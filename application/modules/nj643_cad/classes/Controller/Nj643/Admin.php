<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj643_cad: /cad/admin — управление сервисом «CAD-детали».
 *
 * Владелец (27.09.2026): «CAD-движок проверяю… — убрать проверку. Добавить на
 * страницу возможность удаления».
 *
 * Поэтому здесь больше нет кнопки «Проверить движок» и её живого ряда: движок
 * виден по журналу сборок, а лишняя ручная проверка только пугала ожиданием.
 * Зато есть список всех деталей с превью, почтой владельца (по id владельца
 * непонятно, чья деталь) и удалением — и для своих, и для чужих.
 */
class Controller_Nj643_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->_current_user OR ! $this->can('nj643.admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для управления');
        }

        $this->set_title('CAD-детали · управление');
        $this->set_description('Состояние CAD-движка, журнал сборок и расход ИИ по сервису «CAD-детали».');

        $this->set_module_nav([
            ['title' => 'CAD-детали', 'href' => '/cad', 'icon' => 'fas fa-cube'],
        ]);
    }

    public function action_index()
    {
        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check((string) $this->request->post('csrf')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            elseif ((string) $this->request->post('act') === 'delete_part')
            {
                $code = trim((string) $this->request->post('code'));

                if ($code === '')
                {
                    $flash = 'Не выбрана деталь для удаления';
                }
                else
                {
                    $res   = Service_Nj643_Cad::delete_part($code, (int) $this->_current_user->id, TRUE);
                    $flash = isset($res['error']) ? (string) $res['error'] : 'Деталь удалена (файлы убраны с диска)';
                }
            }
            else
            {
                $flash = 'Неизвестное действие';
            }

            HTTP::redirect('/cad/admin?flash='.urlencode($flash), 303);
        }

        $q    = trim((string) $this->request->query('q'));
        $page = max(1, (int) $this->request->query('page'));
        $per  = 20;

        // Выгрузка реестра деталей: тот же адрес с ?export=csv (без нового роута).
        // Нужна для склада, смет и бухгалтерии — «что вообще собирали».
        if ((string) $this->request->query('export') === 'csv')
        {
            return $this->export_csv($q);
        }

        $total = Service_Nj643_Cad::count_parts(0, ['q' => $q, 'all' => TRUE], TRUE);

        $this->template->content = View::factory('643/admin', [
            'stats'      => Service_Nj643_Cad::admin_stats(),
            'parts'      => Service_Nj643_Cad::parts_of(0, ['q' => $q, 'all' => TRUE], $per, ($page - 1) * $per, TRUE),
            'usage'      => Service_Nj643_Cad::usage_report(20),
            'usage_log'  => Service_Nj643_Cad::usage_log(30),
            'runs'       => Service_Nj643_Cad::runs(20),
            'audit'      => Service_Nj643_Cad::audit_log(30),
            'engine'     => Service_Nj643_Cad::engine_state(),
            'daily'      => Service_Nj643_Cad::usage_daily(7),
            'totals'     => Service_Nj643_Cad::usage_totals(),
            'q'          => $q,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($total / $per)),
            'total'      => $total,
            'csrf'       => Security::token(),
            'flash'      => isset($_GET['flash']) ? (string) $_GET['flash'] : '',
        ]);
    }

    /**
     * Реестр деталей в CSV: код, название, владелец, состояние, габарит, объём,
     * файлы, дата. Отдаём с BOM — иначе Excel не понимает UTF-8.
     */
    protected function export_csv($q = '')
    {
        $rows = Service_Nj643_Cad::parts_of(0, ['q' => (string) $q, 'all' => TRUE], 5000, 0, TRUE);

        $this->auto_render = FALSE;

        $out = "\xEF\xBB\xBF";
        $out .= "Код;Название;Владелец;Состояние;Ревизия;Файлов;Габарит, мм;Объём, мм3;Создана\n";

        $cell = function ($v) {
            $v = str_replace(['"', "\r", "\n"], ['""', ' ', ' '], (string) $v);

            return '"'.$v.'"';
        };

        foreach ($rows as $p)
        {
            $report = (isset($p['report']) AND is_array($p['report']))
                ? $p['report'] : json_decode((string) ($p['report'] ?? ''), TRUE);
            $geo  = (is_array($report) AND isset($report['geometry']) AND is_array($report['geometry']))
                ? $report['geometry'] : [];
            $bbox = (isset($geo['bbox']) AND is_array($geo['bbox'])) ? $geo['bbox'] : [];

            $dims = $bbox
                ? round((float) ($bbox[0] ?? 0), 2).'x'.round((float) ($bbox[1] ?? 0), 2).'x'.round((float) ($bbox[2] ?? 0), 2)
                : '';

            $out .= implode(';', [
                $cell($p['code']),
                $cell($p['title']),
                $cell(trim((string) ($p['owner_email'] ?? '')) !== '' ? $p['owner_email'] : ('id '.(int) $p['user_id'])),
                $cell($p['status']),
                (int) $p['revision'],
                (int) $p['files_count'],
                $cell($dims),
                $geo && isset($geo['volume']) ? round((float) $geo['volume'], 2) : '',
                $cell($p['created_at']),
            ])."\n";
        }

        $this->response->headers('Content-Type', 'text/csv; charset=UTF-8')
            ->headers('Content-Disposition', 'attachment; filename="cad-parts.csv"')
            ->headers('X-Robots-Tag', 'noindex')
            ->body($out);
    }
}
