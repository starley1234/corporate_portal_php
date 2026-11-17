<?php
use PHPUnit\Framework\TestCase;

/** Контракты публичной изолированной витрины дизайна prx140. */
class DesignLabTest extends TestCase
{
    public function test_registry_declares_ten_unique_public_variants(): void
    {
        $this->assertArrayHasKey('prx140_design_lab', Kohana::modules());

        $manifests = Manifest::all();
        $this->assertArrayHasKey('prx140_design_lab', $manifests);
        $manifest = $manifests['prx140_design_lab'];
        $this->assertSame(140, $manifest['id']);
        $this->assertSame('/design-lab', $manifest['href']);
        $this->assertSame('public', $manifest['audience']);
        $this->assertSame('beta', $manifest['status']);

        $variants = $this->_variants();
        $this->assertCount(10, $variants);
        $this->assertCount(10, array_unique(Arr::pluck($variants, 'slug')));
        $this->assertCount(10, array_unique(Arr::pluck($variants, 'css')));
        $this->assertSame(
            [
                'corporate', 'industrial', 'swiss', 'product', 'editorial', 'command',
                'sample-classic', 'sample-compact', 'sample-operations', 'required-design-sample',
            ],
            Arr::pluck($variants, 'slug')
        );
        foreach (['aurora', 'sample-workbench', 'sample-reading'] as $removed_slug) {
            $this->assertNotContains($removed_slug, Arr::pluck($variants, 'slug'));
        }
    }

