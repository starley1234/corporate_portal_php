<?php
use PHPUnit\Framework\TestCase;

/** Контракт личного Избранного: БД, аудитория и первый HTML. */
class FavoritesTest extends TestCase
{
    private function with_users(callable $test): void
    {
        $db = Database::instance();
        $db->begin();
        try {
            $ids = [];
            for ($i = 0; $i < 2; $i++) {
                $name = 'navtest_'.bin2hex(random_bytes(6));
                $rows = DB::query(Database::SELECT,
                    'INSERT INTO users (username, email, password) VALUES (:name, :email, :password) RETURNING id')
                    ->parameters([':name' => $name, ':email' => $name.'@example.invalid', ':password' => 'not-a-login'])
                    ->execute()->as_array();
                $ids[] = (int) $rows[0]['id'];
            }
            $test($ids[0], $ids[1]);
        } finally {
            $db->rollback();
        }
    }

    private function login_context(): array
    {
        return ['guest' => FALSE, 'logged' => TRUE, 'user_id' => 1, 'roles' => ['login'], 'admin' => FALSE, 'agent' => FALSE];
    }

    private function admin_context(): array
    {
        return ['guest' => FALSE, 'logged' => TRUE, 'user_id' => 1, 'roles' => ['login', 'admin'], 'admin' => TRUE, 'agent' => TRUE];
    }

    public function test_account_isolation_idempotence_and_order(): void
    {
        $ctx = $this->login_context();
        $this->with_users(function ($first, $second) use ($ctx) {
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/account/security', TRUE, $ctx));
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx597', TRUE, $ctx));
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/account/security', TRUE, $ctx));
            $this->assertTrue(Service_Prx110_Favorites::set($second, '/prx597', TRUE, $ctx));
            $this->assertSame(['/account/security', '/prx597'], array_column(Service_Prx110_Favorites::items($first, $ctx), 'href'));
            $this->assertSame(['/prx597'], array_column(Service_Prx110_Favorites::items($second, $ctx), 'href'));
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/account/security', FALSE, $ctx));
            $this->assertSame(['/prx597'], array_column(Service_Prx110_Favorites::items($first, $ctx), 'href'));
            $this->assertSame(['/prx597'], array_column(Service_Prx110_Favorites::items($second, $ctx), 'href'));
            DB::delete('users')->where('id', '=', $first)->execute();
            $this->assertSame([], Service_Prx110_Favorites::items($first, $ctx));
            $this->assertSame(['/prx597'], array_column(Service_Prx110_Favorites::items($second, $ctx), 'href'));
        });
    }

    public function test_visibility_changes_hide_but_do_not_leak_or_delete_favorites(): void
    {
        $login = $this->login_context();
        $admin = $this->admin_context();
        $this->with_users(function ($first) use ($login, $admin) {
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx560', TRUE, $admin));
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/account/security', TRUE, $admin));
            $this->assertSame(['/account/security'], array_column(Service_Prx110_Favorites::items($first, $login), 'href'));
            $this->assertSame(['/prx560', '/account/security'], array_column(Service_Prx110_Favorites::items($first, $admin), 'href'));
            $this->assertFalse(Service_Prx110_Favorites::set($first, '/prx560', TRUE, $login));
            // Чужие/внешние адреса и любые страницы невидимого сервиса (по префиксу
            // /prxNNN… или по href/nav_paths манифеста) закрепить нельзя.
            foreach (['//evil.test', 'https://evil.test', '/employee', '/inbox', '/services', "/account/security\n",
                      '/prx560_knowledge', '/prx560_knowledge/admin', '/prx560_index', '/prx560/anything'] as $href) {
                $this->assertFalse(Service_Prx110_Favorites::set($first, $href, TRUE, $login), $href);
            }
            // Внутренняя страница вне сервисов (звезда в page_header) — можно: доступ проверит маршрут.
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/not-a-service', TRUE, $login));
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/not-a-service', FALSE, $login));
            // Удалить свою невидимую запись можно, но только по своему user_id.
            $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx560', FALSE, $login));
            $this->assertSame(['/account/security'], array_column(Service_Prx110_Favorites::items($first, $admin), 'href'));
        });
    }

