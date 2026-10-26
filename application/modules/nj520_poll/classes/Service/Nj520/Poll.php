<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Быстрые опросы (модуль NJ-520, бренд «Ask 2 Vote»).
 *
 * Создал опрос за 10 секунд → поделился ссылкой /p/<code> → люди голосуют →
 * живые результаты.
 *
 * Один голос — с одного БРАУЗЕРА (решение владельца 04.10.2026). Ключ
 * голосующего — `Visitor::uid()`: `u:<id>` у залогиненных, `g:<ubid>` у
 * анонимов (постоянная кука платформы). До этого считали по IP, и целый офис
 * или подъезд за одним NAT голосовал ОДИН раз — а в соседней вкладке режима
 * инкогнито голос всё равно проходил. IP остался только запасным ключом
 * (`ip:<адрес>`) для клиентов, которым куку поставить не удалось.
 *
 * Стиль БД — как в Service_Nj120_JobQueue: Database::instance(), quote(),
 * INSERT..RETURNING через Database::SELECT. Никаких ORM из других модулей.
 */
class Service_Nj520_Poll {

    /** Алфавит кода без похожих символов (0O1lI) */
    const CODE_ALPHABET = '123456789abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    const CODE_LEN = 6;

    const MAX_TITLE = 300;
    const MAX_OPTIONS = 10;
    const MAX_OPTION_LEN = 200;

    /** Потолок ключа голосующего = ширина колонки voter_key */
    const MAX_VOTER_KEY = 64;

    protected static function _db()
    {
        static $db = NULL;

        return $db !== NULL ? $db : $db = Database::instance();
    }

    /** Экранированное значение для подстановки в SQL. */
    protected static function _q($value)
    {
        return self::_db()->quote($value);
    }

    /**
     * Одна строка или NULL.
     *
     * В драйвере PostgreSQL пустой `current()` — это FALSE, а не NULL
     * (правило платформы): нормализуем здесь, чтобы вызывающий код писал
     * `if ($row === NULL)` и не ловил TypeError на несуществующем коде.
     */
    protected static function _one($sql)
    {
        $row = self::_db()->query(Database::SELECT, $sql, FALSE)->current();

        return $row ? (array) $row : NULL;
    }

    /** Все строки запроса. */
    protected static function _all($sql)
    {
        return self::_db()->query(Database::SELECT, $sql, FALSE)->as_array();
    }

    /** Запрос без результата (INSERT/UPDATE/DELETE). */
    protected static function _exec($type, $sql)
    {
        return self::_db()->query($type, $sql, FALSE);
    }

    /** Случайный код без модульного смещения. */
    protected static function _code()
    {
        $alpha = self::CODE_ALPHABET;
        $max = strlen($alpha);
        $code = '';

        for ($i = 0; $i < self::CODE_LEN; $i++)
        {
            $code .= $alpha[random_int(0, $max - 1)];
        }

        return $code;
    }

    /**
     * IP клиента. За nginx REMOTE_ADDR = 127.0.0.1 — тогда берём первый
     * адрес из X-Forwarded-For (заголовку можно верить только когда прямой
     * пир — локальный/приватный, т.е. наш прокси; прямые клиенты с публичным
     * IP подделать его не могут).
     *
     * Для учёта голосов это запасной путь: основной ключ — браузер.
     */
    public static function client_ip()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        $xff    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

        if ($xff !== '' AND ($remote === '' OR filter_var($remote, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === FALSE))
        {
            return trim((string) strtok($xff, ','));
        }

        return $remote;
    }

    /**
     * Ключ голосующего: браузер (или учётная запись), а не адрес сети.
     *
     * `Visitor::uid()` даёт `u:<id>` залогиненному и `g:<ubid>` анониму —
     * общая кука платформы, которая уже стоит у посетителя и живёт год.
     * Когда куку поставить не удалось (робот, запрет кук), `uid()` вернёт
     * `anon` — тогда в ключ идёт IP, чтобы такой клиент не голосовал
     * бесконечно. Ставить куку нужно ДО вывода тела ответа, поэтому метод
     * зовётся из контроллера.
     *
     * @return string
     */
    public static function voter_key()
    {
        $uid = class_exists('Visitor') ? (string) Visitor::uid() : 'anon';

        if ($uid !== '' AND $uid !== 'anon')
        {
            return mb_substr($uid, 0, self::MAX_VOTER_KEY);
        }

        $ip = trim((string) self::client_ip());

        return mb_substr('ip:'.($ip !== '' ? $ip : 'unknown'), 0, self::MAX_VOTER_KEY);
    }

