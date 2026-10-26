<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj653_Veclab (nj653_veclab — лаборатория векторов).
 *
 * Проверяем то, что видит человек и чего не видит база: словесную оценку
 * близости (пороги легко «поплывут» при правке конфига), разбор ответа
 * воркера, защиту превью от чужого data-URL и итог самопроверки модели —
 * особенно случай «модель считает векторы, но текст с картинкой не
 * связывает»: именно ради него модуль и написан.
 *
 * Запуск: php tests/mini_runner.php VeclabService
 */
class VeclabServiceTest extends TestCase {

    // ----------------------------------------------------- оценка близости

    public function test_similarity_uses_configured_scale()
    {
        $top = Service_Nj653_Veclab::similarity(0.97);
        $mid = Service_Nj653_Veclab::similarity(0.55);
        $low = Service_Nj653_Veclab::similarity(0.02);

        $this->assertSame('практически одно и то же', $top['title'],
            'Почти единица — это совпадение');
        $this->assertSame('emerald', $top['tone']);
        $this->assertSame('заметно связаны', $mid['title'],
            'Середина шкалы не должна выглядеть как совпадение');
        $this->assertSame('ничего общего', $low['title'],
            'Около нуля у этих моделей — чужие смыслы');
        $this->assertSame('gray', $low['tone']);
    }

    public function test_similarity_clamps_and_rounds()
    {
        $over = Service_Nj653_Veclab::similarity(7.5);
        $under = Service_Nj653_Veclab::similarity(-9.0);

        $this->assertSame(1.0, $over['value'], 'Косинус больше единицы — это мусор, режем');
        $this->assertSame(-1.0, $under['value']);
        $this->assertSame(0.1235, Service_Nj653_Veclab::similarity(0.123456)['value'],
            'Человеку хватает четырёх знаков');
    }

    public function test_scale_is_ordered_from_high_to_low()
    {
        $previous = 2.0;

        foreach (Service_Nj653_Veclab::scale() as $step)
        {
            $this->assertTrue((float) $step['from'] < $previous,
                'Пороги шкалы обязаны убывать, иначе выбирается не та оценка');
            $previous = (float) $step['from'];
        }

        $this->assertSame(-1.0, (float) $previous,
            'Последний порог обязан ловить любой косинус');
    }

    // -------------------------------------------------------------- косинус

    public function test_cosine_of_same_direction_is_one()
    {
        $this->assertSame(1.0, round(Service_Nj653_Veclab::cosine([1, 2, 3], [2, 4, 6]), 6),
            'Длина вектора на косинус не влияет');
    }

    public function test_cosine_of_orthogonal_is_zero()
    {
        $this->assertSame(0.0, round(Service_Nj653_Veclab::cosine([1, 0], [0, 1]), 6));
    }

    public function test_cosine_refuses_broken_input()
    {
        $this->assertSame(0.0, Service_Nj653_Veclab::cosine([], []),
            'Пустые векторы — не повод падать');
        $this->assertSame(0.0, Service_Nj653_Veclab::cosine([1, 2, 3], [1, 2]),
            'Разная размерность — это ошибка данных, а не «немного похоже»');
        $this->assertSame(0.0, Service_Nj653_Veclab::cosine([0, 0], [1, 1]),
            'Нулевой вектор направления не имеет');
    }

    // --------------------------------------------------- разбор результата

    public function test_shape_of_search_turns_distance_into_score()
    {
        $data = Service_Nj653_Veclab::shape([
            'mode'  => 'search',
            'model' => 'qwen3-vl-embedding-2b',
            'dims'  => 2048,
            'hits'  => [
                ['id' => 7, 'label' => 'Кот', 'kind' => 'image', 'distance' => 0.2],
                ['id' => 8, 'label' => 'Декларация', 'kind' => 'text', 'distance' => 0.95],
            ],
        ]);

        $this->assertSame('search', $data['mode']);
        $this->assertSame('Поиск по смыслу', $data['mode_label']);
        $this->assertCount(2, $data['hits']);
        $this->assertSame(0.8, $data['hits'][0]['score']['value'],
            'Близость — это единица минус дистанция');
        $this->assertSame('про одно и то же', $data['hits'][0]['score']['title']);
        $this->assertSame('ничего общего', $data['hits'][1]['score']['title']);
    }

