<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-677 «Разговор с ИИ голосом» (nj677_talk) — сервис.
 *
 * Задача сервиса — собрать ОДИН ход разговора из трёх чужих умений и не
 * потерять на стыках ни секунды лишнего:
 *
 *   речь → текст   Service_Nj665_Voice::transcribe()   (OmniVoice /audio/transcriptions)
 *   текст → ответ  Llm::chat()                         (фасад платформы)
 *   ответ → речь   Service_Nj665_Voice::synth_bytes()  (OmniVoice /audio/speech)
 *
 * Почему LLM и TTS вызываются прямо в HTTP-запросе, хотя правило платформы
 * требует «долгое — в очередь»: это то же осознанное исключение, что у Алисы
 * (nj657). Человек ЖДЁТ ответа в разговоре; очередь добавляет к каждому ходу
 * круг постановки-выдачи и секунды опроса. Ограничители при этом на месте:
 * короткий ответ (max_tokens), короткая реплика пользователя (turn_chars),
 * лимитер по аккаунту и таймауты клиента OmniVoice. Батчи и длинные тексты
 * сюда заводить нельзя — для них есть /voice и /multivoice.
 *
 * Что здесь чистого (и потому проверяется тестами без базы):
 *   format_reply()    — привести ответ модели к виду «можно читать и слушать»;
 *   clean_speech()    — убрать всё, что нельзя произнести (ссылки, разметка);
 *   split_sentences() — порезать ответ на части озвучки по границам фраз;
 *   trim_history()    — оставить от разговора только то, что влезает в контекст;
 *   build_messages()  — системная роль + история;
 *   audio_ok()        — принять или отклонить запись с микрофона.
 */
class Service_Nj677_Talk
{
    const VERSION = '1.1.0';

    /** Границы одной реплики: ниже — шум, выше — уже не разговор. */
    const MIN_SPEAK_CHARS = 1;

    // ---------------------------------------------------------------- конфиг

    public static function config()
    {
        $config = Kohana::$config->load('nj677_talk');

        return $config instanceof Kohana_Config_Group ? $config->as_array() : (array) $config;
    }

    public static function branch($name, $default = [])
    {
        return (array) Arr::get(self::config(), $name, $default);
    }

    public static function limits($key = NULL, $default = NULL)
    {
        $limits = self::branch('limits');

        return $key === NULL ? $limits : Arr::get($limits, $key, $default);
    }

    public static function rate($key = NULL, $default = NULL)
    {
        $rate = self::branch('rate');

        return $key === NULL ? $rate : Arr::get($rate, $key, $default);
    }

    /** Модель разговора: из конфига модуля, иначе модель платформы. */
    public static function model()
    {
        $want = trim((string) Arr::get(self::config(), 'model', ''));

        if ($want !== '')
        {
            return $want;
        }

        if (class_exists('Kohana') AND Kohana::$config)
        {
            $llm = Kohana::$config->load('llm');

            if ($llm)
            {
                $default = $llm instanceof Kohana_Config_Group
                    ? $llm->get('default_model', '')
                    : Arr::get((array) $llm, 'default_model', '');

                return trim((string) $default);
            }
        }

        return '';
    }

    public static function voice()
    {
        return trim((string) Arr::get(self::config(), 'voice', ''));
    }

    public static function language()
    {
        return trim((string) Arr::get(self::config(), 'language', 'ru'));
    }

    public static function speed()
    {
        $speed = (float) Arr::get(self::config(), 'speed', 1.05);

        return max(0.5, min(2.0, $speed));
    }

    /**
     * Формат озвучки ответа.
     *
     * mp3 экономит трафик, но у части движков он отдаёт мало высоких частот и
     * голос кажется приглушённым. Тогда администратор ставит NJ677_TTS_FORMAT=wav:
     * файлы больше, зато звук без потерь. Незнакомое значение — mp3, чтобы
     * опечатка в .env не выключила озвучку совсем.
     */
    public static function speech_format()
    {
        $format = strtolower(trim((string) Arr::get(self::config(), 'tts_format', 'mp3')));

        return in_array($format, ['mp3', 'wav', 'opus', 'flac', 'aac'], TRUE) ? $format : 'mp3';
    }