    /**
     * Как показать ключ голосующего человеку (страница управления).
     *
     * Ключ — это не имя и не адрес: `g:<ubid>` показываем коротким хвостом,
     * чтобы строки списка различались, но из них нельзя было собрать
     * «профиль» (приватность — заявленная черта сервиса).
     */
    public static function voter_label($key)
    {
        $key = (string) $key;

        if (strpos($key, 'u:') === 0)
        {
            return 'пользователь #'.(int) substr($key, 2);
        }

        if (strpos($key, 'g:') === 0)
        {
            return 'браузер '.substr($key, 2, 6);
        }

        if (strpos($key, 'ip:') === 0)
        {
            return 'IP '.substr($key, 3);
        }

        return $key === '' ? '—' : $key;
    }

    /**
     * Склонение слова «голос»: 1 голос, 2 голоса, 11 голосов.
     *
     * Жило тремя копиями в трёх вьюхах (и в каждой чуть по-своему) —
     * теперь одно место и тест.
     */
    public static function votes_word($n)
    {
        return self::plural((int) $n, ['голос', 'голоса', 'голосов']);
    }

    /** Склонение слова «вариант». */
    public static function options_word($n)
    {
        return self::plural((int) $n, ['вариант', 'варианта', 'вариантов']);
    }

    /**
     * Русское склонение по числу: [один, два, пять].
     */
    public static function plural($n, array $forms)
    {
        $n = abs((int) $n);
        $n100 = $n % 100;
        $n10 = $n % 10;

        if ($n100 >= 11 AND $n100 <= 14)
        {
            return $forms[2];
        }

        if ($n10 === 1)
        {
            return $forms[0];
        }

        return ($n10 >= 2 AND $n10 <= 4) ? $forms[1] : $forms[2];
    }

    /**
     * Доля варианта в процентах (одна десятая, как на странице).
     *
     * Ноль голосов — ровно 0.0, а не «0,0 от нуля»: делить не на что.
     */
    public static function percent($votes, $total)
    {
        $total = (int) $total;

        return $total > 0 ? round((int) $votes * 100 / $total, 1) : 0.0;
    }

    /**
     * Нормализовать вход создания: [title, options] или NULL.
     *
     * @param  string $title
     * @param  array  $option_lines варианты (по строке на вариант)
     * @return array|NULL
     */
    public static function normalize($title, array $option_lines)
    {
        $title = trim((string) $title);

        if ($title === '' OR mb_strlen($title) > self::MAX_TITLE)
        {
            return NULL;
        }

        $options = [];
        $seen = [];

        foreach ($option_lines as $line)
        {
            $opt = trim((string) $line);

            if ($opt === '')
            {
                continue;
            }

            if (mb_strlen($opt) > self::MAX_OPTION_LEN)
            {
                $opt = mb_substr($opt, 0, self::MAX_OPTION_LEN);
            }

            $key = mb_strtolower($opt);

            if (isset($seen[$key]))
            {
                continue; // дубликаты схлопываем
            }

            $seen[$key] = TRUE;
            $options[] = $opt;

            if (count($options) > self::MAX_OPTIONS)
            {
                return NULL;
            }
        }

        return count($options) >= 2 ? ['title' => $title, 'options' => $options] : NULL;
    }

