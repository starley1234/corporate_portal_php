<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj671_Bench (nj671_embedbench — стенд эмбеддингов).
 *
 * Здесь проверяется ВТОРАЯ половина стенда: как ответ воркера превращается в
 * таблицу и слова. Метрики считает воркер (`workers/n2j_workers/embbench.py`,
 * его тесты — `workers/tests/test_nj671_bench.py`), поэтому подделывать здесь
 * арифметику нечего: важно, что страница не врёт о том, что пришло, и что
 * вывод не рассыпается на неполном отчёте.
 *
 * Запуск: php tests/mini_runner.php EmbedbenchService
 */
class EmbedbenchServiceTest extends TestCase {

    /** Отчёт воркера как есть (по нему страница и рисуется). */
    protected function _result(array $over = [])
    {
        $text = [
            'key' => 'text',
            'label' => 'Текстовый путь',
            'base_url' => 'http://127.0.0.1:1235/v1',
            'model' => 'text-embedding-qwen3-embedding-0.6b',
            'ok' => TRUE,
            'error' => '',
            'dims' => 1024,
            'notes' => ['пачка до 32 текстов одним запросом'],
            'quality' => ['recall1' => 0.7, 'recall3' => 0.9, 'mrr' => 0.79,
                          'related' => 0.71, 'unrelated' => 0.33, 'margin' => 0.38],
            'repeat' => 0.9999,
            'latency' => ['total_ms' => 1200, 'per_item_ms' => 48.0],
            'images' => ['supported' => FALSE, 'checked' => FALSE, 'ok' => NULL],
            'rows' => [
                ['query' => 'течёт кран', 'top' => 0, 'top_cos' => 0.91, 'ok' => TRUE, 'rank' => 1],
                ['query' => 'поклеить обои', 'top' => 3, 'top_cos' => 0.55, 'ok' => FALSE, 'rank' => 0],
            ],
        ];

        $mm = [
            'key' => 'mm',
            'label' => 'Мультимодальный путь',
            'base_url' => 'https://embed.example/v1',
            'model' => 'Qwen3-VL-Embedding-2B',
            'ok' => TRUE,
            'error' => '',
            'dims' => 1024,
            'notes' => ['диалект: omni_messages'],
            'quality' => ['recall1' => 0.9, 'recall3' => 1.0, 'mrr' => 0.93,
                          'related' => 0.66, 'unrelated' => 0.21, 'margin' => 0.45],
            'repeat' => 0.997,
            'latency' => ['total_ms' => 24000, 'per_item_ms' => 940.0],
            'images' => ['supported' => TRUE, 'checked' => TRUE, 'ok' => TRUE, 'margin' => 0.31],
            'rows' => [
                ['query' => 'течёт кран', 'top' => 0, 'top_cos' => 0.93, 'ok' => TRUE, 'rank' => 1],
                ['query' => 'поклеить обои', 'top' => 5, 'top_cos' => 0.62, 'ok' => TRUE, 'rank' => 2],
            ],
        ];

        return $over + [
            'status' => 'done',
            'title' => 'Стенд эмбеддингов · Каталог бытовых услуг',
            'corpus' => ['key' => 'catalog', 'title' => 'Каталог бытовых услуг',
                         'docs' => 14, 'queries' => 10, 'quick' => FALSE],
            'engines' => ['text' => $text, 'mm' => $mm],
            'agreement' => 0.62,
            'decision' => [
                'winner' => 'both',
                'summary' => 'Оба пути нужны и делят работу.',
                'reasons' => [
                    ['code' => 'mm_better', 'value' => 0.2],
                    ['code' => 'text_faster', 'value' => 19.6],
                    ['code' => 'images_ok', 'value' => 0.31],
                ],
                'tasks' => [
                    ['task' => 'text_index', 'pick' => 'mm', 'delta' => 0.2, 'speed' => 19.6],
                    ['task' => 'cross_modal', 'pick' => 'mm'],
                    ['task' => 'one_space', 'pick' => 'text'],
                ],
            ],
            'elapsed_ms' => 26000,
        ];
    }