    public function test_controller_is_direct_and_content_is_canonical(): void
    {
        $reflection = new ReflectionClass('Controller_Prx140_DesignLab');
        $this->assertSame('Controller', $reflection->getParentClass()->getName());
        $this->assertFalse($reflection->isSubclassOf('Controller_Base'));

        $source = file_get_contents(APPPATH.'modules/prx140_design_lab/classes/Controller/Prx140/DesignLab.php');
        foreach (['Auth::', 'Session::', 'Database::', 'ORM::', '$this->template'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $content = (new Service_Prx140_DesignLab())->content();
        $this->assertSame('Анна Соколова', $content['person']['name']);
        $this->assertCount(7, $content['navigation']);
        $this->assertCount(4, $content['metrics']);
        $this->assertCount(7, $content['domains']);
        $this->assertCount(8, $content['services']);
        $this->assertCount(4, $content['tasks']);
        $this->assertCount(4, $content['people']);
        $this->assertCount(3, $content['news']);
        $this->assertCount(3, $content['events']);
        $this->assertCount(4, $content['knowledge']);
        $this->assertCount(3, $content['notifications']);
        $this->assertCount(4, $content['integrations']);
        $this->assertCount(4, $content['analytics']);
        $this->assertSame('home', $content['navigation'][0]['icon']);
        $this->assertSame('users', $content['domains'][0]['icon']);
        $this->assertSame('users', $content['services'][0]['icon']);
        $this->assertSame('plug', $content['services'][7]['icon']);
    }

    public function test_each_variant_renders_same_content_with_own_css_and_themes(): void
    {
        $content = (new Service_Prx140_DesignLab())->content();
        $canonical = [
            'Добрый день, Анна. Всё важное для работы — в одном месте.',
            'Семь доменов корпоративных сервисов',
            'Часто используемые сервисы',
            'Мои задачи и согласования',
            'Команда и эксперты',
            'Новости компании',
            'Документы и обучение',
            'Показатели портала',
            'Состояние интеграций',
            'Нужна помощь или новый доступ?',
        ];
        foreach (['domains', 'services', 'tasks', 'people', 'news', 'knowledge'] as $section) {
            foreach ($content[$section] as $item) {
                $canonical[] = $item['title'] ?? $item['name'];
            }
        }

        foreach ($this->_variants() as $variant) {
            $slug = $variant['slug'];
            $response = Request::factory('design-lab/'.$slug)
                ->query(['theme' => 'dark', 'company' => 'orange'])
                ->execute();
            $body = (string) $response->body();

            $this->assertSame(200, $response->status(), $slug);
            $this->assertStringContainsString('no-store', $response->headers('Cache-Control'), $slug);
            $this->assertStringContainsString('no-cache', $response->headers('Pragma'), $slug);
            $this->assertNotSame('', (string) $response->headers('X-PRX-Design-Lab-Revision'), $slug);
            $this->assertStringContainsString('data-theme="dark"', $body, $slug);
            $this->assertStringContainsString('data-company-accent="orange"', $body, $slug);
            $this->assertStringContainsString('data-variant="'.$slug.'"', $body, $slug);
            $this->assertStringContainsString('data-design-lab-css="'.$slug.'"', $body, $slug);
            $this->assertStringContainsString('data-theme-choice="light"', $body, $slug);
            $this->assertStringContainsString('data-theme-choice="dark"', $body, $slug);
            $this->assertStringContainsString('data-company-choice="orange"', $body, $slug);
            $this->assertStringContainsString('data-company-choice="blue"', $body, $slug);
            $this->assertStringContainsString('data-company-choice="yellow"', $body, $slug);
            $this->assertStringContainsString('class="dl-menu-button"', $body, $slug);
            $this->assertStringContainsString('?v=', $body, $slug);
            $this->assertStringContainsString('&amp;theme=dark', $body, $slug);
            $this->assertStringContainsString('&amp;company=orange', $body, $slug);
            $this->assertStringNotContainsString('<link', $body, $slug);
            $this->assertStringNotContainsString('template_tailwindcss', $body, $slug);
            $this->assertStringNotContainsString('prx-main', $body, $slug);

            foreach ($canonical as $text) {
                $this->assertStringContainsString($text, $body, $slug.': '.$text);
            }

            if ($slug === 'required-design-sample') {
                $this->assertStringContainsString('id="dl-icon-home"', $body, $slug);
                $this->assertStringContainsString('id="dl-icon-search"', $body, $slug);
                $this->assertStringContainsString('id="dl-icon-bell"', $body, $slug);
                $this->assertStringContainsString('<use href="#dl-icon-users"></use>', $body, $slug);
                $this->assertGreaterThanOrEqual(40, substr_count($body, 'class="dl-icon"'), $slug);
                $this->assertStringNotContainsString('fontawesome', strtolower($body), $slug);
            } else {
                $this->assertStringNotContainsString('class="dl-icon"', $body, $slug);
            }
        }
    }

    public function test_landing_theme_validation_and_unknown_variant(): void
    {
        $landing = Request::factory('design-lab')->execute();
        $body = (string) $landing->body();
        $this->assertSame(200, $landing->status());
        $this->assertStringContainsString('Выберите характер корпоративного портала PRX', $body);
        foreach ($this->_variants() as $variant) {
            $this->assertStringContainsString('/design-lab/'.$variant['slug'].'?v=', $body);
        }

        $light = Request::factory('design-lab/corporate')
            ->query(['theme' => 'light', 'company' => 'yellow'])
            ->execute();
        $light_body = (string) $light->body();
        $this->assertStringContainsString('data-theme="light"', $light_body);
        $this->assertStringContainsString('data-company-accent="yellow"', $light_body);

        $invalid_theme = Request::factory('design-lab/corporate')
            ->query(['theme' => '"><script>alert(1)</script>', 'company' => '"><script>alert(2)</script>'])
            ->execute();
        $invalid_body = (string) $invalid_theme->body();
        $this->assertSame(200, $invalid_theme->status());
        $this->assertStringContainsString('data-theme=""', $invalid_body);
        $this->assertStringContainsString('data-company-accent=""', $invalid_body);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $invalid_body);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $invalid_body);

        foreach (['aurora', 'sample-workbench', 'sample-reading'] as $removed_slug) {
            $removed = Request::factory('design-lab/'.$removed_slug)->execute();
            $this->assertSame(404, $removed->status(), $removed_slug);
        }

        $missing = Request::factory('design-lab/not-a-design')->execute();
        $this->assertSame(404, $missing->status());
        $this->assertStringContainsString('Такого варианта дизайна нет', (string) $missing->body());
        $this->assertStringContainsString('no-store', $missing->headers('Cache-Control'));
    }

