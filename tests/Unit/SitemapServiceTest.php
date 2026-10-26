<?php

use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Service_Nj602_Sitemap (sitemap.xml из манифестов).
 *
 * Запуск: php tests/mini_runner.php SitemapService
 */
class SitemapServiceTest extends TestCase {

    public function test_module_href_and_extra_urls()
    {
        $urls = Service_Nj602_Sitemap::module_urls([
            ['href' => '/calendar', 'sitemap' => ['/calendar/2026', '/calendar/2027']],
            ['href' => '/percent'],
        ]);

        $this->assertSame(['/calendar', '/calendar/2026', '/calendar/2027', '/percent'], array_column($urls, 'loc'));
        $this->assertSame('0.6', $urls[0]['pri']);
        $this->assertSame('0.5', $urls[1]['pri']);
    }

    public function test_hidden_draft_and_odd_hrefs_are_skipped()
    {
        $urls = Service_Nj602_Sitemap::module_urls([
            ['href' => '/account', 'audience' => 'auth'],
            ['href' => '/raw', 'status' => 'draft'],
            ['href' => '/agent', 'audience' => 'agent'],
            ['href' => '/x?tab=1'],
            ['href' => '/user/{id}'],
            ['href' => 'https://example.org/'],
            ['href' => ''],
            ['title' => 'без href'],
            ['href' => '/ok'],
        ]);

        $this->assertSame(['/ok'], array_column($urls, 'loc'));
    }

    public function test_merge_keeps_first_and_ignores_trailing_slash()
    {
        $m = Service_Nj602_Sitemap::merge([
            ['loc' => '/vat', 'pri' => '0.5'],
            ['loc' => '/vat/', 'pri' => '0.6'],
            ['loc' => '/', 'pri' => '1.0'],
            ['loc' => '/', 'pri' => '0.1'],
        ]);

        $this->assertCount(2, $m);
        $this->assertSame('0.5', $m[0]['pri']);
    }

    public function test_real_catalog_gives_many_valid_urls()
    {
        $urls = Service_Nj602_Sitemap::module_urls(Service_Nj110_Catalog::modules());
        $locs = array_column($urls, 'loc');

        $this->assertGreaterThan(40, count($locs), 'публичных адресов из манифестов');
        $this->assertContains('/percent', $locs);
        $this->assertContains('/calendar/2026', $locs);

        foreach ($locs as $loc)
        {
            $this->assertTrue(Service_Nj602_Sitemap::is_indexable($loc), $loc);
        }
    }
}