    public function test_shape_of_compare_keeps_cosine()
    {
        $data = Service_Nj653_Veclab::shape(['mode' => 'compare', 'cosine' => 0.72,
            'kinds' => ['text', 'image']]);

        $this->assertSame(0.72, $data['pair']['value']);
        $this->assertSame('про одно и то же', $data['pair']['title']);
        $this->assertSame(['text', 'image'], $data['kinds']);
    }

    public function test_shape_of_unknown_mode_falls_back_to_probe()
    {
        $data = Service_Nj653_Veclab::shape(['mode' => 'шутка']);

        $this->assertSame('probe', $data['mode'],
            'Неизвестный режим не должен ронять страницу');
        $this->assertSame('не определён', $data['dialect_title']);
    }

    public function test_shape_names_known_dialect()
    {
        $data = Service_Nj653_Veclab::shape(['mode' => 'probe', 'dialect' => 'oai_content']);

        $this->assertStringContainsString('llama.cpp', $data['dialect_title']);

        $other = Service_Nj653_Veclab::shape(['mode' => 'probe', 'dialect' => 'новый_формат']);

        $this->assertSame('новый_формат', $other['dialect_title'],
            'Новый диалект воркера показывается как есть, а не теряется');
    }

    public function test_hits_sanitize_rows()
    {
        $hits = Service_Nj653_Veclab::hits([
            ['id' => '5', 'label' => ' Кот ', 'kind' => 'непонятно', 'score' => 0.9,
             'preview' => 'javascript:alert(1)'],
            'мусор',
        ]);

        $this->assertCount(1, $hits, 'Не-массивы в списке находок игнорируются');
        $this->assertSame(5, $hits[0]['id']);
        $this->assertSame('Кот', $hits[0]['label']);
        $this->assertSame('text', $hits[0]['kind'], 'Неизвестная модальность — это текст');
        $this->assertSame('', $hits[0]['preview'], 'Чужая ссылка в src недопустима');
    }

    // --------------------------------------------------------------- превью

    public function test_safe_preview_allows_only_image_data_urls()
    {
        $ok = 'data:image/jpeg;base64,/9j/4AAQSkZJRg==';

        $this->assertSame($ok, Service_Nj653_Veclab::safe_preview($ok));
        $this->assertSame('', Service_Nj653_Veclab::safe_preview('data:text/html;base64,PHNjcmlwdD4='),
            'data:text/html в src — это чужой скрипт на странице');
        $this->assertSame('', Service_Nj653_Veclab::safe_preview('https://example.com/cat.jpg'),
            'Внешних адресов в коллекции не бывает');
        $this->assertSame('', Service_Nj653_Veclab::safe_preview(''));
    }

    public function test_safe_preview_strips_whitespace()
    {
        $this->assertSame('data:image/png;base64,AAAA',
            Service_Nj653_Veclab::safe_preview("data:image/png;base64,AA\n AA"),
            'Переносы строк из base64 убираются, картинка остаётся');
    }

    // ----------------------------------------------------- итог самопроверки

    protected function _probe(array $checks)
    {
        return Service_Nj653_Veclab::shape(['mode' => 'probe', 'checks' => $checks]);
    }

    public function test_probe_verdict_fails_when_model_is_not_multimodal()
    {
        $shape = $this->_probe([
            ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
            ['key' => 'meaning', 'title' => 'Смысл текста', 'value' => 0.4, 'ok' => TRUE],
            ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.01, 'ok' => FALSE],
        ]);

        $verdict = $shape['verdict'];