    public static function system_prompt()
    {
        $prompt = trim((string) Arr::get(Arr::get(self::config(), 'prompt', []), 'system', ''));

        return $prompt !== '' ? $prompt : 'Отвечай коротко: одно-три предложения, без разметки.';
    }

    // ------------------------------------------------------------- готовность

    /**
     * Готов ли сервис: есть LLM и настроенный клиент OmniVoice.
     *
     * Без этого страница честно объясняет, чего не хватает, вместо того чтобы
     * ловить 401 внешнего API на первом же слове пользователя.
     */
    public static function enabled()
    {
        return self::missing() === '';
    }

    /** Чего не хватает для работы — по-русски, для человека ('' — всё есть). */
    public static function missing()
    {
        if ( ! class_exists('Llm'))
        {
            return 'Модуль LLM не подключён: разговаривать не с кем.';
        }

        if ( ! class_exists('Service_Nj665_Voice'))
        {
            return 'Не подключён модуль озвучивания (nj665) — он же клиент OmniVoice для распознавания и синтеза.';
        }

        if ( ! Service_Nj665_Voice::enabled())
        {
            return 'Не настроен OmniVoice: задайте NJ665_API_URL и NJ665_API_KEY в .env.';
        }

        return '';
    }

    /**
     * Голоса для выбора на странице.
     *
     * Берём каталог клиента OmniVoice; если он недоступен — хотя бы текущий
     * голос из конфига, чтобы разговор не остался без выбора.
     */
    public static function voices()
    {
        if ( ! class_exists('Service_Nj665_Voice'))
        {
            return [];
        }

        $voices = (array) Service_Nj665_Voice::voices();
        $want   = self::voice();

        if ($want !== '' AND ! in_array($want, $voices, TRUE))
        {
            array_unshift($voices, $want);
        }

        return array_values(array_unique(array_map('strval', $voices)));
    }

    /** Голос по умолчанию: конфиг модуля → конфиг nj665 → первый в каталоге. */
    public static function default_voice()
    {
        $want = self::voice();

        if ($want !== '')
        {
            return $want;
        }

        return class_exists('Service_Nj665_Voice')
            ? (string) Service_Nj665_Voice::default_voice()
            : '';
    }

    // ------------------------------------------------------- текст ответа

    /**
     * Привести ответ модели к виду «можно читать и слушать».
     *
     * Модель, даже с просьбой «без разметки», иногда приносит `**жирный**`,
     * заголовки и блоки кода. Разговорный ответ не должен показывать звёздочки:
     * их прочитают вслух как «звёздочка звёздочка». Полностью текст не режем —
     * длинный ответ остаётся видимым (озвучим начало, см. split_sentences).
     */
    public static function format_reply($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        // Блоки кода: убираем только заборы, содержимое оставляем текстом.
        $text = preg_replace('~^```[a-zA-Z0-9_+-]*\s*$~m', '', $text);
        // Заголовки, цитаты, маркеры списков в начале строки.
        $text = preg_replace('~^\s{0,3}#{1,6}\s+~m', '', $text);
        $text = preg_replace('~^\s{0,3}>\s?~m', '', $text);
        $text = preg_replace('~^\s{0,3}[-*+]\s+~m', '', $text);
        // Жирный/курсив/инлайн-код — оставляем сам текст.
        $text = preg_replace('~(\*\*|__|\*|_|`)~u', '', $text);
        // Управляющие символы, кроме перевода строки и табуляции.
        $text = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', $text);
        // Больше двух пустых строк подряд не нужно.
        $text = preg_replace('~\n{3,}~u', "\n\n", $text);
        $text = trim($text);

        return mb_substr($text, 0, 4000);
    }

