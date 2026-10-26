<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj677_Talk (nj677_talk — разговор с ИИ голосом).
 *
 * Запуск: php tests/mini_runner.php Nj677Talk
 *
 * Проверяются только чистые функции: разговор в тесте не завести — нужен
 * микрофон, модель и внешний синтез. Но именно чистые функции решают, будет
 * разговор удобным:
 *
 *   - ответ приводится к читаемому виду и не показывает `**звёздочки**`;
 *   - озвучка не произносит ссылки, разметку и смайлы;
 *   - ответ режется на части по границам фраз — от этого зависит, насколько
 *     рано человек услышит первую фразу;
 *   - контекст обрезается с конца (свежее важнее) и не путает роли;
 *   - запись с микрофона принимается или отклоняется по размеру и типу.
 *
 * Те же проверки есть в стенде `.tools/nj677_talk_stand.php` — он работает без
 * PHP в системе и без PostgreSQL.
 */
class Nj677TalkTest extends TestCase
{
    // --- конфиг -----------------------------------------------------------

    public function test_limits_come_from_config()
    {
        $this->assertSame(200, (int) Service_Nj677_Talk::limits('tts_chars', 0));
        $this->assertSame(10, (int) Service_Nj677_Talk::limits('tts_parts', 0));
        $this->assertSame(2000, (int) Service_Nj677_Talk::limits('turn_chars', 0));
    }

    public function test_rate_text_is_human_readable()
    {
        $this->assertStringContainsString('в минуту', Service_Nj677_Talk::rate_text());
    }

    public function test_speech_format_falls_back_to_mp3()
    {
        // Незнакомое значение в .env не должно выключать озвучку.
        $this->assertContains(Service_Nj677_Talk::speech_format(), ['mp3', 'wav', 'opus', 'flac', 'aac']);
    }

    public function test_speed_is_clamped()
    {
        $this->assertGreaterThanOrEqual(0.5, Service_Nj677_Talk::speed());
        $this->assertLessThanOrEqual(2.0, Service_Nj677_Talk::speed());
    }

    // --- ответ модели -----------------------------------------------------

    public function test_format_reply_strips_markdown()
    {
        $this->assertSame(
            "Заголовок\nпункт\nважно",
            Service_Nj677_Talk::format_reply("## Заголовок\n- пункт\n**важно**")
        );
    }

    public function test_format_reply_collapses_blank_lines()
    {
        $this->assertSame("а\n\nб", Service_Nj677_Talk::format_reply("а\n\n\n\nб"));
    }

    public function test_format_reply_drops_control_chars()
    {
        $this->assertSame('аб', Service_Nj677_Talk::format_reply("а\x01б"));
    }

    public function test_format_reply_caps_length()
    {
        $this->assertSame(4000, mb_strlen(Service_Nj677_Talk::format_reply(str_repeat('я', 5000))));
    }

    // --- текст для озвучки ------------------------------------------------

    public function test_clean_speech_keeps_link_caption()
    {
        $this->assertSame(
            'Смотри документ рядом',
            Service_Nj677_Talk::clean_speech('Смотри [документ](https://example.ru/a?b=1) рядом')
        );
    }

    public function test_clean_speech_drops_bare_url()
    {
        $this->assertSame('Открыл и всё', Service_Nj677_Talk::clean_speech('Открыл https://example.ru/page и всё'));
    }

    public function test_clean_speech_drops_emoji()
    {
        $this->assertSame('Готово', Service_Nj677_Talk::clean_speech('Готово 🙂'));
    }

    // --- части озвучки ----------------------------------------------------

    public function test_empty_reply_has_no_parts()
    {
        $this->assertSame([], Service_Nj677_Talk::split_sentences(''));
    }

    public function test_short_sentences_are_glued()
    {
        // «Да.» и «Понял.» отдельными запросами к синтезу — лишний круг.
        $this->assertSame(
            ['Да. Понял. Это длинное предложение из нескольких слов, которое стоит разбить.'],
            Service_Nj677_Talk::split_sentences('Да. Понял. Это длинное предложение из нескольких слов, которое стоит разбить.')
        );
    }

    public function test_long_text_is_split_by_chunk_limit()
    {
        $parts = Service_Nj677_Talk::split_sentences(str_repeat('слово ', 60));

        $this->assertGreaterThanOrEqual(2, count($parts));
        $this->assertLessThanOrEqual(200, max(array_map('mb_strlen', $parts)));
    }

    public function test_parts_limit_is_respected()
    {
        $parts = Service_Nj677_Talk::split_sentences(
            'Первое предложение достаточно длинное, чтобы остаться отдельным. '
            .'Второе предложение тоже достаточно длинное для отдельной части. '
            .'Третье предложение такое же длинное, как и предыдущие два.',
            200,
            2
        );

        $this->assertCount(2, $parts);
    }

    public function test_speech_is_not_read_forever()
    {
        // Длинный ответ остаётся текстом на экране: вслух — только начало.
        $parts = Service_Nj677_Talk::split_sentences(str_repeat('слово ', 800));

        $this->assertLessThanOrEqual(10, count($parts));
    }

    // --- контекст ---------------------------------------------------------

    public function test_history_keeps_last_turns_in_order()
    {
        $rows = [
            ['role' => 'user', 'text' => 'первый'],
            ['role' => 'assistant', 'text' => 'второй'],
            ['role' => 'user', 'text' => 'третий'],
        ];

        $this->assertSame(
            [
                ['role' => 'assistant', 'content' => 'второй'],
                ['role' => 'user', 'content' => 'третий'],
            ],
            Service_Nj677_Talk::trim_history($rows, 2)
        );
    }

