<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj607_industry: /nj607/admin — все отчёты «Карты отрасли»: кто и когда
 * запустил, статус; действия (перезапуск/закрыть/удалить) — AJAX (JSON).
 */
class Controller_Nj607_Admin extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        if ( ! $this->can('nj607.admin') AND ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Только для администратора');
        }

        $this->set_title('Карта отрасли · управление');

        Service_Nj607_Industry::ensure_tables();
    }

    public function action_index()
    {
        // AJAX-подмена ОДНОЙ строки (после перезапуска/закрытия в админке):
        // страница не перезагружается, возвращаем только <tr> этой строки.
        if ($this->request->method() === Request::GET
            AND $this->request->query('rows') === '1')
        {
            $this->auto_render = FALSE;
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');

            $code = (string) $this->request->query('code');
            $row  = $code !== '' ? Service_Nj607_Industry::admin_row($code) : NULL;

            // статичные хелперы строки вычислены в шаблоне-источнике; здесь держим
            // локальные копии (idempotent) — View::factory их читает из переменной
            $st = [
                'running' => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
                'done'    => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
                'error'   => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-300',
                'new'     => 'bg-blue-50 text-blue-600 dark:bg-blue-950/60 dark:text-blue-300',
                'closed'  => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400',
            ];
            $dt = function ($v) { return $v ? HTML::chars(Date::formatted_time((string) $v, 'd.m.y H:i')) : '—'; };

            $this->response->body(json_encode([
                'ok'  => $row !== NULL,
                'row' => $row !== NULL
                    ? (string) View::factory('nj607/admin_row', ['r' => $row, 'st' => $st, 'dt' => $dt, 'csrf' => Security::token()])
                    : NULL,
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        $flash = '';

        if ($this->request->method() === Request::POST)
        {
            $flash = 'Сессия устарела — попробуйте ещё раз';

            if (Security::check((string) $this->request->post('csrf')))
            {
                $code = (string) $this->request->post('code');

                switch ((string) $this->request->post('act'))
                {
                    case 'close':
                        $ok = Service_Nj607_Industry::close_order($code);
                        $flash = $ok ? 'Заявка закрыта' : 'Заявка не найдена';
                        break;

                    case 'restart':
                        $res = Service_Nj607_Industry::admin_restart($code);
                        $flash = isset($res['error']) ? $res['error']
                            : 'Сбор запущен заново — задача уже в очереди';
                        $ok = ! isset($res['error']);
                        break;

                    case 'delete':
                        $ok = Service_Nj607_Industry::admin_delete($code);
                        $flash = $ok ? 'Отчёт удалён' : 'Отчёт не найден';
                        break;

                    case 'ontology_backfill':
                        // Разовая операция после миграции 003: разобрать старые
                        // отчёты заново, чтобы блок «Онтология отчёта» наполнился
                        // и у тех отчётов, где компании уже были заведены другими.
                        $res = Service_Nj607_Ontology::backfill(5, (string) $this->request->post('with_links') === '1');
                        $ok = TRUE;
                        $flash = (string) ($res['note'] ?? '');
                        break;

                    default:
                        $ok = FALSE;
                        $flash = 'Неизвестное действие';
                }
            }
            else
            {
                $ok = FALSE;
            }

            // AJAX: JSON вместо перезагрузки
            if ($this->request->post('ajax') === '1'
                OR strtolower((string) $this->request->headers('x-requested-with')) === 'xmlhttprequest')
            {
                $this->auto_render = FALSE;
                $this->response->headers('Content-Type', 'application/json; charset=utf-8')
                    ->body(json_encode(['ok' => (bool) $ok, 'flash' => $flash], JSON_UNESCAPED_UNICODE));

                return;
            }

            Session::instance()->set('nj607_admin_flash', $flash);
            HTTP::redirect('/nj607/admin', 303);
        }

        $this->template->content = View::factory('nj607/admin', [
            'reports' => Service_Nj607_Industry::admin_overview(),
            'jobs'    => Service_Nj120_JobQueue::instance()->channel_jobs('nj607.industry'),
            'flash'   => (string) Session::instance()->get_once('nj607_admin_flash', ''),
            'csrf'    => Security::token(),
        ]);
    }
}