        $this->assertFalse($verdict['ok'], 'Без кросс-модальности модуль бесполезен');
        $this->assertSame('rose', $verdict['tone']);
        $this->assertStringContainsString('не связывает', $verdict['title']);
    }

    public function test_probe_verdict_warns_about_other_failures()
    {
        $verdict = $this->_probe([
            ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 0.3, 'ok' => FALSE],
            ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.5, 'ok' => TRUE],
        ])['verdict'];

        $this->assertFalse($verdict['ok']);
        $this->assertSame('amber', $verdict['tone']);
        $this->assertStringContainsString('Повторяемость', $verdict['note'],
            'Человеку нужно имя непрошедшей проверки, а не «что-то не так»');
    }

    public function test_probe_verdict_separates_skipped_cross_check()
    {
        $verdict = $this->_probe([
            ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
            ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => NULL, 'ok' => NULL],
        ])['verdict'];

        $this->assertTrue($verdict['ok'], 'Пропущенная проба — не провал');
        $this->assertSame('amber', $verdict['tone'],
            'Но и зелёным это называть нельзя: главное не проверено');
    }

    public function test_probe_verdict_is_green_when_everything_passed()
    {
        $verdict = $this->_probe([
            ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
            ['key' => 'meaning', 'title' => 'Смысл текста', 'value' => 0.47, 'ok' => TRUE],
            ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.48, 'ok' => TRUE],
        ])['verdict'];

        $this->assertTrue($verdict['ok']);
        $this->assertSame('emerald', $verdict['tone']);
    }

    public function test_probe_verdict_names_caption_mode()
    {
        // Режим описания работает, но ДРУГИМ способом: вектор по картинке
        // даёт не эмбеддинг-модель, а «vision-чат → слова → текст». Выдавать
        // это за мультимодальную модель нельзя — человек должен знать.
        $shape = Service_Nj653_Veclab::shape([
            'mode'     => 'probe',
            'passport' => ['mode' => 'caption', 'base_url' => 'https://llm.example/v1'],
            'checks'   => [
                ['key' => 'repeat', 'title' => 'Повторяемость', 'value' => 1.0, 'ok' => TRUE],
                ['key' => 'cross', 'title' => 'Текст и картинка', 'value' => 0.5, 'ok' => TRUE],
            ],
        ]);

        $this->assertSame('caption', $shape['passport']['mode']);
        $this->assertSame('amber', $shape['verdict']['tone']);
        $this->assertStringContainsString('описание', $shape['verdict']['title']);
    }

    public function test_passport_keeps_server_model_list()
    {
        $shape = Service_Nj653_Veclab::shape([
            'mode'     => 'probe',
            'checks'   => [],
            'passport' => [
                'models'       => ['qwen3-vl-embedding-2b', 'text-embedding-qwen3-embedding-0.6b', 42],
                'model_known'  => TRUE,
                'provider'     => 'TOOLFORGE',
                'model_note'   => 'подставлено имя',
            ],
        ]);

        $passport = $shape['passport'];

        $this->assertCount(2, $passport['models'], 'Мусор в списке моделей не нужен');
        $this->assertTrue($passport['model_known']);
        $this->assertSame('TOOLFORGE', $passport['provider']);
        $this->assertSame('подставлено имя', $passport['model_note']);
    }

    // ------------------------------------------------------------- подписи

    public function test_labels_cover_unknown_values()
    {
        $this->assertSame('картинка', Service_Nj653_Veclab::kind_label('image'));
        $this->assertSame('текст', Service_Nj653_Veclab::kind_label('кот'));
        $this->assertSame('fa-layer-group', Service_Nj653_Veclab::kind_icon('both'));
        $this->assertSame('fa-align-left', Service_Nj653_Veclab::kind_icon('кот'));
        $this->assertSame('Проверка модели', Service_Nj653_Veclab::mode_label('probe'));
        $this->assertSame('Прогон', Service_Nj653_Veclab::mode_label('кот'));
    }
}