    public function test_css_files_are_standalone_responsive_and_dark_ready(): void
    {
        $base = APPPATH.'modules/prx140_design_lab/assets/design-lab/';
        $files = ['landing.css' => file_get_contents($base.'landing.css')];
        foreach ($this->_variants() as $variant) {
            $files[$variant['css']] = file_get_contents($base.$variant['css']);
        }

        $hashes = [];
        foreach ($files as $name => $css) {
            $this->assertTrue(is_string($css), $name);
            $this->assertGreaterThan(8000, strlen($css), $name);
            $hashes[] = hash('sha256', $css);
            $this->assertStringContainsString('html[data-theme="dark"]', $css, $name);
            $this->assertStringContainsString('.dl-company-switch', $css, $name);
            $this->assertStringContainsString('html[data-company-accent="orange"]', $css, $name);
            $this->assertStringContainsString('html[data-company-accent="blue"]', $css, $name);
            $this->assertStringContainsString('html[data-company-accent="yellow"]', $css, $name);
            $this->assertStringContainsString('@media (max-width: 760px)', $css, $name);
            $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css, $name);
            $this->assertStringNotContainsString('@import', $css, $name);
            $this->assertStringNotContainsString('http://', $css, $name);
            $this->assertStringNotContainsString('https://', $css, $name);
            $this->assertSame(substr_count($css, '{'), substr_count($css, '}'), $name);
        }
        $this->assertCount(count($files), array_unique($hashes));

        foreach ($this->_variants() as $variant) {
            $css = $files[$variant['css']];
            foreach ([
                '.dl-labbar',
                '.dl-shell',
                '.dl-sidebar',
                '.dl-hero',
                '.dl-service-grid',
                '.dl-task-row',
                '.dl-people-grid',
                '.dl-meter > span',
                '.dl-support-form',
                'html[data-menu-open="true"] .dl-primary-nav',
                '@media (max-width: 520px)',
            ] as $selector) {
                $this->assertStringContainsString($selector, $css, $variant['css'].': '.$selector);
            }
        }

        // Владелец отдельно попросил не раздувать H1 в этих трёх направлениях.
        $this->assertStringNotContainsString('clamp(2.7rem, 7vw, 6.2rem)', $files['swiss.css']);
        $this->assertStringNotContainsString('clamp(2.2rem, 4.7vw, 4.3rem)', $files['product.css']);
        $this->assertStringNotContainsString('clamp(2.7rem, 6vw, 5.4rem)', $files['editorial.css']);
        foreach (['sample-classic.css', 'sample-compact.css', 'sample-operations.css', 'required-design-sample.css'] as $sample_css) {
            $this->assertStringContainsString('Финальный предохранитель шкалы эталона', $files[$sample_css], $sample_css);
            $this->assertMatchesRegularExpression('/\.dl-hero h1 \{ font-size: 1\.(7|875)rem; \}/', $files[$sample_css], $sample_css);
        }

        $exact_css = $files['required-design-sample.css'];
        foreach ([
            '.dl-icon',
            '.dl-nav-label .dl-icon',
            '--exact-control: 44px',
            'grid-template-columns: 264px minmax(0, 1fr)',
            'min-height: 64px',
            'font-size: 30px',
            'font-size: 22px',
            'font-size: 18px',
            'Точный слой required_design_sample',
        ] as $exact_contract) {
            $this->assertStringContainsString($exact_contract, $exact_css, $exact_contract);
        }
    }

    protected function _variants(): array
    {
        $config = Kohana::$config->load('prx140_design_lab')->as_array();
        $this->assertArrayHasKey('variants', $config);
        return $config['variants'];
    }
}