    /**
     * Текст, который можно произнести.
     *
     * Убираем то, что голосом звучит как мусор: блоки кода (их читают
     * посимвольно), ссылки (длинные и нечитаемые), разметку, смайлы.
     */
    public static function clean_speech($text)
    {
        $text = self::format_reply($text);

        // Блок кода целиком — вслух он не нужен.
        $text = preg_replace('~```.*?```~su', ' ', $text);
        // Ссылки markdown → подпись; голые адреса → пробел.
        $text = preg_replace('~\[([^\]]{1,120})\]\([^)]{0,500}\)~u', '$1', $text);
        $text = preg_replace('~(https?://|www\.)\S+~iu', ' ', $text);
        // Почтовые адреса и «собаки» в слух не идут.
        $text = preg_replace('~\S+@\S+\.\S+~u', ' ', $text);
        // Смайлы и пиктограммы.
        $text = preg_replace('~[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2600}-\x{27BF}\x{FE0F}\x{2B00}-\x{2BFF}]~u', '', $text);
        // Прочие символы разметки.
        $text = str_replace(['*', '_', '#', '`', '>', '|', '~'], ' ', $text);
        // Пробелы и переносы приводим к одной строке: паузы ставит синтез.
        $text = preg_replace('~\s+~u', ' ', $text);

        return trim($text);
    }