    public function test_shape_keeps_both_sides_and_metrics()
    {
        $data = Service_Nj671_Bench::shape($this->_result());

        $this->assertTrue($data['ok']);
        $this->assertSame('catalog', $data['corpus']['key']);
        $this->assertSame(14, $data['corpus']['docs']);
        $this->assertCount(7, $data['metrics'], 'таблица метрик объявлена целиком');
        $this->assertTrue($data['sides']['text']['ok']);
        $this->assertSame(1024, $data['sides']['mm']['dims']);
        $this->assertSame(0.9, $data['sides']['mm']['recall1']);
        $this->assertSame(48.0, $data['sides']['text']['per_item_ms']);
    }

    public function test_metric_rows_pick_the_better_side()
    {
        $data = Service_Nj671_Bench::shape($this->_result());
        $rows = [];

        foreach ($data['metrics'] as $row)
        {
            $rows[$row['key']] = $row;
        }

        $this->assertSame('mm', $rows['recall1']['best'], '0.9 выше 0.7');
        $this->assertSame('text', $rows['per_item_ms']['best'], '48 мс дешевле 940 мс');
        $this->assertSame('', $rows['dims']['best'], 'размерность не «лучше» и не «хуже»');
        $this->assertSame('90%', $rows['recall3']['text']);
        $this->assertSame('70%', $rows['recall1']['text']);
        $this->assertSame('90%', $rows['recall1']['mm']);
    }

    public function test_rows_compare_the_same_queries()
    {
        $data = Service_Nj671_Bench::shape($this->_result());

        $this->assertCount(2, $data['rows']);
        $this->assertSame('течёт кран', $data['rows'][0]['query']);
        $this->assertTrue($data['rows'][0]['text_ok']);
        $this->assertTrue($data['rows'][0]['mm_ok']);
        $this->assertFalse($data['rows'][1]['text_ok']);
        $this->assertTrue($data['rows'][1]['mm_ok'], 'разница путей и должна быть видна');
    }

    public function test_same_dimensions_note_warns_about_spaces()
    {
        $data = Service_Nj671_Bench::shape($this->_result());
        $titles = array_column($data['notes'], 'title');

        $this->assertContains('Размерности совпали: 1024', $titles);
        $this->assertContains('Согласие структуры: r = 0.62', $titles);

        $same = $data['notes'][0];

        $this->assertStringContainsString('НЕ значит', $same['text'],
            'совпадение ширины не делает пространства одним');
    }

    public function test_different_dimensions_is_a_red_note()
    {
        $result = $this->_result();
        $result['engines']['mm']['dims'] = 2048;
        $result['agreement'] = NULL;

        $data = Service_Nj671_Bench::shape($result);
        $notes = $data['notes'];

        $this->assertSame('Размерности разные: 1024 и 2048', $notes[0]['title']);
        $this->assertSame('rose', $notes[0]['tone']);
    }

    public function test_one_side_off_does_not_break_the_report()
    {
        $result = $this->_result();
        $result['engines']['mm']['ok'] = FALSE;
        $result['engines']['mm']['error'] = 'EMBEDDING_MM_API_URL не задан в workers/.env';
        $result['agreement'] = NULL;
        $result['decision'] = [
            'winner' => 'text',
            'summary' => 'Отвечает только один путь.',
            'reasons' => [['code' => 'only_one', 'side' => 'text',
                           'error' => 'EMBEDDING_MM_API_URL не задан в workers/.env']],
            'tasks' => [['task' => 'any', 'pick' => 'text']],
        ];

        $data = Service_Nj671_Bench::shape($result);

        $this->assertTrue($data['ok'], 'один рабочий путь — уже результат');
        $this->assertFalse($data['sides']['mm']['ok']);
        $this->assertStringContainsString('EMBEDDING_MM_API_URL',
            $data['sides']['mm']['error']);

        $rows = [];
        foreach ($data['metrics'] as $row) { $rows[$row['key']] = $row; }

        $this->assertSame('—', $rows['recall1']['mm'], 'у выключенного пути цифр нет');
        $this->assertSame('', $rows['recall1']['best'], 'сравнивать не с чем');
    }