    /** Решение о публикации модуля внутри транзакции with_users (откатится). */
    private function publication(string $module, string $state): void
    {
        DB::query(Database::UPDATE, 'INSERT INTO prx_110_publications (module_name, state, note) VALUES (:m, :s, :n)
            ON CONFLICT (module_name) DO UPDATE SET state = EXCLUDED.state')
            ->parameters([':m' => $module, ':s' => $state, ':n' => 'FavoritesTest'])->execute();
        $cache = new ReflectionProperty(Service_Prx110_Publication::class, 'decisions');
        $cache->setValue(NULL, NULL);
    }

    /**
     * Сервис «в работе» (wip, напр. beta prx400) не на витрине, но открыт
     * администратору — его страницы закрепляются (раньше: 422 и «Не удалось
     * подтвердить состояние Избранного»). Без доступа, unpublished и архив — нет.
     */
    public function test_work_in_progress_service_can_be_pinned_by_those_who_can_open_it(): void
    {
        $login = $this->login_context();
        $admin = $this->admin_context();
        try {
            $this->with_users(function ($first) use ($login, $admin) {
                $this->publication('prx400_specflow', 'wip');
                $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx400_specflow', TRUE, $admin));
                $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx400_specflow/spec/5', TRUE, $admin, 'Спецификация №5'));
                $this->assertFalse(Service_Prx110_Favorites::set($first, '/prx400_specflow', TRUE, $login), 'аудитория admin');
                $items = array_column(Service_Prx110_Favorites::items($first, $admin), NULL, 'href');
                $this->assertSame(['/prx400_specflow', '/prx400_specflow/spec/5'], array_keys($items));
                $this->assertSame('Оцифровка спецификаций', $items['/prx400_specflow']['title'], 'метаданные корня — из манифеста');
                $this->assertSame('fas fa-table-list', $items['/prx400_specflow']['icon']);
                $this->assertSame('Спецификация №5', $items['/prx400_specflow/spec/5']['title']);
                $this->assertSame([], Service_Prx110_Favorites::items($first, $login), 'чужой аудитории не видно');

                // Сняли с публикации или отправили в архив — закладки скрыты и не создаются.
                foreach (['unpublished', 'archived'] as $state) {
                    $this->publication('prx400_specflow', $state);
                    $this->assertSame([], Service_Prx110_Favorites::items($first, $admin), $state);
                    $this->assertFalse(Service_Prx110_Favorites::set($first, '/prx400_specflow/spec/6', TRUE, $admin), $state);
                }
                // Одобрили — обычный сервис витрины.
                $this->publication('prx400_specflow', 'approved');
                $this->assertSame(['/prx400_specflow', '/prx400_specflow/spec/5'], array_column(Service_Prx110_Favorites::items($first, $admin), 'href'));
                // Удалить свою запись можно в любом состоянии.
                $this->assertTrue(Service_Prx110_Favorites::set($first, '/prx400_specflow', FALSE, $login));
            });
        } finally {
            (new ReflectionProperty(Service_Prx110_Publication::class, 'decisions'))->setValue(NULL, NULL);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_kicked_session_cannot_read_favorites_or_render_personal_html(): void
    {
        $old_host = $_SERVER['HTTP_HOST'] ?? NULL;
        $old_server_name = $_SERVER['SERVER_NAME'] ?? NULL;
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SERVER_NAME'] = 'localhost';
        try {
            $this->with_users(function ($first) {
                DB::query(Database::UPDATE, "INSERT INTO roles_users (user_id, role_id) SELECT :uid, id FROM roles WHERE name = 'login'")
                    ->param(':uid', $first)->execute();
                $this->assertTrue(Service_Prx110_Favorites::set($first, '/kicked-check/page', TRUE, $this->login_context()));
                $session = Session::instance();
                $original = $session->as_array();
                try {
                    $user = ORM::factory('User', $first);
                    Service_Prx599_Account::logout_everywhere($first);
                    foreach (['prx110/api/favorites' => 401, 'services' => 302] as $uri => $expected) {
                        if (session_status() !== PHP_SESSION_ACTIVE) $session->restart();
                        $session->set(Kohana::$config->load('auth.session_key'), $user);
                        $response = Request::factory($uri)->execute();
                        $this->assertSame($expected, $response->status(), $uri);
                        $this->assertStringNotContainsString('href="/kicked-check/page"', $response->body());
                        if ($expected === 401) $this->assertSame('auth', json_decode($response->body(), TRUE)['error'] ?? NULL);
                    }
                } finally {
                    if (session_status() !== PHP_SESSION_ACTIVE) $session->restart();
                    $data =& $session->as_array();
                    $data = $original;
                }
            });
        } finally {
            if ($old_host === NULL) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $old_host;
            if ($old_server_name === NULL) unset($_SERVER['SERVER_NAME']); else $_SERVER['SERVER_NAME'] = $old_server_name;
        }
    }

    public function test_server_render_escapes_favorite_metadata(): void
    {
        $items = [['href' => '/inbox', 'title' => '<script>alert(1)</script>', 'icon' => 'fas fa-inbox', 'nav_paths' => []]];
        $sidebar = View::factory('partials/sidebar', [
            'site_name' => 'PRX',
            'nav_favorites' => ['status' => 'ready', 'user_id' => 9, 'csrf' => 'csrf-test', 'items' => $items],
        ])->render();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $sidebar);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $sidebar);
        $this->assertStringContainsString('\\u003Cscript\\u003E', $sidebar);
    }

    public function test_server_render_contains_the_active_favorite_without_loading_placeholder(): void
    {
        $items = Service_Prx110_Favorites::mark_active([
            ['href' => '/prx391_documents', 'title' => 'Документы', 'icon' => 'fas fa-folder', 'nav_paths' => []],
            ['href' => '/prx391_documents/settings', 'title' => 'Настройки', 'icon' => 'fas fa-gear', 'nav_paths' => ['/document-settings']],
        ], '/document-settings/42');
        $this->assertFalse($items[0]['active']);
        $this->assertTrue($items[1]['active']);
        $this->assertSame('location', $items[1]['aria_current']);
        $sidebar = View::factory('partials/sidebar', [
            'site_name' => 'PRX',
            'nav_favorites' => ['status' => 'ready', 'user_id' => 9, 'csrf' => 'csrf-test', 'items' => $items],
        ])->render();
        $this->assertStringContainsString('data-nav-status="ready"', $sidebar);
        $this->assertStringContainsString('data-nav-user-id="9"', $sidebar);
        $this->assertStringContainsString('href="/prx391_documents/settings"', $sidebar);
        $this->assertStringContainsString('aria-current="location"', $sidebar);
        $this->assertStringContainsString('Убрать из Избранного: Настройки', $sidebar);
        $this->assertStringNotContainsString('Загрузка Избранного', $sidebar);
    }
}