    /**
     * Создать опрос. Возвращает код или NULL.
     */
    public static function create($title, array $option_lines)
    {
        $norm = self::normalize($title, $option_lines);

        if ($norm === NULL)
        {
            return NULL;
        }

        for ($try = 0; $try < 5; $try++)
        {
            $code = self::_code();

            if (self::_one('SELECT 1 AS x FROM nj_520_polls WHERE code = '.self::_q($code)) !== NULL)
            {
                continue;
            }

            // INSERT .. RETURNING — это Database::SELECT (правило платформы):
            // отдельным запросом за id ходить незачем.
            $row = self::_one('INSERT INTO nj_520_polls (code, title) VALUES ('
                .self::_q($code).', '.self::_q($norm['title']).') RETURNING id');

            if ($row === NULL)
            {
                return NULL;
            }

            $values = [];
            $pos = 0;

            foreach ($norm['options'] as $opt)
            {
                $values[] = '('.(int) $row['id'].', '.self::_q($opt).', '.(++$pos).')';
            }

            self::_exec(Database::INSERT,
                'INSERT INTO nj_520_options (poll_id, label, pos) VALUES '.implode(', ', $values));

            return $code;
        }

        return NULL;
    }

    /** Опрос по коду или NULL. */
    public static function by_code($code)
    {
        return self::_one('SELECT id, code, title, created_at, closed_at
                             FROM nj_520_polls WHERE code = '.self::_q((string) $code));
    }

    /** Голосовал ли уже этот браузер: id выбранного варианта или 0. */
    public static function my_vote($poll_id, $voter_key)
    {
        $row = self::_one('SELECT option_id FROM nj_520_votes
                            WHERE poll_id = '.(int) $poll_id.'
                              AND voter_key = '.self::_q((string) $voter_key).' LIMIT 1');

        return $row === NULL ? 0 : (int) $row['option_id'];
    }

    /** Голосовал ли уже этот браузер. */
    public static function has_voted($poll_id, $voter_key)
    {
        return self::my_vote($poll_id, $voter_key) > 0;
    }

    /**
     * Проголосовать. 'ok' | 'already' | 'bad_option' | 'closed'.
     */
    public static function vote($poll_id, $option_id, $voter_key)
    {
        // Закрытый опрос голоса не принимает
        $poll = self::_one('SELECT closed_at FROM nj_520_polls WHERE id = '.(int) $poll_id);

        if ($poll !== NULL AND ! empty($poll['closed_at']))
        {
            return 'closed';
        }

        $opt = self::_one('SELECT id FROM nj_520_options
                            WHERE id = '.(int) $option_id.' AND poll_id = '.(int) $poll_id);

        if ($opt === NULL)
        {
            return 'bad_option';
        }

        // Защита от второго голоса — на уровне записи: уникальный индекс по
        // паре «опрос + браузер». Проверка SELECT'ом гонку не ловит.
        $row = self::_one('INSERT INTO nj_520_votes (poll_id, option_id, voter_key) VALUES ('
            .(int) $poll_id.', '.(int) $option_id.', '.self::_q((string) $voter_key).') '
            .'ON CONFLICT (poll_id, voter_key) DO NOTHING RETURNING id');

        return $row === NULL ? 'already' : 'ok';
    }

    /**
     * Показывать ли этому посетителю цифры результатов.
     *
     * Пока человек не проголосовал, распределение прячем: увидев «за первый
     * вариант 80%», голосуют иначе — это называется эффектом присоединения, и
     * опрос перестаёт мерить то, ради чего затевался (просьба владельца
     * 04.10.2026). Закрытый опрос показываем всем: голосовать уже нечем, а
     * итоги — это и есть смысл ссылки, которую пересылают после опроса.
     *
     * Общее число проголосовавших остаётся видимым всегда: оно говорит
     * «тут уже 12 человек», но не подсказывает, за что голосовать.
     *
     * @param  array $poll      строка опроса (нужен closed_at)
     * @param  bool  $has_voted голосовал ли этот браузер
     * @return bool
     */
    public static function results_visible(array $poll, $has_voted)
    {
        return ((bool) $has_voted OR ! empty($poll['closed_at']));
    }

    /**
     * Результаты: ['poll'=>row, 'options'=>[['id','label','votes','percent']], 'total'=>int, 'leader'=>int].
     *
     * `leader` — id варианта с наибольшим числом голосов (0, если голосов нет
     * или впереди несколько сразу: «лидирует» про ничью не говорят).
     */
    public static function results($code)
    {
        $poll = self::by_code($code);

        if ($poll === NULL)
        {
            return NULL;
        }

        $rows = self::_all('SELECT o.id, o.label, o.pos, COUNT(v.id) AS votes
                              FROM nj_520_options o
                              LEFT JOIN nj_520_votes v ON v.option_id = o.id
                             WHERE o.poll_id = '.(int) $poll['id'].'
                             GROUP BY o.id, o.label, o.pos ORDER BY o.pos, o.id');

        $total = 0;
        $options = [];

        foreach ($rows as $row)
        {
            $votes = (int) $row['votes'];
            $total += $votes;
            $options[] = ['id' => (int) $row['id'], 'label' => $row['label'], 'votes' => $votes];
        }

        $best = $options ? max(array_column($options, 'votes')) : 0;
        $leaders = 0;

        foreach ($options as &$opt)
        {
            $opt['percent'] = self::percent($opt['votes'], $total);
            $leaders += ($opt['votes'] === $best AND $best > 0) ? 1 : 0;
        }
        unset($opt);

        $leader = 0;

        foreach ($options as $opt)
        {
            if ($leaders === 1 AND $opt['votes'] === $best AND $best > 0)
            {
                $leader = $opt['id'];
            }
        }

        return ['poll' => $poll, 'options' => $options, 'total' => $total, 'leader' => $leader];
    }

    /**
     * Последние N действующих (не закрытых) опросов — для блока на /poll.
     * $except_code — код текущего опроса (исключить из списка).
     */
    public static function recent_active($limit = 5, $except_code = NULL)
    {
        $sql = 'SELECT p.id, p.code, p.title, p.created_at,
                (SELECT COUNT(*) FROM nj_520_votes v WHERE v.poll_id = p.id) AS votes,
                (SELECT COUNT(*) FROM nj_520_options o WHERE o.poll_id = p.id) AS options_cnt
                FROM nj_520_polls p WHERE p.closed_at IS NULL';

        if ($except_code !== NULL)
        {
            $sql .= ' AND p.code <> '.self::_q((string) $except_code);
        }

        return self::_all($sql.' ORDER BY p.id DESC LIMIT '.max(1, (int) $limit));
    }

    /* ==================== Управление (только роль admin) ==================== */

    /** Список опросов (управление): с числом голосов. */
    public static function admin_list($limit = 50)
    {
        return self::_all('SELECT p.id, p.code, p.title, p.created_at, p.closed_at,
                    (SELECT COUNT(*) FROM nj_520_votes v WHERE v.poll_id = p.id) AS votes,
                    (SELECT COUNT(*) FROM nj_520_options o WHERE o.poll_id = p.id) AS options_cnt
             FROM nj_520_polls p ORDER BY p.id DESC LIMIT '.max(1, (int) $limit));
    }

    /** Опрос (управление): варианты, голоса и кто голосовал. */
    public static function admin_poll($code)
    {
        $results = self::results($code);

        if ($results === NULL)
        {
            return NULL;
        }

        $voters = self::_all('SELECT v.voter_key, v.voter_ip, v.created_at, o.label
             FROM nj_520_votes v JOIN nj_520_options o ON o.id = v.option_id
             WHERE v.poll_id = '.(int) $results['poll']['id'].' ORDER BY v.id DESC LIMIT 100');

        foreach ($voters as &$voter)
        {
            $voter['who'] = self::voter_label($voter['voter_key']);
        }
        unset($voter);

        return $results + ['voters' => $voters];
    }

    /** Закрыть опрос (голосование прекращается) или открыть снова. */
    public static function admin_set_closed($poll_id, $closed)
    {
        self::_exec(Database::UPDATE, 'UPDATE nj_520_polls SET closed_at = '.($closed ? 'now()' : 'NULL')
            .' WHERE id = '.(int) $poll_id);

        return ['ok' => TRUE];
    }

    /** Удалить опрос (варианты и голоса уйдут каскадом). */
    public static function admin_delete($poll_id)
    {
        self::_exec(Database::DELETE, 'DELETE FROM nj_520_polls WHERE id = '.(int) $poll_id);

        return ['ok' => TRUE];
    }
}
