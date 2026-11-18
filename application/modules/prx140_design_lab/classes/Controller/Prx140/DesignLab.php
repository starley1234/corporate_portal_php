<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичная изолированная витрина дизайна.
 *
 * Намеренно наследуется от базового Controller, а не Controller_Base:
 * здесь нет Auth, сессии, БД, общего template_tailwindcss.php и общего CSS.
 */
class Controller_Prx140_DesignLab extends Controller
{
    public $auto_render = false;

    public function action_index()
    {
        $theme = $this->_theme();
        $company = $this->_company_accent();
        $variants = [];
        foreach ($this->_variants() as $variant) {
            $variant['href'] = $this->_url('design-lab/' . $variant['slug'], $theme, $company);
            $variants[] = $variant;
        }

        $view = View::factory('prx140_design_lab/landing')
            ->set('variants', $variants)
            ->set('variant_count', count($variants))
            ->set('theme', $theme)
            ->set('company_accent', $company)
            ->set('company_options', $this->_company_options('design-lab', $theme, $company))
            ->set('revision', $this->_revision())
            ->set('css', $this->_css('landing.css'))
            ->set('theme_urls', [
                'light' => $this->_url('design-lab', 'light', $company),
                'dark' => $this->_url('design-lab', 'dark', $company),
            ]);

        return $this->_html($view, 200);
    }

    public function action_variant()
    {
        $slug = strtolower((string) $this->request->param('variant', ''));
        $variants = $this->_variants();
        $current = null;
        $current_index = null;

        foreach ($variants as $index => $variant) {
            if ($variant['slug'] === $slug) {
                $current = $variant;
                $current_index = $index;
                break;
            }
        }

        if ($current === null) {
            return $this->_not_found();
        }

        $theme = $this->_theme();
        $company = $this->_company_accent();
        $switch_variants = [];
        foreach ($variants as $variant) {
            $variant['href'] = $this->_url('design-lab/' . $variant['slug'], $theme, $company);
            $switch_variants[] = $variant;
        }

        $current['href'] = $this->_url('design-lab/' . $current['slug'], $theme, $company);
        $previous = $variants[($current_index - 1 + count($variants)) % count($variants)];
        $next = $variants[($current_index + 1) % count($variants)];
        $previous['href'] = $this->_url('design-lab/' . $previous['slug'], $theme, $company);
        $next['href'] = $this->_url('design-lab/' . $next['slug'], $theme, $company);

        $view = View::factory('prx140_design_lab/show')
            ->set('variant', $current)
            ->set('variants', $switch_variants)
            ->set('variant_count', count($variants))
            ->set('previous', $previous)
            ->set('next', $next)
            ->set('content', (new Service_Prx140_DesignLab())->content())
            ->set('theme', $theme)
            ->set('company_accent', $company)
            ->set('company_options', $this->_company_options('design-lab/' . $current['slug'], $theme, $company))
            ->set('revision', $this->_revision())
            // Композиция двух локальных файлов остаётся одним автономным
            // inline CSS: общий эталон не копируется в вариант навигации.
            ->set('css', (empty($current['base_css']) ? '' : $this->_css($current['base_css']) . "\n") . $this->_css($current['css']))
            ->set('home_url', $this->_url('design-lab', $theme, $company))
            ->set('theme_urls', [
                'light' => $this->_url('design-lab/' . $current['slug'], 'light', $company),
                'dark' => $this->_url('design-lab/' . $current['slug'], 'dark', $company),
            ]);

        return $this->_html($view, 200);
    }

    protected function _not_found()
    {
        $theme = $this->_theme();
        $company = $this->_company_accent();
        $variants = [];
        foreach ($this->_variants() as $variant) {
            $variant['href'] = $this->_url('design-lab/' . $variant['slug'], $theme, $company);
            $variants[] = $variant;
        }

        $view = View::factory('prx140_design_lab/not_found')
            ->set('variants', $variants)
            ->set('variant_count', count($variants))
            ->set('theme', $theme)
            ->set('company_accent', $company)
            ->set('revision', $this->_revision())
            ->set('css', $this->_css('landing.css'))
            ->set('home_url', $this->_url('design-lab', $theme, $company));

        return $this->_html($view, 404);
    }

