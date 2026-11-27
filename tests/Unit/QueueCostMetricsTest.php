<?php
use PHPUnit\Framework\TestCase;

/** prx120 «токены → рубли» и формат /health/metrics. */
class QueueCostMetricsTest extends TestCase
{
    protected function tearDown(): void
    {
        Service_Prx120_Cost::override(NULL);
    }

    public function test_price_lookup_order_and_unpriced_models(): void
    {
        Service_Prx120_Cost::override([
            'currency'    => '₽',
            'per_million' => [
                'OpenAI::gpt-4o-mini' => [15, 60],
                'ollama::*'           => [0, 0],
                'yandexgpt-lite'      => [200, 200],
            ],
            'default' => [0, 0],
        ]);

        $this->assertSame([15.0, 60.0], Service_Prx120_Cost::price('openai', 'GPT-4o-mini'));
        $this->assertSame([0.0, 0.0], Service_Prx120_Cost::price('ollama', 'qwen2.5:7b'), 'провайдер::* — бесплатно');
        $this->assertSame([200.0, 200.0], Service_Prx120_Cost::price('yandex', 'yandexgpt-lite'));
        $this->assertNull(Service_Prx120_Cost::price('anthropic', 'x'), 'нет цены — NULL, а не 0');

        // 1 млн промпта по 15 + 0,5 млн ответа по 60 = 45 ₽
        $this->assertEqualsWithDelta(45.0, Service_Prx120_Cost::rub('openai', 'gpt-4o-mini', 1000000, 500000), 0.0001);
    }

    public function test_aggregate_keeps_unpriced_tokens_apart(): void
    {
        Service_Prx120_Cost::override(['per_million' => ['m1' => [10, 20]], 'default' => [0, 0]]);

        $agg = Service_Prx120_Cost::aggregate([
            ['channel' => 'a', 'provider' => 'p', 'model' => 'm1', 'ptokens' => 100000, 'ctokens' => 50000],
            ['channel' => 'a', 'provider' => 'p', 'model' => 'mX', 'ptokens' => 7, 'ctokens' => 3],
            ['channel' => 'b', 'provider' => 'p', 'model' => 'mX', 'ptokens' => 5, 'ctokens' => 0],
        ], 'channel');

        $this->assertEqualsWithDelta(2.0, $agg['a']['rub'], 0.0001);
        $this->assertSame(10, $agg['a']['unpriced_tokens']);
        $this->assertTrue($agg['a']['priced']);
        $this->assertFalse($agg['b']['priced']);
        $this->assertSame('—', Service_Prx120_Cost::fmt($agg['b']), 'неоценённое не выглядит бесплатным');
        $this->assertSame('2,00 ₽ +', Service_Prx120_Cost::fmt($agg['a']), '«+» — часть токенов без цены');

        Service_Prx120_Cost::override(['per_million' => [], 'default' => [100, 100]]);
        $this->assertSame([100.0, 100.0], Service_Prx120_Cost::price('any', 'model'), 'default из env');
    }

    public function test_metrics_text_is_valid_prometheus_exposition(): void
    {
        $text = Service_Prx103_Metrics::prometheus();

        $this->assertStringContainsString("prx_up 1\n", $text);
        $this->assertMatchesRegularExpression('/^prx_db_up [01]$/m', $text);

        foreach (explode("\n", trim($text)) as $line)
        {
            if ($line[0] === '#')
            {
                $this->assertMatchesRegularExpression('/^# (HELP|TYPE) [a-z_][a-z0-9_]* .+$/u', $line);
                continue;
            }

            $this->assertMatchesRegularExpression('/^[a-z_][a-z0-9_]*(\{[a-z_]+="(?:[^"\\\\]|\\\\.)*"\})? -?[0-9]+(\.[0-9]+)?$/', $line);
        }
    }

    public function test_metrics_token_must_be_configured_and_match(): void
    {
        $old = getenv('PRX_METRICS_TOKEN');

        try
        {
            putenv('PRX_METRICS_TOKEN=');
            $this->assertFalse(Service_Prx103_Metrics::token_ok(''), 'пустой секрет не открывает метрики');
            $this->assertFalse(Service_Prx103_Metrics::token_ok('x'));

            putenv('PRX_METRICS_TOKEN=s3cret');
            $this->assertTrue(Service_Prx103_Metrics::token_ok('s3cret'));
            $this->assertFalse(Service_Prx103_Metrics::token_ok('s3cre'));
            $this->assertFalse(Service_Prx103_Metrics::token_ok(NULL));
        }
        finally
        {
            putenv($old === FALSE ? 'PRX_METRICS_TOKEN' : 'PRX_METRICS_TOKEN='.$old);
        }
    }
}
