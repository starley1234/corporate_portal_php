<?php
use PHPUnit\Framework\TestCase;

/** Навигация основного шаблона и доступность сервисов через каталог. */
class PortalNavigationTest extends TestCase
{
    private ReflectionProperty $publication_decisions;
    private ?array $previous_decisions;

    protected function setUp(): void
    {
        $this->publication_decisions = new ReflectionProperty('Service_Prx110_Publication', 'decisions');
        $this->publication_decisions->setAccessible(TRUE);
        $this->previous_decisions = $this->publication_decisions->getValue();
        $this->publication_decisions->setValue(NULL, array_fill_keys([
            'prx560_knowledge', 'prx597_notifications', 'prx599_account', 'example',
        ], ['state' => 'approved']));
    }

    protected function tearDown(): void
    {
        $this->publication_decisions->setValue(NULL, $this->previous_decisions);
    }

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

    public function test_catalog_shows_only_approved_services_and_keeps_wip_for_preview(): void
    {
        $admin = ['guest' => FALSE, 'logged' => TRUE, 'user_id' => 1, 'roles' => ['login', 'admin'], 'admin' => TRUE, 'agent' => TRUE];
        $items = Service_Prx110_Catalog::search_json($admin)['items'];
        $hrefs = array_column($items, 'href');
        foreach ([
            '/prx560', '/prx597', '/account/security',
        ] as $href) {
            $this->assertContains($href, $hrefs, $href);
        }
        // beta/draft — «в работе»: на /services и в палитру не попадают даже админу.
        foreach (['/architecture', '/prx395_document_extract', '/inbox',
                  '/prx391_documents', '/analysis', '/sla'] as $href) {
            $this->assertNotContains($href, $hrefs, $href);
        }
        foreach (['/replies', '/vlm-demo', '/employee'] as $href) {
            $this->assertNotContains($href, $hrefs, $href);
        }
        foreach ($items as $item) {
            $this->assertArrayHasKey('nav_paths', $item);
        }

        // Предпросмотр (?agent_preview=1) возвращает «в работе» агенту/админу
        // с учётом аудитории: beta-модули снова видны, архив — нет.
        $preview = $admin + ['preview' => TRUE];
        $preview_hrefs = array_column(Service_Prx110_Catalog::search_json($preview)['items'], 'href');
        foreach (['/architecture', '/prx395_document_extract'] as $href) {
            $this->assertContains($href, $preview_hrefs, $href);
        }
        $modules = Kohana::modules();
        foreach (['/inbox' => 'prx390_intake', '/prx391_documents' => 'prx391_documents', '/analysis' => 'prx392_analysis', '/sla' => 'prx393_workflow'] as $href => $module) {
            $this->assertSame(isset($modules[$module]), in_array($href, $preview_hrefs, TRUE), $href);
        }
        foreach (['/replies', '/vlm-demo', '/employee'] as $href) {
            $this->assertNotContains($href, $preview_hrefs, $href);
        }

        $login = ['guest' => FALSE, 'logged' => TRUE, 'user_id' => 1, 'roles' => ['login'], 'admin' => FALSE, 'agent' => FALSE, 'preview' => FALSE];
        $login_hrefs = array_column(Service_Prx110_Catalog::search_json($login)['items'], 'href');
        $this->assertNotContains('/employee', $login_hrefs);
        foreach (['/prx560', '/prx395_document_extract', '/inbox', '/prx391_documents', '/analysis', '/sla', '/architecture'] as $href) {
            $this->assertNotContains($href, $login_hrefs);
        }

        // Обычный сотрудник не открывает «в работе» одним query-параметром.
        $login_preview = $login + ['preview' => TRUE];
        $login_preview_hrefs = array_column(Service_Prx110_Catalog::search_json($login_preview)['items'], 'href');
        $this->assertNotContains('/architecture', $login_preview_hrefs);
        $this->assertNotContains('/prx395_document_extract', $login_preview_hrefs);

        $guest = ['guest' => TRUE, 'logged' => FALSE, 'roles' => [], 'admin' => FALSE, 'agent' => FALSE, 'preview' => FALSE];
        $guest_hrefs = array_column(Service_Prx110_Catalog::search_json($guest)['items'], 'href');
        $this->assertNotContains('/employee', $guest_hrefs);
        $this->assertNotContains('/prx560', $guest_hrefs);
        $this->assertSame([], $guest_hrefs);
    }

    public function test_global_search_is_not_in_the_catalog_or_palette(): void
    {
        $search = Service_Prx110_Catalog::get('prx100_search');
        $this->assertNotNull($search);
        foreach ([['login', 'admin'], ['login']] as $roles) {
            $admin = in_array('admin', $roles, TRUE);
            $ctx = ['logged' => TRUE, 'roles' => $roles, 'admin' => $admin, 'agent' => $admin];
            $visible = Service_Prx110_Catalog::visible_modules($ctx);
            $this->assertNotContains('/', array_column($visible, 'href'));
            $this->assertNotContains('prx100_search', array_column($visible, 'module_name'));
            $items = Service_Prx110_Catalog::search_json($ctx)['items'];
            $this->assertNotContains('/', array_column($items, 'href'));
            $this->assertNotContains($search['title'], array_column($items, 'title'));
        }
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
