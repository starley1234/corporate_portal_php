<?php

use PHPUnit\Framework\TestCase;

/** Юнит-тесты Service_Nj516_Metrics: только локальные агрегаты и классификация источника. */
class MetricsServiceTest extends TestCase {

    public function test_path_must_be_a_local_path_and_query_is_removed()
    {
        $this->assertSame('/vat', Service_Nj516_Metrics::normalize_path('/vat'));
        $this->assertSame('/vat', Service_Nj516_Metrics::normalize_path('/vat/?a=100&secret=1'));
        $this->assertSame('/nj100/admin', Service_Nj516_Metrics::normalize_path('/nj100/admin/'));
        $this->assertNull(Service_Nj516_Metrics::normalize_path('https://n2j.ru/vat'));
        $this->assertNull(Service_Nj516_Metrics::normalize_path('//evil.example/vat'));
        $this->assertNull(Service_Nj516_Metrics::normalize_path(''));
        $this->assertNull(Service_Nj516_Metrics::normalize_path("/vat\nX-Test: 1"));
        $this->assertNull(Service_Nj516_Metrics::normalize_path(str_repeat('/', 205)));
    }

    public function test_only_public_manifest_service_pages_count()
    {
        $vat = Service_Nj516_Metrics::page_info('/vat');
        $percent = Service_Nj516_Metrics::page_info('/percent');

        $this->assertNotNull($vat);
        $this->assertSame('Бухгалтерия', $vat['group']);
        $this->assertSame('Бухгалтерия', $percent['group']);
        $this->assertNull(Service_Nj516_Metrics::page_info('/nj516/admin'));
        $this->assertNull(Service_Nj516_Metrics::page_info('/nj516/api/hit'));
        $this->assertNull(Service_Nj516_Metrics::page_info('/service/does-not-exist'));
        $this->assertNull(Service_Nj516_Metrics::page_info('/vat/calc'));
    }

    public function test_source_is_reduced_to_safe_categories()
    {
        $this->assertSame('direct', Service_Nj516_Metrics::source_of('', 'n2j.ru'));
        $this->assertSame('internal', Service_Nj516_Metrics::source_of('https://n2j.ru/services/finansy?token=private', 'n2j.ru:443'));
        $this->assertSame('search', Service_Nj516_Metrics::source_of('https://yandex.ru/search/?text=secret', 'n2j.ru'));
        $this->assertSame('search', Service_Nj516_Metrics::source_of('https://www.google.co.uk/search?q=secret', 'n2j.ru'));
        $this->assertSame('external', Service_Nj516_Metrics::source_of('https://example.org/path?secret=1', 'n2j.ru'));
        $this->assertSame('direct', Service_Nj516_Metrics::source_of('javascript:alert(1)', 'n2j.ru'));
    }

    public function test_bot_filter_does_not_store_user_agent()
    {
        foreach (['Googlebot/2.1', 'Mozilla crawler', 'curl/8.0', 'python-requests/2.31', 'HeadlessChrome/130'] as $ua)
        {
            $this->assertTrue(Service_Nj516_Metrics::is_bot($ua), $ua);
        }
        $this->assertFalse(Service_Nj516_Metrics::is_bot('Mozilla/5.0 (X11; Linux x86_64) Chrome/131.0 Safari/537.36'));
    }

    public function test_storage_is_aggregated_without_identity_columns()
    {
        $migration = file_get_contents(APPPATH.'modules/nj516_metrics/migrations_pg/pgMigration_Nj_516_001_Views_Aggregate.php');
        $this->assertStringContainsString('PRIMARY KEY (day, path, group_name, source, audience)', $migration);
        foreach (['visitor_id', 'user_id', 'ip_address', 'user_agent', 'referer'] as $forbidden)
        {
            $this->assertTrue(strpos($migration, $forbidden) === FALSE, 'В таблице нет '.$forbidden);
        }
    }

    public function test_upsert_increments_daily_aggregate_without_leaving_test_rows()
    {
        $db = Database::instance();
        $db->begin();

        try
        {
            $path = '/vat';
            $source = 'direct';
            $audience = 'guest';
            $q = function () use ($db, $path, $source, $audience) {
                $row = $db->query(Database::SELECT,
                    'SELECT views FROM nj_516_views WHERE day = current_date AND path = '.$db->quote($path)
                    .' AND source = '.$db->quote($source).' AND audience = '.$db->quote($audience), FALSE)->current();
                return $row ? (int) $row['views'] : 0;
            };

            $before = $q();
            $this->assertTrue(Service_Nj516_Metrics::track_page($path, '', FALSE, 'Mozilla/5.0', 'n2j.ru'));
            $this->assertTrue(Service_Nj516_Metrics::track_page($path, '', FALSE, 'Mozilla/5.0', 'n2j.ru'));
            $this->assertSame($before + 2, $q(), 'два просмотра увеличили один дневной агрегат');

            // В query параметр пути был бы отброшен, а endpoint/admin не создают строк.
            $this->assertTrue(Service_Nj516_Metrics::track_page('/vat?secret=1', '', FALSE, 'Mozilla/5.0', 'n2j.ru'));
            $this->assertSame($before + 3, $q(), 'query не входит в ключ агрегата');
            $this->assertFalse(Service_Nj516_Metrics::track_page('/nj516/admin', '', FALSE, 'Mozilla/5.0', 'n2j.ru'));
            $this->assertFalse(Service_Nj516_Metrics::track_page('/vat', '', FALSE, 'Googlebot/2.1', 'n2j.ru'));
            $this->assertSame($before + 3, $q(), 'непубличная страница и робот не записались');
        }
        finally
        {
            $db->rollback();
        }
    }

}