    /**
     * Порезать ответ на части озвучки.
     *
     * Зачем части: пока играет первая фраза, остальные уже синтезируются —
     * разговор начинается на секунду-две раньше, чем при озвучке всего ответа
     * одним запросом. Режем по границам предложений, короткие куски склеиваем
     * (иначе синтез «спотыкается» на каждом «Да.»), длинные — по запятым.
     *
     * @param  string $text      ответ модели
     * @param  int    $max_chars символов в одной части (0 — из конфига)
     * @param  int    $max_parts частей не больше (0 — из конфига)
     * @return array  список строк для синтеза
     */
    public static function split_sentences($text, $max_chars = 0, $max_parts = 0)
    {
        $max_chars = $max_chars > 0 ? (int) $max_chars : (int) self::limits('tts_chars', 200);
        $max_parts = $max_parts > 0 ? (int) $max_parts : (int) self::limits('tts_parts', 10);
        $speak_max = (int) self::limits('speak_chars', 1600);

        $text = self::clean_speech($text);

        if ($text === '')
        {
            return [];
        }

        // Дольше слушать, чем готов человек ждать: озвучиваем начало, остальное
        // остаётся в тексте ответа. Обрезка — по границе слова.
        if ($speak_max > 0 AND mb_strlen($text) > $speak_max)
        {
            $cut = mb_substr($text, 0, $speak_max);
            $space = mb_strrpos($cut, ' ');

            $text = $space !== FALSE ? mb_substr($cut, 0, $space) : $cut;
        }

        $raw = preg_split('~(?<=[.!?…])\s+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $parts = [];

        foreach ((array) $raw as $piece)
        {
            $piece = trim((string) $piece);

            if ($piece === '')
            {
                continue;
            }

            // «Да.» и «Понял.» отдельными запросами — лишний круг к TTS.
            if ($parts AND mb_strlen($piece) < 24)
            {
                $parts[count($parts) - 1] .= ' '.$piece;
                continue;
            }

            if ($parts AND mb_strlen($parts[count($parts) - 1]) < 24)
            {
                $parts[count($parts) - 1] .= ' '.$piece;
                continue;
            }

            $parts[] = $piece;
        }

        $out = [];

        foreach ($parts as $part)
        {
            foreach (self::chunk($part, $max_chars) as $chunk)
            {
                $out[] = $chunk;
            }
        }

        if (count($out) > $max_parts)
        {
            $out = array_slice($out, 0, $max_parts);
        }

        return array_values(array_filter($out, function ($part) { return trim((string) $part) !== ''; }));
    }

    /**
     * Порезать слишком длинное предложение по запятым, затем по пробелам.
     * Одна часть никогда не длиннее $max_chars (ограничение синтеза и задержки).
     */
    protected static function chunk($text, $max_chars)
    {
        $text = trim((string) $text);

        if ($text === '')
        {
            return [];
        }

        if (mb_strlen($text) <= $max_chars)
        {
            return [$text];
        }

        $out = [];
        $rest = $text;

        while ($rest !== '' AND mb_strlen($rest) > $max_chars)
        {
            $head = mb_substr($rest, 0, $max_chars);
            $cut = max(
                (int) mb_strrpos($head, ', '),
                (int) mb_strrpos($head, '; '),
                (int) mb_strrpos($head, ' — '),
                (int) mb_strrpos($head, ' ')
            );

            if ($cut < (int) ($max_chars / 3))
            {
                $cut = mb_strlen($head);
            }

            $piece = trim(mb_substr($rest, 0, $cut));

            if ($piece !== '')
            {
                $out[] = rtrim($piece, ',;:').'.';
            }

            $rest = trim(mb_substr($rest, $cut));
        }

        if ($rest !== '')
        {
            $out[] = $rest;
        }

        return $out;
    }

    // ------------------------------------------------------------- контекст

    /**
     * Оставить от разговора только то, что влезает в контекст.
     *
     * Берём с конца (свежее важнее), пока не выбрали лимит реплик и знаков.
     * Роли нормализуем: в API модели допустимы только system/user/assistant.
     *
     * @param  array $rows [['role'=>'user'|'assistant','text'=>'…'], …] по порядку
     * @return array [['role'=>'…','content'=>'…'], …] по порядку
     */
    public static function trim_history(array $rows, $turns = 0, $chars = 0)
    {
        $turns = $turns > 0 ? (int) $turns : (int) self::limits('history_turns', 12);
        $chars = $chars > 0 ? (int) $chars : (int) self::limits('history_chars', 6000);

        $out = [];
        $used = 0;

        foreach (array_reverse($rows) as $row)
        {
            $text = trim((string) (is_array($row) ? Arr::get($row, 'text', '') : $row));

            if ($text === '')
            {
                continue;
            }

            if (count($out) >= $turns)
            {
                break;
            }

            $len = mb_strlen($text);

            // Хотя бы одна реплика должна остаться, даже если она длинная.
            if ($out AND $used + $len > $chars)
            {
                break;
            }

            $role = (string) (is_array($row) ? Arr::get($row, 'role', 'user') : 'user');
            $role = in_array($role, ['user', 'assistant'], TRUE) ? $role : 'user';

            array_unshift($out, ['role' => $role, 'content' => $text]);
            $used += $len;
        }

        return $out;
    }

    /** Сообщения для Llm::chat: системная роль + история (уже обрезанная). */
    public static function build_messages(array $history, $system = '')
    {
        $system = trim((string) $system) !== '' ? (string) $system : self::system_prompt();

        $messages = [['role' => 'system', 'content' => $system]];

        foreach ($history as $row)
        {
            $role = (string) Arr::get($row, 'role', 'user');

            if ( ! in_array($role, ['user', 'assistant'], TRUE))
            {
                continue;
            }

            $content = trim((string) Arr::get($row, 'content', ''));

            if ($content !== '')
            {
                $messages[] = ['role' => $role, 'content' => $content];
            }
        }

        return $messages;
    }

    // --------------------------------------------------------- вход и лимиты

    /**
     * Принять запись с микрофона или объяснить отказ.
     *
     * @param  int    $bytes     размер аудио
     * @param  string $mime      заявленный MIME
     * @param  int    $max_kb    предел из конфига
     * @return array  ['ok'=>bool,'error'=>'…','ext'=>'webm']
     */
    public static function audio_ok($bytes, $mime = '', $max_kb = 0)
    {
        $max_kb  = $max_kb > 0 ? (int) $max_kb : (int) self::limits('audio_kb', 4096);
        $bytes   = (int) $bytes;
        $mime    = strtolower(trim((string) $mime));
        $allowed = [
            'audio/webm'       => 'webm',
            'audio/ogg'        => 'ogg',
            'audio/mp4'        => 'm4a',
            'audio/mpeg'       => 'mp3',
            'audio/wav'        => 'wav',
            'audio/x-wav'      => 'wav',
            'application/octet-stream' => 'webm',
        ];

        if ($bytes <= 0)
        {
            return ['ok' => FALSE, 'error' => 'Пустая запись — микрофон не передал звук.', 'ext' => ''];
        }

        if ($bytes > $max_kb * 1024)
        {
            return ['ok' => FALSE, 'error' => 'Запись больше '.$max_kb.' КБ. Говорите короче — это разговор, а не лекция.', 'ext' => ''];
        }

        // 1 КБ за 0,5 с — уже не речь, а обрывок; короче 0,2 с обычно шум.
        if ($bytes < 1024)
        {
            return ['ok' => FALSE, 'error' => 'Слишком короткая запись — не разобрать.', 'ext' => ''];
        }

        $ext = Arr::get($allowed, $mime, '');

        if ($ext === '')
        {
            // Незнакомый MIME не повод отказывать: расширение подскажем по
            // первым байтам (браузеры называют контейнер по-разному).
            $ext = 'webm';
        }

        return ['ok' => TRUE, 'error' => '', 'ext' => $ext];
    }

    /**
     * Похоже ли распознанное на настоящую речь, а не на выдумку распознавателя.
     *
     * Почему это вообще нужно. Модели семейства Whisper на тишине, шуме и
     * обрывках не отдают пустую строку — они «дописывают» то, на чём учились:
     * титры обучающих видео. Самая известная выдумка —
     * «Редактор субтитров А.Семкин Корректор А.Егорова»: человек молчит,
     * а в разговоре появляется реплика, которой он не говорил. Ровно это и
     * случилось на живом стенде (замечание владельца 26.10.2026).
     *
     * Бороться нужно в двух местах: не отправлять тишину (это делает страница)
     * и не пускать известные артефакты в разговор (это здесь). Правила узкие
     * намеренно: «Спасибо за просмотр презентации, теперь к бюджету» — живая
     * речь, и её резать нельзя. Поэтому титры ловятся по форме записи
     * (инициалы после «Редактор субтитров»/«Корректор»), а не по одному слову.
     *
     * @param  string $text распознанный текст
     * @return array  ['ok'=>bool, 'why'=>'…'] — why заполняется только у отказа
     */
    public static function plausible_speech($text)
    {
        $text = trim((string) $text);

        if ($text === '')
        {
            return ['ok' => FALSE, 'why' => 'пусто'];
        }

        // Ни одной буквы или цифры — это шум, а не речь («...», «— — —»).
        if ( ! preg_match('~[\p{L}\p{N}]~u', $text))
        {
            return ['ok' => FALSE, 'why' => 'нет слов'];
        }

        // Всё сказанное — только пометки в скобках: «[музыка]», «(шум)», «*вздох*».
        // Распознаватель так описывает не речь, а звук на фоне.
        $outside = preg_replace('~\[[^\]]*\]|\([^)]*\)|\*[^*]+\*~u', ' ', $text);

        if ( ! preg_match('~[\p{L}\p{N}]~u', (string) $outside))
        {
            return ['ok' => FALSE, 'why' => 'только пометки о звуке'];
        }

        $norm = mb_strtolower(str_replace('ё', 'е', $text));
        $norm = trim(preg_replace('~\s+~u', ' ', preg_replace('~[^\p{L}\p{N} ]+~u', ' ', $norm)));

        if ($norm === '')
        {
            return ['ok' => FALSE, 'why' => 'нет слов'];
        }

        $len = mb_strlen($norm);

        // Титры обучающих видео: «Редактор субтитров А.Семкин Корректор А.Егорова».
        // Ловим форму с инициалами — фраза «редактор субтитров сломался» пройдёт.
        if (preg_match('~Редактор\s+субтитров\s+[А-ЯЁA-Z]\.\s*[А-ЯЁA-Z][а-яёa-z]+~u', $text)
            OR preg_match('~Корректор\s+[А-ЯЁA-Z]\.\s*[А-ЯЁA-Z][а-яёa-z]+~u', $text))
        {
            return ['ok' => FALSE, 'why' => 'титры вместо речи'];
        }

        // Прочие «подписи» распознавателя: они появляются на тишине пачками.
        foreach ([
            '~Субтитры\s+(сделал|создавал|подготовил)~ui',
            '~Amara\.org~i',
            '~DimaTorzok~i',
            '~rev\.com~i',
            '~otter\.ai~i',
        ] as $rx)
        {
            if (preg_match($rx, $text))
            {
                return ['ok' => FALSE, 'why' => 'подпись распознавателя'];
            }
        }

        // Короткие «концовки», которые распознаватель дописывает на тишине.
        // Проверяем именно целую реплику: продолжение фразы — уже речь.
        foreach ([
            'продолжение следует'        => 30,
            'спасибо за просмотр'        => 25,
            'подпишись на канал'         => 40,
            'ставьте лайки'              => 40,
            'до новых встреч'            => 30,
            'thanks for watching'        => 40,
            'thank you for watching'     => 40,
            'subtitles by'               => 40,
        ] as $phrase => $max_len)
        {
            if ($len <= $max_len AND mb_strpos($norm, $phrase) === 0)
            {
                return ['ok' => FALSE, 'why' => 'концовка распознавателя'];
            }
        }

        // Зацикливание: «да да да да» или три одинаковых предложения подряд —
        // типичный срыв распознавания, а не речь.
        $words = preg_split('~\s+~u', $norm, -1, PREG_SPLIT_NO_EMPTY);

        if (count($words) >= 6 AND count(array_unique($words)) === 1)
        {
            return ['ok' => FALSE, 'why' => 'зацикливание'];
        }

        $sentences = preg_split('~(?<=[.!?…])\s+~u', $norm, -1, PREG_SPLIT_NO_EMPTY);

        if (count($sentences) >= 3 AND count(array_unique($sentences)) <= 2)
        {
            return ['ok' => FALSE, 'why' => 'зацикливание'];
        }

        return ['ok' => TRUE, 'why' => ''];
    }

    /**
     * Принять реплику пользователя или отказать: текст + сколько реально
     * звучал голос (страница считает это по громкости до отправки).
     *
     * Порог по длине голоса отсекает то, что нельзя распознать в принципе:
     * щелчок, кашель, хлопок двери. Но если страница длительность не прислала
     * (старый клиент, ручной POST), проверка не применяется — иначе честная
     * короткая реплика «да» пропала бы.
     *
     * @return array ['ok'=>bool, 'text'=>'…', 'why'=>'…']
     */
    public static function accept_speech($text, $voiced_ms = 0)
    {
        $text = trim(preg_replace('~\s+~u', ' ', (string) $text));
        $voiced_ms = (int) $voiced_ms;

        if ($text === '')
        {
            return ['ok' => FALSE, 'text' => '', 'why' => 'Пустая запись — нечего распознавать.'];
        }

        if ($voiced_ms > 0 AND $voiced_ms < 300)
        {
            return [
                'ok'   => FALSE,
                'text' => '',
                'why'  => 'Слишком коротко: похоже на шум, а не на слова. Скажите ещё раз.',
            ];
        }

        $check = self::plausible_speech($text);

        if ( ! $check['ok'])
        {
            return [
                'ok'   => FALSE,
                'text' => $text,
                'why'  => 'Не расслышал — похоже, это шум, а не речь. Скажите ещё раз.',
            ];
        }

        return ['ok' => TRUE, 'text' => $text, 'why' => ''];
    }

    /** Не превышен ли лимит ходов: считается по репликам пользователя в БД. */
    public static function rate_ok($user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return FALSE;
        }

        foreach ([['minute', 'per_minute', 20], ['hour', 'per_hour', 240]] as $rule)
        {
            list($unit, $key, $default) = $rule;
            $limit = (int) self::rate($key, $default);

            if ($limit <= 0)
            {
                continue;
            }

            $row = self::_one('SELECT count(*) AS n
                FROM nj_677_messages m
                JOIN nj_677_talks t ON t.id = m.talk_id
                WHERE t.user_id = '.(int) $user_id.'
                  AND m.role = \'user\'
                  AND m.created_at > now() - interval \'1 '.$unit.'\'');

            if ($row AND (int) $row['n'] >= $limit)
            {
                return FALSE;
            }
        }

        return TRUE;
    }