    public function test_history_keeps_at_least_one_turn_under_char_limit()
    {
        $rows = [
            ['role' => 'user', 'text' => 'первый'],
            ['role' => 'assistant', 'text' => 'второй'],
            ['role' => 'user', 'text' => 'третий'],
        ];

        $this->assertSame(
            [['role' => 'user', 'content' => 'третий']],
            Service_Nj677_Talk::trim_history($rows, 5, 5)
        );
    }

    public function test_unknown_role_becomes_user()
    {
        $this->assertSame(
            [['role' => 'user', 'content' => 'правила']],
            Service_Nj677_Talk::trim_history([['role' => 'system', 'text' => 'правила']], 5)
        );
    }

    public function test_build_messages_puts_system_first()
    {
        $this->assertSame(
            [
                ['role' => 'system', 'content' => 'отвечай коротко'],
                ['role' => 'user', 'content' => 'привет'],
                ['role' => 'assistant', 'content' => 'здравствуйте'],
            ],
            Service_Nj677_Talk::build_messages([
                ['role' => 'system', 'content' => 'вредное'],
                ['role' => 'user', 'content' => 'привет'],
                ['role' => 'assistant', 'content' => 'здравствуйте'],
            ], 'отвечай коротко')
        );
    }

    public function test_default_system_prompt_asks_for_short_answer()
    {
        $messages = Service_Nj677_Talk::build_messages([]);

        $this->assertSame('system', $messages[0]['role']);
        $this->assertStringContainsString('коротко', $messages[0]['content']);
    }

    // --- запись с микрофона -----------------------------------------------

    public function test_empty_recording_is_rejected()
    {
        $r = Service_Nj677_Talk::audio_ok(0);

        $this->assertFalse($r['ok']);
        $this->assertNotSame('', $r['error']);
    }

    public function test_too_short_recording_is_rejected()
    {
        $this->assertFalse(Service_Nj677_Talk::audio_ok(500)['ok']);
    }

    public function test_too_large_recording_is_rejected()
    {
        $this->assertFalse(Service_Nj677_Talk::audio_ok(5 * 1024 * 1024)['ok']);
    }

    public function test_browser_mime_with_codecs_is_accepted()
    {
        // MediaRecorder присылает «audio/webm;codecs=opus» — кодек не повод отказать.
        $r = Service_Nj677_Talk::audio_ok(20000, 'audio/webm;codecs=opus');

        $this->assertTrue($r['ok']);
        $this->assertSame('webm', $r['ext']);
    }

    public function test_safari_recording_gets_m4a_extension()
    {
        $this->assertSame('m4a', Service_Nj677_Talk::audio_ok(20000, 'audio/mp4')['ext']);
    }

    public function test_unknown_mime_does_not_reject_recording()
    {
        $this->assertTrue(Service_Nj677_Talk::audio_ok(20000, 'application/x-unknown')['ok']);
    }

    // --- выдумки распознавателя на тишине ---------------------------------

    public function test_subtitle_credit_hallucination_is_rejected()
    {
        // Реальный случай: человек молчал, а распознаватель выдал титры
        // обучающего видео. Именно эта строка пришла на живой стенд 26.10.2026.
        $this->assertFalse(
            Service_Nj677_Talk::plausible_speech('Редактор субтитров А.Семкин Корректор А.Егорова')['ok']
        );
    }

    public function test_other_silence_artefacts_are_rejected()
    {
        foreach ([
            'Продолжение следует...',
            'Спасибо за просмотр',
            'Субтитры сделал DimaTorzok',
            '[музыка]',
            'да да да да да да',
            '...',
        ] as $sample)
        {
            $this->assertFalse(Service_Nj677_Talk::plausible_speech($sample)['ok'], $sample);
        }
    }

    public function test_live_speech_with_similar_words_is_kept()
    {
        // Узкие правила: живая фраза, в которой встретились те же слова, — речь.
        foreach ([
            'Спасибо за просмотр презентации, теперь к бюджету',
            'Редактор субтитров сломался, покажи другой файл',
            'Продолжение следует из договора, пункт третий',
            'Да',
        ] as $sample)
        {
            $this->assertTrue(Service_Nj677_Talk::plausible_speech($sample)['ok'], $sample);
        }
    }

    public function test_too_short_voice_is_rejected()
    {
        $this->assertFalse(Service_Nj677_Talk::accept_speech('привет', 230)['ok']);
        $this->assertTrue(Service_Nj677_Talk::accept_speech('привет', 600)['ok']);
    }

    public function test_unknown_voice_duration_is_not_punished()
    {
        // Длительность присылает страница; ручной POST без неё — не повод отказать.
        $this->assertTrue(Service_Nj677_Talk::accept_speech('привет', 0)['ok']);
    }

    public function test_hallucination_is_rejected_even_with_long_voice()
    {
        $this->assertFalse(Service_Nj677_Talk::accept_speech('Продолжение следует...', 900)['ok']);
    }

    // --- тема разговора ---------------------------------------------------

    public function test_digest_keeps_short_title()
    {
        $this->assertSame('Как настроить VPN', Service_Nj677_Talk::digest('Как настроить VPN'));
    }

    public function test_digest_cuts_long_title_at_word_boundary()
    {
        $title = Service_Nj677_Talk::digest(str_repeat('слово ', 40));

        $this->assertLessThanOrEqual(83, mb_strlen($title));
        $this->assertStringEndsWith('…', $title);
    }
}
