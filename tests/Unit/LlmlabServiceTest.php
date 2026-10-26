<?php

use PHPUnit\Framework\TestCase;

/**
 * Чистая логика «Лаборатории LLM» (nj645_llmlab): токенизация, распределение,
 * разбор промпта, локальные эмбеддинги. База и ИИ не нужны — сервис Lab
 * специально построен без запросов (см. Service_Nj645_Lab).
 */
class LlmlabServiceTest extends TestCase {

    private function vocab()
    {
        return [
            'words'  => ['языковая' => 5, 'модель' => 12, 'предсказывает' => 3, 'токен' => 7],
            'pieces' => ['язы', 'кова', 'мо', 'дель', 'ток', 'ен'],
        ];
    }

    public function test_tokenize_prefers_known_word_and_splits_unknown()
    {
        $out = Service_Nj645_Lab::tokenize('Модель ток', $this->vocab());

        $kinds = array_map(function ($t) { return $t['kind']; }, $out['tokens']);

        $this->assertContains('word', $kinds);
        $this->assertGreaterThan(0, $out['count']);
        $this->assertCount($out['count'], $out['tokens']);

        $known = FALSE;

        foreach ($out['tokens'] as $token)
        {
            if (mb_strtolower($token['t']) === 'модель')
            {
                $known = $token['known'] AND $token['kind'] === 'word';
            }
        }

        $this->assertTrue($known, 'известное слово должно стать одним токеном');
    }

    public function test_estimate_tokens_orders_cyrillic_above_latin()
    {
        $cyr = Service_Nj645_Lab::estimate_tokens('модель предсказывает следующий токен');
        $lat = Service_Nj645_Lab::estimate_tokens('the model predicts the next token');

        $this->assertGreaterThan(0, $cyr['total']);
        $this->assertGreaterThan($lat['total'], $cyr['total']);
        $this->assertNotEmpty($cyr['parts']);
    }