    public static function rate_text()
    {
        $minute = (int) self::rate('per_minute', 20);
        $hour   = (int) self::rate('per_hour', 240);

        $parts = [];

        if ($minute > 0)
        {
            $parts[] = $minute.' в минуту';
        }

        if ($hour > 0)
        {
            $parts[] = $hour.' в час';
        }

        return $parts ? implode(' и ', $parts) : 'без ограничений';
    }

    // ------------------------------------------------------------ хранилище

    public static function code_new($len = 12)
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $len; $i++)
        {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /** Разговор по коду — только владельцу (чужой код ничего не открывает). */
    public static function talk($code, $user_id)
    {
        $code = preg_replace('~[^a-z0-9]~', '', strtolower((string) $code));

        if ($code === '')
        {
            return NULL;
        }

        $row = self::_one('SELECT * FROM nj_677_talks
            WHERE code = '.self::_q($code).'
              AND user_id = '.(int) $user_id.'
            LIMIT 1');

        return $row ?: NULL;
    }

    /**
     * Найти разговор по коду или начать новый.
     *
     * Кода нет (первый ход или «Новый разговор») — строка создаётся здесь:
     * пользователь не должен нажимать «создать», он просто говорит.
     */
    public static function ensure($code, $user_id)
    {
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return NULL;
        }

        $found = self::talk($code, $user_id);

        if ($found)
        {
            return $found;
        }

        for ($try = 0; $try < 5; $try++)
        {
            $new = self::code_new();

            $row = self::_one('INSERT INTO nj_677_talks (code, user_id, model, voice)
                VALUES ('.self::_q($new).', '.(int) $user_id.', '
                .self::_q(self::model()).', '.self::_q(self::default_voice()).')
                ON CONFLICT (code) DO NOTHING
                RETURNING *');

            if ($row)
            {
                return $row;
            }
        }

        return NULL;
    }

    /** Реплики разговора по порядку (старые первыми). */
    public static function history($talk_id, $limit = 0)
    {
        $limit = $limit > 0 ? (int) $limit : 100;

        return self::_all('SELECT role, text, ms, created_at
            FROM nj_677_messages
            WHERE talk_id = '.(int) $talk_id.'
            ORDER BY id ASC
            LIMIT '.$limit);
    }

    /** Последние разговоры пользователя: вернуться к теме одним нажатием. */
    public static function recent($user_id, $limit = 0)
    {
        $limit = $limit > 0 ? (int) $limit : (int) Arr::get(self::config(), 'recent_limit', 5);
        $user_id = (int) $user_id;

        if ($user_id <= 0)
        {
            return [];
        }

        return self::_all('SELECT code, title, turns, voice, model, updated_at
            FROM nj_677_talks
            WHERE user_id = '.(int) $user_id.' AND turns > 0
            ORDER BY updated_at DESC
            LIMIT '.max(1, $limit));
    }

    /** Добавить реплику; ms — время своего этапа (распознавание или модель). */
    public static function append($talk_id, $role, $text, $ms = 0)
    {
        $role = $role === 'assistant' ? 'assistant' : 'user';
        $text = str_replace("\0", '', (string) $text);

        $row = self::_one('INSERT INTO nj_677_messages (talk_id, role, text, chars, ms)
            VALUES ('.(int) $talk_id.', '.self::_q($role).', '.self::_q($text).', '
            .mb_strlen($text).', '.(int) $ms.')
            RETURNING id');

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * Один ход разговора: реплика пользователя → ответ модели.
     *
     * Распознавание речи уже сделано (см. Controller_Nj677_Api::action_hear),
     * потому что страница показывает текст пользователя сразу, не дожидаясь
     * модели: так разговор не выглядит зависшим.
     *
     * @param  int    $user_id владелец разговора
     * @param  string $code    код разговора ('' — новый)
     * @param  string $text    что сказал (или написал) пользователь
     * @param  int    $stt_ms  сколько заняло распознавание (0 — писали текстом)
     * @return array  ok, error, talk (код), reply, parts, model, llm_ms
     */
    public static function turn($user_id, $code, $text, $stt_ms = 0)
    {
        $out = [
            'ok'     => FALSE,
            'error'  => '',
            'talk'   => '',
            'reply'  => '',
            'parts'  => [],
            'model'  => self::model(),
            'llm_ms' => 0,
        ];

        $text = trim(preg_replace('~\s+~u', ' ', str_replace("\0", '', (string) $text)));

        if (mb_strlen($text) < self::MIN_SPEAK_CHARS)
        {
            $out['error'] = 'Пустая реплика: нечего отправлять модели.';

            return $out;
        }

        $max = (int) self::limits('turn_chars', 2000);

        if (mb_strlen($text) > $max)
        {
            $text = mb_substr($text, 0, $max);
        }

        if ( ! self::enabled())
        {
            $out['error'] = self::missing();

            return $out;
        }

        if ( ! self::rate_ok($user_id))
        {
            $out['error'] = 'Слишком часто. Лимит разговора: '.self::rate_text().'.';
            self::_log(Log::WARNING, 'лимит ходов исчерпан: user=:uid', [':uid' => (int) $user_id]);

            return $out;
        }

        $talk = self::ensure($code, $user_id);

        if ( ! $talk)
        {
            $out['error'] = 'Не удалось открыть разговор. Обновите страницу и попробуйте снова.';
            self::_log(Log::ERROR, 'разговор не создан: user=:uid', [':uid' => (int) $user_id]);

            return $out;
        }

        $talk_id = (int) $talk['id'];
        $out['talk'] = (string) $talk['code'];

        self::append($talk_id, 'user', $text, (int) $stt_ms);

        // Контекст собираем из базы, а не из браузера: страницу можно
        // перезагрузить, история от этого не должна «забываться».
        $rows = self::history($talk_id, (int) self::limits('history_turns', 12) * 2 + 2);
        $messages = self::build_messages(self::trim_history($rows), self::system_prompt());

        $options = [
            'max_tokens'  => max(32, (int) self::limits('max_tokens', 220)),
            'temperature' => (float) self::limits('temperature', 0.6),
        ];

        $model = self::model();

        if ($model !== '')
        {
            $options['model'] = $model;
        }

        $start = microtime(TRUE);

        try
        {
            $response = Llm::chat($messages, $options);
            $reply = (string) ($response['choices'][0]['message']['content'] ?? '');
        }
        catch (Throwable $e)
        {
            $out['error'] = 'Модель не ответила. Попробуйте ещё раз.';
            self::_log(Log::ERROR, 'LLM не ответила: :error', [
                ':error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300),
            ]);

            return $out;
        }

        $out['llm_ms'] = (int) round((microtime(TRUE) - $start) * 1000);
        $reply = self::format_reply($reply);

        if ($reply === '')
        {
            $out['error'] = 'Модель ответила пусто. Скажите иначе — попробую снова.';
            self::_log(Log::WARNING, 'пустой ответ модели: talk=:talk', [':talk' => $out['talk']]);

            return $out;
        }

        self::append($talk_id, 'assistant', $reply, $out['llm_ms']);

        // Заголовок разговора — первая реплика пользователя: по нему потом
        // узнаёшь тему в списке последних, не открывая разговор.
        $title = trim((string) Arr::get($talk, 'title', ''));

        self::_exec('UPDATE nj_677_talks SET
                turns = turns + 1,
                updated_at = now(),
                title = '.($title === '' ? self::_q(self::digest($text)) : 'title').',
                model = '.self::_q($out['model']).',
                voice = '.self::_q(self::default_voice()).'
            WHERE id = '.(int) $talk_id);

        $out['ok']    = TRUE;
        $out['reply'] = $reply;
        $out['parts'] = self::split_sentences($reply);

        return $out;
    }

    /** Короткая тема разговора из первой реплики. */
    public static function digest($text, $len = 80)
    {
        $text = trim(preg_replace('~\s+~u', ' ', (string) $text));

        if (mb_strlen($text) <= $len)
        {
            return $text;
        }

        $cut = mb_substr($text, 0, $len);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== FALSE ? mb_substr($cut, 0, $space) : $cut, " ,.;:—-").'…';
    }

    // ------------------------------------------------------------ внутреннее

    /**
     * Записать отказ в общий журнал (правило платформы: причина отказа видна в
     * логе, а не только в подсказке человеку).
     *
     * Контроллер зовёт это, когда шаг не удался: распознавание, синтез, лимит.
     * Сообщение — по-русски и с причиной («нет ключа», «502», «лимит»), иначе
     * разбор инцидента начинается с угадывания.
     */
    public static function log_event($message, array $params = [], $level = NULL)
    {
        self::_log($level === NULL ? Log::WARNING : $level, (string) $message, $params);
    }

    protected static function _log($level, $message, array $params = [])
    {
        if (class_exists('Log_Plain'))
        {
            Log_Plain::event($level, (string) $message, $params);

            return;
        }

        Kohana::$log->add($level, (string) $message, $params);
    }

    protected static function _db()
    {
        return Database::instance();
    }

    protected static function _q($value)
    {
        return self::_db()->quote((string) $value);
    }

    protected static function _one($sql)
    {
        $row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    protected static function _all($sql)
    {
        $rows = self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();

        return $rows ? array_map(function ($row) { return (array) $row; }, $rows) : [];
    }

    protected static function _exec($sql)
    {
        return self::_db()->query(Database::INSERT, $sql, FALSE);
    }
}