    protected function _html($view, $status)
    {
        $this->response->status((int) $status);
        $this->response->headers([
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'self'",
            'X-PRX-Design-Lab-Revision' => $this->_revision(),
        ]);
        $this->response->body((string) $view->render());

        return $this->response;
    }

    protected function _variants()
    {
        $config = Kohana::$config->load('prx140_design_lab')->as_array();
        $variants = (isset($config['variants']) && is_array($config['variants'])) ? $config['variants'] : [];
        $result = [];

        foreach ($variants as $variant) {
            $slug = isset($variant['slug']) ? (string) $variant['slug'] : '';
            $css = isset($variant['css']) ? basename((string) $variant['css']) : '';
            if (preg_match('/^[a-z][a-z0-9-]*$/', $slug) !== 1 || $css === '') {
                continue;
            }
            $variant['slug'] = $slug;
            $variant['css'] = $css;
            if ( ! empty($variant['base_css'])) {
                $variant['base_css'] = basename((string) $variant['base_css']);
            }
            $result[] = $variant;
        }

        return $result;
    }

    protected function _theme()
    {
        $theme = strtolower(trim((string) $this->request->query('theme')));
        return ($theme === 'light' || $theme === 'dark') ? $theme : '';
    }

    protected function _company_accent()
    {
        $company = strtolower(trim((string) $this->request->query('company')));
        return in_array($company, ['orange', 'blue', 'yellow'], true) ? $company : '';
    }

    protected function _company_options($path, $theme, $current)
    {
        $labels = [
            'orange' => 'Оранжевый',
            'blue' => 'Синий',
            'yellow' => 'Жёлтый',
        ];
        $options = [];
        foreach ($labels as $slug => $title) {
            $options[] = [
                'slug' => $slug,
                'title' => $title,
                'href' => $this->_url($path, $theme, $slug),
                'active' => ($current === $slug || ($current === '' && $slug === 'blue')),
            ];
        }
        return $options;
    }

    protected function _url($path, $theme = '', $company = '')
    {
        $query = ['v' => $this->_revision()];
        if ($theme === 'light' || $theme === 'dark') {
            $query['theme'] = $theme;
        }
        if (in_array($company, ['orange', 'blue', 'yellow'], true)) {
            $query['company'] = $company;
        }
        return URL::site($path) . '?' . http_build_query($query);
    }

    protected function _css($filename)
    {
        $path = APPPATH . 'modules/prx140_design_lab/assets/design-lab/' . basename((string) $filename);
        $css = is_file($path) ? file_get_contents($path) : false;
        if ($css === false) {
            throw new HTTP_Exception_500('Design lab stylesheet is unavailable.');
        }
        return (string) $css;
    }

    protected function _revision()
    {
        static $revision = null;
        if ($revision !== null) {
            return $revision;
        }

        $base = APPPATH . 'modules/prx140_design_lab/';
        $files = [
            $base . 'config/prx140_design_lab.php',
            $base . 'classes/Controller/Prx140/DesignLab.php',
            $base . 'classes/Service/Prx140/DesignLab.php',
            $base . 'views/prx140_design_lab/landing.php',
            $base . 'views/prx140_design_lab/show.php',
            $base . 'views/prx140_design_lab/not_found.php',
            $base . 'assets/design-lab/landing.css',
        ];
        foreach ($this->_variants() as $variant) {
            if ( ! empty($variant['base_css'])) {
                $files[] = $base . 'assets/design-lab/' . $variant['base_css'];
            }
            $files[] = $base . 'assets/design-lab/' . $variant['css'];
        }

        $parts = [];
        foreach ($files as $file) {
            $hash = is_file($file) ? hash_file('sha256', $file) : 'missing';
            $parts[] = basename($file) . ':' . (string) $hash;
        }

        $revision = substr(hash('sha256', implode('|', $parts)), 0, 16);
        return $revision;
    }
}