    public function test_distribution_is_softmax_with_temperature_and_greedy_mode()
    {
        $rows = [
            ['token' => 'а', 'score' => 1.0, 'count' => 10, 'n' => 1],
            ['token' => 'б', 'score' => 0.25, 'count' => 2, 'n' => 1],
        ];

        $hot = Service_Nj645_Lab::distribution($rows, 1.0, 0, 1.0);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($hot['items'], 'prob')), 1e-9);
        $this->assertGreaterThan($hot['items'][1]['prob'], $hot['items'][0]['prob']);

        $cold = Service_Nj645_Lab::distribution($rows, 0.2, 0, 1.0);
        $this->assertGreaterThan($hot['items'][0]['prob'], $cold['items'][0]['prob']);

        $greedy = Service_Nj645_Lab::distribution($rows, 0.0, 0, 1.0);
        $this->assertSame('а', $greedy['items'][0]['token']);
        $this->assertSame(1.0, $greedy['items'][0]['prob']);
        $this->assertTrue($greedy['greedy']);
    }

    public function test_distribution_respects_top_k_and_top_p()
    {
        $rows = [
            ['token' => 'а', 'score' => 1.0, 'count' => 1, 'n' => 1],
            ['token' => 'б', 'score' => 0.9, 'count' => 1, 'n' => 1],
            ['token' => 'в', 'score' => 0.8, 'count' => 1, 'n' => 1],
            ['token' => 'г', 'score' => 0.7, 'count' => 1, 'n' => 1],
        ];

        $topk = Service_Nj645_Lab::distribution($rows, 1.0, 2, 1.0);
        $this->assertCount(2, $topk['items']);

        $topp = Service_Nj645_Lab::distribution($rows, 1.0, 0, 0.5);
        $this->assertTrue(count($topp['items']) <= 2);
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($topp['items'], 'prob')), 1e-9);

        $empty = Service_Nj645_Lab::distribution([], 1.0);
        $this->assertSame([], $empty['items']);
        $this->assertSame(0, $empty['total']);
    }

    public function test_local_embedding_similarity_and_neighbours()
    {
        $a = Service_Nj645_Lab::local_embed('кошка сидит на окне', 64);
        $b = Service_Nj645_Lab::local_embed('на окне сидит кошка', 64);
        $c = Service_Nj645_Lab::local_embed('курс доллара вырос', 64);

        $this->assertCount(64, $a);
        $this->assertEqualsWithDelta(1.0, Service_Nj645_Lab::cosine($a, $a), 1e-9);
        $this->assertGreaterThan(Service_Nj645_Lab::cosine($a, $c), Service_Nj645_Lab::cosine($a, $b));

        $nearest = Service_Nj645_Lab::nearest($a, [
            ['id' => 1, 'label' => 'похожий', 'vector' => $b],
            ['id' => 2, 'label' => 'другой', 'vector' => $c],
        ], 2);

        $this->assertSame(1, (int) $nearest[0]['id']);
        $this->assertGreaterThan($nearest[1]['similarity'], $nearest[0]['similarity']);
    }

    public function test_prompt_analyzer_scores_and_flags_problems()
    {
        $good = Service_Nj645_Lab::analyze_prompt(
            'Ты — редактор. Задача: перепиши абзац про LLM в 3 предложениях для новичка. '
            .'Контекст: аудитория — школьники. Формат ответа: markdown-список. '
            .'Ограничения: без терминов, не длиннее 60 слов. Пример вывода: 1) … 2) …'
        );
        $bad = Service_Nj645_Lab::analyze_prompt('сделай хорошо');

        $this->assertGreaterThan($bad['score'], $good['score']);
        $this->assertCount(10, $good['checks']);
        $this->assertTrue($good['score'] >= 5 AND $good['score'] <= 100);
        $this->assertSame('слабый', $bad['grade']);
        $this->assertTrue($bad['score'] < 40);

        foreach ($good['findings'] as $finding)
        {
            $this->assertArrayHasKey('level', $finding);
            $this->assertArrayHasKey('title', $finding);
            $this->assertArrayHasKey('text', $finding);
        }

        $this->assertSame('отличный', Service_Nj645_Lab::analyze_prompt(
            'Ты — опытный редактор. Задача: перепиши текст. Контекст: ниже новость. '
            .'Формат: markdown-список. Ограничения: не более 5 пунктов, без оценок. '
            .'Пример: 1) факт. Аудитория: читатели блога. Тон: нейтральный. '
            .'Если данных нет — не выдумывай. Сначала факты, затем выводы.'
        )['grade']);
    }

    public function test_text_helpers_and_similarity_labels()
    {
        $this->assertSame('а б', Service_Nj645_Lab::normalize_text("а   б"));
        $this->assertSame("а\n\nб", Service_Nj645_Lab::normalize_text("а\n\n\n\nб"));
        $this->assertSame("а\nб", Service_Nj645_Lab::normalize_text("а\tб"));
        $this->assertSame('ru', Service_Nj645_Lab::detect_lang('привет мир'));
        $this->assertSame('en', Service_Nj645_Lab::detect_lang('hello world'));
        $this->assertSame('other', Service_Nj645_Lab::detect_lang('123 456'));

        $label = Service_Nj645_Lab::similarity_label(0.97);
        $this->assertSame('почти одно и то же', $label['title']);
        $this->assertSame('good', $label['tone']);
        $this->assertSame('разные тексты', Service_Nj645_Lab::similarity_label(0.05)['title']);
        $this->assertSame('есть общая тема', Service_Nj645_Lab::similarity_label(0.4)['title']);
    }

    public function test_cost_uses_model_prices_and_context()
    {
        $models = [
            'test-model' => ['title' => 'Тест', 'context' => 2000, 'in' => 100.0, 'out' => 200.0, 'own' => FALSE, 'note' => ''],
        ];

        $cost = Service_Nj645_Lab::cost(1000, 500, 'test-model', $models);

        $this->assertSame(0.1, $cost['in']);
        $this->assertSame(0.1, $cost['out']);
        $this->assertSame(0.2, $cost['total']);
        $this->assertTrue($cost['fits']);
        $this->assertFalse(Service_Nj645_Lab::cost(1800, 500, 'test-model', $models)['fits']);
        $this->assertNull(Service_Nj645_Lab::cost(10, 10, 'unknown', $models));
    }
}
