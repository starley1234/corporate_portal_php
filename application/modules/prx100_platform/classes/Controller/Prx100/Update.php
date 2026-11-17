<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Пинок обновления сайта (bin/deploy.sh --migrate).
 *
 * Два входа:
 *  • /prx100/update           — страница администратора: кнопка + хвост журнала;
 *  • /update/kick/<token>    — для агента/скриптов: GET с токеном
 *                              SITE_UPDATE_TOKEN из .env (hash_equals).
 *
 * deploy.sh сам тянет git, применяет миграции (db:migrate идемпотентен),
 * перезапускает воркер и релоадит fpm; flock не даёт двум копиям
 * пересечься. Крон каждую минуту больше не нужен.
 */
class Controller_Prx100_Update extends Controller_Prx100_Base {

    const KICK_LOG = '/tmp/prx-deploy-kick.log';

    public function before()
    {
        parent::before();

        $this->set_title('Обновление сайта');
    }

    /** GET/POST /prx100/update — страница админа. */
    public function action_index()
    {
        if ( ! Auth::instance()->logged_in('admin'))
        {
            throw HTTP_Exception::factory(403, 'Доступ только для администратора');
        }

        $flash = '';
        $kicked = FALSE;

        if ($this->request->method() === Request::POST)
        {
            if ( ! Security::check((string) Arr::get($_POST, 'csrf', '')))
            {
                $flash = 'Сессия устарела — попробуйте ещё раз';
            }
            else
            {
                $res = self::kick();
                $kicked = ! empty($res['started']);
                $flash = isset($res['error'])
                    ? 'Не удалось запустить: '.$res['error']
                    : 'Обновление запущено: git pull + миграции + перезапуск воркера. Страница обновится сама через ~25 секунд.';
            }
        }

        $this->template->content = View::factory('prx100/update', [
            'flash'  => $flash,
            'kicked' => $kicked,
            'log'    => self::log_tail(),
            'log_path' => self::KICK_LOG,
            'deploy_branch' => self::deploy_branch(),
            'csrf'   => Security::token(),
            'kick_on' => trim((string) getenv('SITE_UPDATE_TOKEN')) !== '',
            'diag'   => self::diag(),
        ]);
    }

    /** GET /update/kick/<token> — пинок по токену (для агента, без логина). */
    public function action_kick()
    {
        $this->auto_render = FALSE;

        $token = trim((string) getenv('SITE_UPDATE_TOKEN'));
        $given = (string) $this->request->param('token');

        if ($token === '' OR $given === '' OR ! hash_equals($token, $given))
        {
            $this->response->status(403);
            $this->response->headers('Content-Type', 'application/json; charset=utf-8');
            $this->response->body(json_encode(['error' => 'bad_token'], JSON_UNESCAPED_UNICODE));

            return;
        }

        $res = self::kick();
        $this->response->headers('Content-Type', 'application/json; charset=utf-8');
        $this->response->body(json_encode(
            isset($res['error']) ? ['error' => $res['error']] : ['ok' => TRUE] + $res,
            JSON_UNESCAPED_UNICODE));
    }

    /**
     * Запустить bin/deploy.sh --migrate в фоне от имени пользователя php-fpm.
     * Лог — в /tmp (у www-data нет прав на /var/log); flock в deploy.sh
     * защищает от параллельного запуска с таймером/другим пинком.
     */
    public static function kick()
    {
        $root = realpath(DOCROOT.'..');

        if ($root === FALSE OR ! is_file($root.'/bin/deploy.sh'))
        {
            return ['error' => 'bin/deploy.sh не найден'];
        }

        $log = self::KICK_LOG;
        $cmd = 'DEPLOY_LOG='.escapeshellarg($log)
             .' nohup bash '.escapeshellarg($root.'/bin/deploy.sh').' --migrate'
             .' >> '.escapeshellarg($log).' 2>&1 & echo $!';

        $out = [];
        exec($cmd, $out, $code);

        if ($code !== 0 OR empty($out[0]))
        {
            return ['error' => 'exec недоступен (disable_functions?)'];
        }

        // деплой из-под php-fpm (www-data) не может сделать systemctl reload fpm:
        // если у opcache выключен validate_timestamps, новый код контроллеров
        // не подхватился бы никогда. Сбрасываем кэш компиляции сами.
        if (function_exists('opcache_reset'))
        {
            @opcache_reset();
        }

        return ['started' => TRUE, 'pid' => (int) $out[0], 'log' => $log];
    }

    /**
     * Диагностика «куда смотрит деплой» (найдено 21.09: deploy.sh тянет main,
     * а работа идёт в ветке arena/… — обновляться было НЕЧЕМУ, отсюда
     * «/prx100/update не обновляет»). exec недоступен — вернём NULL и скроем блок.
     */
    public static function diag()
    {
        $root = realpath(DOCROOT.'..');

        if ($root === FALSE)
        {
            return NULL;
        }

        $run = function ($cmd) use ($root) {
            $out = [];
            @exec('git -C '.escapeshellarg($root).' '.$cmd.' 2>/dev/null', $out);

            return $out[0] ?? '';
        };

        $branch = $run('rev-parse --abbrev-ref HEAD');

        if ($branch === '')
        {
            return NULL; // exec недоступен или не git — блок не показываем
        }

        $local    = $run('rev-parse --short HEAD');
        $main     = $run('rev-parse --short origin/main');
        $ahead    = (int) $run('rev-list --count origin/main..HEAD');
        // Ветка, которую реально тянет deploy.sh (DEPLOY_BRANCH): если сервер
        // стоит на ней, «впереди main» — норма, а важно другое: есть ли в ветке
        // коммиты, которых на сервере ещё нет. Иначе выглядит как «деплой ничего
        // не делает» (владелец, 26.09: правки есть, на сайте старьё).
        $upstream = $run('rev-parse --short origin/'.$branch);
        $pending  = ($upstream !== '' AND $upstream !== $local);

        return [
            'branch'   => $branch,
            'local'    => $local,
            'main'     => $main,
            'ahead'    => $ahead,
            'upstream' => $upstream,
            'pending'  => $pending,
        ];
    }

    /** В какой ветке сейчас работают агенты (для подсказки на странице). */
    public static function deploy_branch()
    {
        $f = '/etc/default/prx-deploy';

        if (is_readable($f) AND preg_match('~^\s*DEPLOY_BRANCH\s*=\s*"?([^"\s]+)~m', (string) file_get_contents($f), $m))
        {
            return $m[1];
        }

        return '';
    }

    /** Хвост журнала пинка (последние 40 строк) — если файл читается. */
    public static function log_tail($lines = 40)
    {
        if ( ! is_file(self::KICK_LOG) OR ! is_readable(self::KICK_LOG))
        {
            return [];
        }

        $all = file(self::KICK_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_slice(is_array($all) ? $all : [], -$lines);
    }
}
