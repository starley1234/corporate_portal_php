<?php
use PHPUnit\Framework\TestCase;

/** Навигация основного шаблона и доступность сервисов через каталог. */
class PortalNavigationTest extends TestCase
{
    public function test_sidebar_contains_only_catalog_even_when_module_navigation_is_supplied(): void
    {
        $items = Service_Prx110_Catalog::menu_items();
        $this->assertSame(['/services'], array_column($items, 'href'));

        $sidebar = View::factory('partials/sidebar', [
            'site_name' => 'PRX',
            'module_nav' => [['title' => 'Входящие', 'href' => '/inbox']],
            'nav_favorites' => ['status' => 'guest', 'items' => []],
        ])->render();
        $this->assertStringContainsString('href="/services"', $sidebar);
        $this->assertStringNotContainsString('href="/inbox"', $sidebar);
        $this->assertStringNotContainsString('href="/architecture"', $sidebar);
        $this->assertStringNotContainsString('href="/account/security"', $sidebar);
    }

    public function test_catalog_excludes_archived_and_deleted_services_even_for_admin(): void
    {
        $admin = ['guest' => FALSE, 'logged' => TRUE, 'roles' => ['login', 'admin'], 'admin' => TRUE, 'agent' => TRUE];
        $items = Service_Prx110_Catalog::search_json($admin)['items'];
        $hrefs = array_column($items, 'href');
        foreach ([
            '/architecture', '/design-lab', '/prx560', '/employee', '/prx597', '/account/security',
        ] as $href) {
            $this->assertContains($href, $hrefs, $href);
        }
        foreach (['/inbox', '/documents', '/analysis', '/sla', '/replies', '/vlm-demo'] as $href) {
            $this->assertNotContains($href, $hrefs, $href);
        }
        foreach ($items as $item) {
            $this->assertArrayHasKey('nav_paths', $item);
        }

        $login = ['guest' => FALSE, 'logged' => TRUE, 'roles' => ['login'], 'admin' => FALSE, 'agent' => FALSE];
        $login_hrefs = array_column(Service_Prx110_Catalog::search_json($login)['items'], 'href');
        $this->assertContains('/employee', $login_hrefs);
        $this->assertNotContains('/prx560', $login_hrefs);

        $guest = ['guest' => TRUE, 'logged' => FALSE, 'roles' => [], 'admin' => FALSE, 'agent' => FALSE];
        $guest_hrefs = array_column(Service_Prx110_Catalog::search_json($guest)['items'], 'href');
        $this->assertNotContains('/employee', $guest_hrefs);
        $this->assertNotContains('/prx560', $guest_hrefs);
        $this->assertContains('/design-lab', $guest_hrefs);
    }

    public function test_catalog_passes_only_local_navigation_aliases(): void
    {
        $property = new ReflectionProperty('Service_Prx110_Catalog', '_modules');
        $property->setAccessible(TRUE);
        $old = $property->getValue();
        try {
            $property->setValue(NULL, [[
                'title' => 'Пример', 'href' => '/example', 'icon' => 'fas fa-file',
                'group' => 'Сервисы', 'sort' => 1, 'module_name' => 'example',
                'status' => 'stable', 'audience' => 'public',
                'nav_paths' => [' /alias ', '//evil.test', 'https://evil.test', '', '/another'],
            ]]);
            $guest = ['guest' => TRUE, 'logged' => FALSE, 'roles' => [], 'admin' => FALSE, 'agent' => FALSE];
            $item = Service_Prx110_Catalog::search_json($guest)['items'][0];
            $this->assertSame(['/alias', '/another'], $item['nav_paths']);
        } finally {
            $property->setValue(NULL, $old);
        }
    }

    public function test_template_accent_accepts_known_colors_and_defaults_to_orange(): void
    {
        $config = Kohana::$config->load('site_config');
        $old = $config->get('company_accent');
        $old_host = $_SERVER['HTTP_HOST'] ?? NULL;
        $_SERVER['HTTP_HOST'] = parse_url((string) (getenv('APP_URL') ?: 'http://localhost:8080'), PHP_URL_HOST) ?: 'localhost';
        try {
            foreach (['orange' => 'orange', 'BLUE' => 'blue', 'yellow' => 'yellow', 'invalid"' => 'orange'] as $input => $expected) {
                $config->set('company_accent', $input);
                $head = View::factory('partials/head')->render();
                $this->assertStringContainsString('data-company-accent="'.$expected.'"', $head);
                if ($input === 'invalid"') $this->assertStringNotContainsString($input, $head);
            }
        } finally {
            $config->set('company_accent', $old);
            if ($old_host === NULL) { unset($_SERVER['HTTP_HOST']); }
            else { $_SERVER['HTTP_HOST'] = $old_host; }
        }
    }
}