    public function test_both_off_is_reported_honestly()
    {
        $result = $this->_result();
        $result['engines']['text']['ok'] = FALSE;
        $result['engines']['text']['error'] = 'нет адреса';
        $result['engines']['mm']['ok'] = FALSE;
        $result['engines']['mm']['error'] = 'нет адреса';
        $result['agreement'] = NULL;
        $result['decision'] = ['winner' => 'none', 'summary' => '', 'reasons' => [], 'tasks' => []];

        $data = Service_Nj671_Bench::shape($result);

        $this->assertFalse($data['ok']);
        $this->assertSame('Сравнение не удалось', $data['decision']['label']);
    }

    public function test_winner_and_tasks_are_translated()
    {
        $data = Service_Nj671_Bench::shape($this->_result());
        $decision = $data['decision'];

        $this->assertSame('Держать оба пути', $decision['label']);
        $this->assertSame('emerald', $decision['tone']);

        $picks = [];

        foreach ($decision['tasks'] as $task)
        {
            $picks[$task['task']] = $task['label'];
        }

        $this->assertSame('мультимодальный путь', $picks['text_index']);
        $this->assertSame('мультимодальный путь', $picks['cross_modal']);
        $this->assertSame('текстовый путь', $picks['one_space']);
    }

    public function test_every_known_reason_has_words()
    {
        foreach (['only_one', 'both_off', 'mm_better', 'text_better', 'same_quality',
                  'text_faster', 'images_ok', 'images_weak', 'text_unstable',
                  'mm_unstable', 'dims_differ'] as $code)
        {
            $reason = Service_Nj671_Bench::reason($code, [
                'value' => 0.5, 'side' => 'text', 'error' => 'нет',
                'text' => 1024, 'mm' => 2048,
            ]);

            $this->assertSame($code, $reason['code']);
            $this->assertNotSame('', $reason['title'], "у причины $code нет заголовка");
            $this->assertNotSame($code, $reason['title'], "причина $code не переведена");
        }

        $unknown = Service_Nj671_Bench::reason('что-то новое', []);

        $this->assertSame('что-то новое', $unknown['title'],
            'неизвестный код показываем как есть, а не прячем');
    }

    public function test_display_and_grades()
    {
        $this->assertSame('80%', Service_Nj671_Bench::pct(0.8));
        $this->assertSame('12.3', Service_Nj671_Bench::display('ms', 12.34));
        $this->assertSame('1024', Service_Nj671_Bench::display('int', 1024));
        $this->assertSame('0.999', Service_Nj671_Bench::display('cos', 0.9987));
        $this->assertSame('—', Service_Nj671_Bench::display('pct', NULL));
        $this->assertSame('—', Service_Nj671_Bench::display('pct', 0.9, FALSE),
            'у выключенного пути «0%» было бы враньём');

        $this->assertSame('emerald', Service_Nj671_Bench::grade('recall1', 0.9)['tone']);
        $this->assertSame('amber', Service_Nj671_Bench::grade('recall1', 0.6)['tone']);
        $this->assertSame('rose', Service_Nj671_Bench::grade('recall1', 0.1)['tone']);
        $this->assertSame('стабильно', Service_Nj671_Bench::grade('repeat', 0.9997)['text']);
        $this->assertSame('дрожит', Service_Nj671_Bench::grade('repeat', 0.97)['text']);
        $this->assertSame('связывает',
            Service_Nj671_Bench::grade('images', 1, ['ok' => TRUE])['text']);
    }

    public function test_unstable_vector_gets_a_warning()
    {
        $data = Service_Nj671_Bench::shape($this->_result());
        $titles = array_column($data['notes'], 'title');

        $this->assertContains('Мультимодальный путь: вектор «дрожит»', $titles,
            'повторяемость 0.997 — это ниже порога 0.999');
        $this->assertNotContains('Текстовый путь: вектор «дрожит»', $titles);
    }

    public function test_brief_is_short_and_honest()
    {
        $run = ['result' => $this->_result()];
        $brief = Service_Nj671_Bench::brief($run);

        $this->assertStringContainsString('текст 70%', $brief);
        $this->assertStringContainsString('мультимодальный 90%', $brief);
        $this->assertStringContainsString('оба пути', $brief);

        $this->assertSame('', Service_Nj671_Bench::brief(['result' => NULL]),
            'непосчитанный прогон не показывает «0%»');
    }

    public function test_corpora_declared_and_known()
    {
        $corpora = Service_Nj671_Bench::corpora();

        $this->assertArrayHasKey('catalog', $corpora);
        $this->assertNotSame('', (string) $corpora['catalog']['title']);
        $this->assertNull(Service_Nj671_Bench::corpus('нет такого'),
            'неизвестный набор не должен уезжать в очередь');
        $this->assertNotNull(Service_Nj671_Bench::corpus('catalog'));
    }

    public function test_empty_result_does_not_crash()
    {
        $data = Service_Nj671_Bench::shape([]);

        $this->assertFalse($data['ok']);
        $this->assertCount(7, $data['metrics']);
        $this->assertSame([], $data['rows']);
        $this->assertSame('Сравнение не удалось', $data['decision']['label']);
    }

    /**
     * Полный отчёт, короткая сводка и пустой result — три разных состояния.
     *
     * Живой случай 22.10.2026: владелец запустил стенд на настроенном сервере и
     * увидел «Ни один путь не ответил — причина не записана». Виноват был дожим
     * прогона: он перезаписывал доставленный отчёт короткой сводкой из очереди
     * (в ней нет `engines`, только winner/text_ok/mm_ok), а страница считала
     * отсутствие метрик отказом обоих путей. Различать эти состояния обязан
     * `shape()` — иначе страница врёт о состоянии серверов.
     */
    public function test_report_summary_and_empty_are_different_states()
    {
        $full = Service_Nj671_Bench::shape($this->_result());
        $this->assertSame('full', $full['state'], 'полный отчёт узнаётся по engines');

        $summary = Service_Nj671_Bench::shape([
            'summary_only' => TRUE,
            'run_id'       => 7,
            'corpus'       => 'catalog',
            'winner'       => 'both',
            'text_ok'      => TRUE,
            'mm_ok'        => TRUE,
            'agreement'    => 0.62,
        ]);

        $this->assertSame('summary', $summary['state']);
        $this->assertSame(TRUE, $summary['summary']['text_ok']);
        $this->assertSame(TRUE, $summary['summary']['mm_ok']);
        $this->assertSame(0.62, $summary['summary']['agreement']);
        $this->assertSame('Держать оба пути',
            Service_Nj671_Bench::winner_text($summary['summary']['winner']),
            'сводка всё равно называет вывод');
        $this->assertSame('catalog', $summary['corpus']['key'],
            'ключ набора из сводки не теряется, даже когда пришёл строкой');
        $this->assertNotSame('', $summary['corpus']['title'],
            'название набора берётся из конфига — иначе на странице пусто');

        $empty = Service_Nj671_Bench::shape([]);
        $this->assertSame('empty', $empty['state']);
        $this->assertNull($empty['summary']['text_ok'],
            'про пустой результат нельзя утверждать, что путь не ответил');
    }

    /** Сводка без флага тоже узнаётся: старые задачи придут без `summary_only`. */
    public function test_old_summary_without_flag_is_still_a_summary()
    {
        $data = Service_Nj671_Bench::shape([
            'run_id' => 3, 'winner' => 'text', 'text_ok' => TRUE, 'mm_ok' => FALSE,
        ]);

        $this->assertSame('summary', $data['state']);
        $this->assertSame(FALSE, $data['summary']['mm_ok']);
        $this->assertFalse($data['ok'], 'в сводке нет метрик — сравнивать нечего');
    }
}
