<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-645 Lab — чистая логика «Лаборатории LLM».
 *
 * Здесь нет ни одного запроса к базе: сюда приходят данные (словарь из корпуса,
 * строки n-грамм), а наружу уходят токены, распределения и оценки. Поэтому
 * сервис целиком проверяется юнит-тестом — см. tests/Unit/LlmlabServiceTest.php.
 *
 * Что важно понимать про демонстрации:
 *   * токенизация — МИНИ-BPE по нашему словарю (не токенизатор реальной
 *     модели): он показывает принцип, а точные цифры для чужой модели
 *     считаются отдельной оценкой `estimate_tokens()`;
 *   * «следующий токен» — n-граммная модель, а не нейросеть. Принципы те же
 *     (распределение, температура, top-k/top-p), но всё объяснимо и работает
 *     без внешнего ИИ;
 *   * эмбеддинги — локальный хеш символьных n-грамм; если на платформе
 *     настроен сервер эмбеддингов, модуль предпочитает его.
 */
class Service_Nj645_Lab
{
    /** @var array|NULL конфиг модели токенизации */
    protected static $_cfg = NULL;

    protected static function _cfg($key = NULL, $default = NULL)
    {
        if (self::$_cfg === NULL)
        {
            self::$_cfg = (array) Kohana::$config->load('nj645_llmlab');
        }

        if ($key === NULL)
        {
            return self::$_cfg;
        }

        $value = self::$_cfg;

        foreach (explode('.', $key) as $part)
        {
            if ( ! is_array($value) OR ! array_key_exists($part, $value))
            {
                return $default;
            }

            $value = $value[$part];
        }

        return $value === NULL ? $default : $value;
    }

    // ------------------------------------------------------------- нормализация

    /** Текст без управляющих символов: их не должно быть ни в корпусе, ни в токенах. */
    public static function normalize_text($text)
    {
        $text = (string) $text;
        $text = str_replace(["\r\n", "\r", "\t"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * Токены для n-граммной модели: слова в нижнем регистре, числа и знаки.
     * Пунктуация — отдельные токены, чтобы «привет, мир» давало контекст «,».
     */
    public static function ngram_tokens($text)
    {
        $text = self::normalize_text($text);

        if ($text === '')
        {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}]+|[^\p{L}\p{N}\s]+/u', mb_strtolower($text), $m);

        $tokens = [];

        foreach ($m[0] as $token)
        {
            $token = mb_substr($token, 0, 80);

            if ($token !== '')
            {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    // -------------------------------------------------------------- токенизация

    /**
     * Разложить текст на токены мини-BPE.
     *
     * @param string $text  текст
     * @param array  $vocab ['words' => [слово => счётчик], 'pieces' => [строка, …]]
     *
     * @return array ['tokens' => [['t' => …, 'kind' => word|piece|space|punct, 'known' => bool]], 'count' => int]
     */
    public static function tokenize($text, array $vocab)
    {
        $text   = self::normalize_text($text);
        $pieces = ! empty($vocab['pieces']) ? array_flip($vocab['pieces']) : [];
        $words  = ! empty($vocab['words']) ? $vocab['words'] : [];

        preg_match_all('/\s+|[\p{L}\p{N}]+|[^\p{L}\p{N}\s]+/u', $text, $m);

        $tokens = [];

        foreach ($m[0] as $chunk)
        {
            if (preg_match('/^\s+$/u', $chunk))
            {
                // Пробел в настоящих токенизаторах часто приклеен к слову; здесь
                // показываем его отдельным чипом «␣», чтобы разбиение было видно.
                $tokens[] = ['t' => '␣', 'kind' => 'space', 'known' => TRUE];
                continue;
            }

            if ( ! preg_match('/^[\p{L}\p{N}]+$/u', $chunk))
            {
                foreach (preg_split('//u', $chunk, -1, PREG_SPLIT_NO_EMPTY) as $char)
                {
                    $tokens[] = ['t' => $char, 'kind' => 'punct', 'known' => TRUE];
                }

                continue;
            }

            $lower = mb_strtolower($chunk);

            if (isset($words[$lower]))
            {
                $tokens[] = ['t' => $chunk, 'kind' => 'word', 'known' => TRUE];
                continue;
            }

            foreach (self::_split_word($lower, $pieces) as $piece)
            {
                $tokens[] = [
                    't'     => $piece,
                    'kind'  => mb_strlen($piece) > 1 ? 'piece' : 'letter',
                    'known' => isset($pieces[$piece]),
                ];
            }
        }

        return ['tokens' => $tokens, 'count' => count($tokens)];
    }

    /** Жадное разбиение слова на самые длинные известные куски словаря. */
    protected static function _split_word($word, array $pieces)
    {
        $out    = [];
        $len    = mb_strlen($word);
        $pos    = 0;
        $max    = 12;

        while ($pos < $len)
        {
            $matched = NULL;

            for ($size = min($max, $len - $pos); $size >= 2; $size--)
            {
                $candidate = mb_substr($word, $pos, $size);

                if (isset($pieces[$candidate]))
                {
                    $matched = $candidate;
                    break;
                }
            }

            if ($matched === NULL)
            {
                $matched = mb_substr($word, $pos, 1);
            }

            $out[] = $matched;
            $pos  += mb_strlen($matched);
        }

        return $out;
    }

    /**
     * Оценка расхода токенов у РЕАЛЬНЫХ моделей (упрощённая, по типам символов).
     * Это ориентир для расчёта цены, а не точный подсчёт: точный даёт только
     * токенизатор конкретной модели.
     *
     * @return array ['total' => int, 'parts' => [['kind' => 'cyr', 'chars' => n, 'tokens' => m], …]]
     */
    public static function estimate_tokens($text)
    {
        $rates = (array) self::_cfg('chars_per_token', [
            'cyr' => 2.2, 'lat' => 4.0, 'digit' => 3.0, 'punct' => 2.0, 'space' => 4.5, 'other' => 3.0,
        ]);

        $counts = ['cyr' => 0, 'lat' => 0, 'digit' => 0, 'punct' => 0, 'space' => 0, 'other' => 0];

        foreach (preg_split('//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $char)
        {
            if (preg_match('/[А-Яа-яЁё]/u', $char))
            {
                $counts['cyr']++;
            }
            elseif (preg_match('/[A-Za-z]/', $char))
            {
                $counts['lat']++;
            }
            elseif (preg_match('/[0-9]/', $char))
            {
                $counts['digit']++;
            }
            elseif (preg_match('/\s/u', $char))
            {
                $counts['space']++;
            }
            elseif (preg_match('/[\p{P}\p{S}]/u', $char))
            {
                $counts['punct']++;
            }
            else
            {
                $counts['other']++;
            }
        }

        $parts = [];
        $total = 0.0;

        foreach ($counts as $kind => $chars)
        {
            if ($chars === 0)
            {
                continue;
            }

            $rate   = max(1.0, (float) ($rates[$kind] ?? 3.0));
            $tokens = (int) ceil($chars / $rate);

            $parts[] = ['kind' => $kind, 'chars' => $chars, 'tokens' => $tokens];
            $total  += $chars / $rate;
        }

        return [
            'total' => (int) ceil($total),
            'parts' => $parts,
        ];
    }

    /**
     * Настроен ли на платформе хотя бы один LLM-провайдер. От этого зависит,
     * доступны ли демонстрации с реальной моделью (прогон промптов): без
     * ключа и адреса честнее сказать «ИИ на этом сервере не подключён».
     */
    public static function llm_available()
    {
        try
        {
            $providers = Kohana::$config->load('llm.providers');

            if (is_array($providers) OR $providers instanceof Traversable)
            {
                foreach ($providers as $provider)
                {
                    $provider = (array) $provider;

                    if ( ! empty($provider['base_url']) AND ! empty($provider['api_key']))
                    {
                        return TRUE;
                    }
                }
            }
        }
        catch (Throwable $e)
        {
            // конфиг LLM недоступен — считаем, что модели нет
        }

        return FALSE;
    }

    /** Язык текста по доле кириллицы — для суточных агрегатов. */
    public static function detect_lang($text)
    {
        $cyr = preg_match_all('/[А-Яа-яЁё]/u', (string) $text);
        $lat = preg_match_all('/[A-Za-z]/', (string) $text);

        if ($cyr + $lat === 0)
        {
            return 'other';
        }

        return $cyr >= $lat ? 'ru' : 'en';
    }

    /**
     * Стоимость запроса в рублях по прайсу из конфига.
     *
     * @return array ['model' => key, 'title' => …, 'in' => float, 'out' => float, 'total' => float, 'fits' => bool]
     */
    public static function cost($tokens_in, $tokens_out, $model_key, array $models)
    {
        if ( ! isset($models[$model_key]))
        {
            return NULL;
        }

        $model = $models[$model_key];
        $in    = (float) $tokens_in;
        $out   = (float) $tokens_out;

        $cost_in  = $in / 1000000 * (float) $model['in'];
        $cost_out = $out / 1000000 * (float) $model['out'];

        return [
            'model'    => $model_key,
            'title'    => (string) $model['title'],
            'context'  => (int) $model['context'],
            'in'       => round($cost_in, 2),
            'out'      => round($cost_out, 2),
            'total'    => round($cost_in + $cost_out, 2),
            'own'      => ! empty($model['own']),
            'fits'     => ($in + $out) <= (int) $model['context'],
            'note'     => (string) ($model['note'] ?? ''),
        ];
    }

    // -------------------------------------------------------------- сэмплирование

    /**
     * Превратить строки n-грамм в распределение вероятностей.
     *
     * @param array $rows  [['token' => …, 'score' => …, 'count' => …, 'n' => …], …]
     * @param float $temperature  0 — жадный выбор, 1 — как есть, >1 — «смелее»
     * @param int   $top_k        0 — без ограничения
     * @param float $top_p        1.0 — без ограничения
     *
     * @return array ['items' => [['token','prob','score','count','n'], …], 'entropy' => float, 'total' => int]
     */
    public static function distribution(array $rows, $temperature, $top_k = 0, $top_p = 1.0, $limit = 40)
    {
        if ( ! $rows)
        {
            return ['items' => [], 'entropy' => 0.0, 'total' => 0];
        }

        $temperature = max(0.0, min(2.0, (float) $temperature));
        $top_k       = max(0, (int) $top_k);
        $top_p       = max(0.0, min(1.0, (float) $top_p));

        // Логарифм веса = «логит»: температура делит логит, как в настоящей модели.
        $logits = [];

        foreach ($rows as $row)
        {
            $score = max(1e-9, (float) $row['score']);
            $logits[] = log($score);
        }

        if ($temperature < 0.05)
        {
            $best = 0;

            foreach ($logits as $i => $v)
            {
                if ($v > $logits[$best])
                {
                    $best = $i;
                }
            }

            $items = [[
                'token' => (string) $rows[$best]['token'],
                'prob'  => 1.0,
                'score' => (float) $rows[$best]['score'],
                'count' => (int) $rows[$best]['count'],
                'n'     => (int) $rows[$best]['n'],
            ]];

            return ['items' => $items, 'entropy' => 0.0, 'total' => 1, 'greedy' => TRUE];
        }

        $powered = [];
        $sum     = 0.0;

        foreach ($logits as $i => $logit)
        {
            $value = exp($logit / $temperature);
            $powered[$i] = $value;
            $sum += $value;
        }

        if ($sum <= 0)
        {
            return ['items' => [], 'entropy' => 0.0, 'total' => 0];
        }

        $items = [];

        foreach ($rows as $i => $row)
        {
            $items[] = [
                'token' => (string) $row['token'],
                'prob'  => $powered[$i] / $sum,
                'score' => (float) $row['score'],
                'count' => (int) $row['count'],
                'n'     => (int) $row['n'],
            ];
        }

        // Сортировка по вероятности и отсечения: top-k, затем top-p (нуклеус).
        usort($items, function ($a, $b) {
            return $a['prob'] < $b['prob'] ? 1 : ($a['prob'] > $b['prob'] ? -1 : 0);
        });

        $total_before = count($items);

        if ($top_k > 0 AND count($items) > $top_k)
        {
            $items = array_slice($items, 0, $top_k);
        }

        if ($top_p < 1.0)
        {
            $cumulative = 0.0;
            $kept       = [];

            foreach ($items as $item)
            {
                $kept[] = $item;
                $cumulative += $item['prob'];

                if ($cumulative >= $top_p)
                {
                    break;
                }
            }

            $items = $kept;
        }

        if (count($items) > $limit)
        {
            $items = array_slice($items, 0, $limit);
        }

        // Перенормировка после отсечений — сумма снова 1.
        $sum2 = 0.0;

        foreach ($items as $item)
        {
            $sum2 += $item['prob'];
        }

        $entropy = 0.0;

        foreach ($items as $i => $item)
        {
            $items[$i]['prob'] = $sum2 > 0 ? $item['prob'] / $sum2 : 0.0;

            if ($items[$i]['prob'] > 0)
            {
                $entropy -= $items[$i]['prob'] * log($items[$i]['prob'], 2);
            }
        }

        return [
            'items'   => $items,
            'entropy' => round($entropy, 3),
            'total'   => $total_before,
            'greedy'  => FALSE,
        ];
    }

    /**
     * Взять один токен из распределения. Seed делает розыгрыш
     * воспроизводимым: одну и ту же пару «контекст + seed» можно переиграть.
     */
    public static function sample(array $distribution, $seed)
    {
        $items = $distribution['items'];

        if ( ! $items)
        {
            return NULL;
        }

        if ( ! empty($distribution['greedy']) OR count($items) === 1)
        {
            return $items[0] + ['index' => 0];
        }

        mt_srand(((int) $seed) & 0x7fffffff);
        $roll = mt_rand() / mt_getrandmax();

        $cumulative = 0.0;

        foreach ($items as $i => $item)
        {
            $cumulative += $item['prob'];

            if ($roll <= $cumulative)
            {
                return $item + ['index' => $i];
            }
        }

        $last = count($items) - 1;

        return $items[$last] + ['index' => $last];
    }

    // ---------------------------------------------------------------- векторы

    /**
     * Локальный эмбеддинг: хеш слов и символьных триграмм в вектор заданной
     * размерности. Не «понимает» текст, но похожие тексты сближает — чего
     * достаточно для демонстрации косинусной близости без внешнего сервиса.
     *
     * @return float[] единичной длины
     */
    public static function local_embed($text, $dims = 128)
    {
        $dims   = max(16, min(1024, (int) $dims));
        $vector = array_fill(0, $dims, 0.0);

        $norm = mb_strtolower(self::normalize_text($text));

        // Слова весят больше, чем отдельные триграммы.
        preg_match_all('/[\p{L}\p{N}]+/u', $norm, $m);

        foreach ($m[0] as $word) {
            $hash = crc32($word);
            $vector[$hash % $dims] += 1.0;
        }

        $padded = ' '.$norm.' ';
        $len    = mb_strlen($padded);

        if ($len >= 3)
        {
            for ($i = 0; $i + 3 <= $len; $i++)
            {
                $gram = mb_substr($padded, $i, 3);
                $vector[crc32($gram) % $dims] += 0.35;
            }
        }

        $norm2 = 0.0;

        foreach ($vector as $value)
        {
            $norm2 += $value * $value;
        }

        $norm2 = sqrt($norm2);

        if ($norm2 <= 0)
        {
            return $vector;
        }

        foreach ($vector as $i => $value)
        {
            $vector[$i] = round($value / $norm2, 6);
        }

        return $vector;
    }

    /** Косинусная близость: от −1 (противоположны) до 1 (одно и то же). */
    public static function cosine(array $a, array $b)
    {
        $n = min(count($a), count($b));
        $dot = 0.0;
        $na  = 0.0;
        $nb  = 0.0;

        for ($i = 0; $i < $n; $i++)
        {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] * $a[$i];
            $nb  += $b[$i] * $b[$i];
        }

        if ($na <= 0 OR $nb <= 0)
        {
            return 0.0;
        }

        return round($dot / (sqrt($na) * sqrt($nb)), 4);
    }

    /**
     * Ближайшие соседи точки в наборе: используется для семантической карты.
     *
     * @param float[] $vector
     * @param array   $points [['id' => …, 'label' => …, 'vector' => float[], …], …]
     *
     * @return array точки с добавленным 'similarity', отсортированные по близости
     */
    public static function nearest(array $vector, array $points, $limit = 8, $exclude_id = NULL)
    {
        $out = [];

        foreach ($points as $point)
        {
            if ($exclude_id !== NULL AND (int) $point['id'] === (int) $exclude_id)
            {
                continue;
            }

            $vec = is_array($point['vector']) ? $point['vector'] : json_decode((string) $point['vector'], TRUE);

            if ( ! is_array($vec) OR ! $vec)
            {
                continue;
            }

            $point['similarity'] = self::cosine($vector, array_map('floatval', $vec));
            $out[] = $point;
        }

        usort($out, function ($a, $b) {
            return $a['similarity'] < $b['similarity'] ? 1 : ($a['similarity'] > $b['similarity'] ? -1 : 0);
        });

        return array_slice($out, 0, max(1, (int) $limit));
    }

    // ------------------------------------------------------------------ промпт

    /**
     * Разбор промпта по чек-листу: роль, задача, контекст, формат, ограничения,
     * примеры, аудитория, тон, работа с неизвестным. Скоринг детерминированный
     * и объяснимый — каждая находка сопровождается советом.
     *
     * @return array ['score' => 0..100, 'grade' => '…', 'checks' => [key => bool],
     *                'findings' => [['level' => good|warn|tip, 'title' => …, 'text' => …]],
     *                'chars' => int, 'tokens' => int]
     */
    public static function analyze_prompt($text)
    {
        $text = self::normalize_text($text);
        $low  = mb_strtolower($text);
        $chars = mb_strlen($text);

        $checks = [
            'role'        => (bool) preg_match('/(ты\s*[—–-]|выступай|представь себя|веди себя как|act as|ты опытн|как эксперт|в роли)/u', $low),
            'task'        => (bool) preg_match('/(напиши|составь|объясни|переведи|проанализируй|сравни|извлеки|классифицируй|сделай|создай|разбери|оцени|перепиши|сформулируй|сократи|найди|посчитай|предложи|summarize|extract|analyze)/u', $low),
            'context'     => (bool) preg_match('/(текст|данные|контекст|ниже|источник|вход|пример|ситуаци|задача:)/u', $low),
            'format'      => (bool) preg_match('/(json|таблиц|спис(ок|ком)|формат|схем|markdown|абзац|пункт|строк|колонк|строго)/u', $low),
            'constraints' => (bool) preg_match('/(не более|не менее|до \d|только|запрещ|исключи|не добавляй|сохрани|максимум|минимум|без )/u', $low),
            'examples'    => (bool) preg_match('/(например|пример|образец|вход:|выход:|input:|output:)/u', $low),
            'audience'    => (bool) preg_match('/(аудитор|читател|для школьник|для клиент|для новичк|для руковод|для детей|для коллег)/u', $low),
            'tone'        => (bool) preg_match('/(тон|стил|формальн|неформальн|дружелюбн|официальн|нейтральн|пиши как)/u', $low),
            'unknown'     => (bool) preg_match('/(если не знаешь|нет данных|не выдумывай|не придумывай|не додумывай|честно|если данных нет|в источниках нет)/u', $low),
            'steps'       => (bool) preg_match('/(по шагам|шаг \d|сначала|затем|далее)/u', $low),
        ];

        $findings = [];

        $good = [
            'role'        => ['Роль задана', 'Модель знает, чью точку зрения держать.'],
            'task'        => ['Задача названа', 'Есть глагол действия: понятно, что именно требуется.'],
            'context'     => ['Есть контекст', 'Модели даны данные, а не только вопрос.'],
            'format'      => ['Задан формат ответа', 'Результат проще проверить и использовать в программе.'],
            'constraints' => ['Есть ограничения', 'Понятно, чего делать нельзя и где границы.'],
            'examples'    => ['Есть примеры', 'Пример задаёт структуру надёжнее словесных описаний.'],
            'audience'    => ['Названа аудитория', 'Ответ будет подстроен под читателя.'],
            'tone'        => ['Задан тон', 'Стиль ответа предсказуем.'],
            'unknown'     => ['Есть правило про «нет данных»', 'Меньше шансов получить выдумку вместо честного пробела.'],
            'steps'       => ['Задан порядок работы', 'Пошаговая структура снижает разброс ответов.'],
        ];

        foreach ($checks as $key => $ok)
        {
            if ($ok)
            {
                $findings[] = ['level' => 'good', 'key' => $key, 'title' => $good[$key][0], 'text' => $good[$key][1]];
            }
        }

        $tips = [
            'role'        => 'Добавьте роль: «Ты — опытный редактор». Роль задаёт словарь и глубину ответа.',
            'task'        => 'Назовите действие глаголом: «составь», «извлеки», «сравни». Вопрос «что думаешь?» даёт размытый ответ.',
            'context'     => 'Вставьте данные прямо в промпт или скажите, откуда их взять. Модель не знает вашей ситуации.',
            'format'      => 'Укажите формат: «список из 5 пунктов», «строго JSON по схеме», «таблица: критерий — вывод».',
            'constraints' => 'Добавьте границы: объём, что не менять, чего не выдумывать. Ограничения уменьшают разброс.',
            'examples'    => 'Дайте 1–2 примера «вход → выход». Это самый быстрый способ зафиксировать формат.',
        ];

        $missing = 0;

        foreach (['role', 'task', 'context', 'format', 'constraints', 'examples'] as $key)
        {
            if ( ! $checks[$key])
            {
                $missing++;
                $findings[] = ['level' => 'tip', 'key' => $key, 'title' => 'Чего не хватает: '.$key, 'text' => $tips[$key]];
            }
        }

        // Слабые места формулировки.
        $warns = [];

        if (preg_match('/(красиво|качественно|интересно|нормально|хорошо\s|удобно|правильно|эффективно|оптимально)/u', $low))
        {
            $warns[] = ['level' => 'warn', 'key' => 'vague',
                'title' => 'Оценочные слова без критерия',
                'text'  => '«Красиво», «качественно», «интересно» каждый понимает по-своему. Замените критерием: «до 1500 знаков», «без жаргона», «с примером».'];
        }

        if (preg_match('/(пожалуйста|если не сложно|будь добр|очень прошу)/u', $low) AND $chars < 400)
        {
            $warns[] = ['level' => 'warn', 'key' => 'polite',
                'title' => 'Вежливость вместо задания',
                'text'  => '«Пожалуйста» не улучшает результат. Вежливая просьба из трёх слов даёт такой же размытый ответ, как приказ из трёх слов.'];
        }

        if (preg_match('/(кратко|коротко)/u', $low) AND preg_match('/(подробно|развёрнуто|детально)/u', $low))
        {
            $warns[] = ['level' => 'warn', 'key' => 'contradiction',
                'title' => 'Противоречие в задании',
                'text'  => '«Кратко» и «подробно» в одном промпте спорят друг с другом. Выберите одно или укажите, где именно какая детальность нужна.'];
        }

        if ($chars > 40)
        {
            $letters = preg_match_all('/[A-ZА-ЯЁ]/u', $text);
            if ($letters > 0 AND $letters / max(1, $chars) > 0.5)
            {
                $warns[] = ['level' => 'warn', 'key' => 'caps',
                    'title' => 'Капс и крик',
                    'text'  => 'Заглавные буквы не усиливают инструкцию, зато мешают модели различать структуру. Пишите обычным регистром.'];
            }
        }

        if (preg_match('/(!!+|\?\?+)/u', $text))
        {
            $warns[] = ['level' => 'warn', 'key' => 'emoji',
                'title' => 'Лишние знаки',
                'text'  => 'Повторяющиеся «!!» и «??» добавляют шума. Если нужен строгий ответ — уберите эмоции из инструкции.'];
        }

        if ($chars > 0 AND $chars < 80)
        {
            $warns[] = ['level' => 'tip', 'key' => 'short',
                'title' => 'Промпт очень короткий',
                'text'  => 'Короткий промпт не всегда плох, но модель додумывает детали за вас. Для повторяемой задачи опишите роль, формат и ограничения.'];
        }

        if (preg_match_all('/\{[a-zа-я_]+/u', $text) >= 2)
        {
            $warns[] = ['level' => 'tip', 'key' => 'placeholders',
                'title' => 'Незаполненные подстановки',
                'text'  => 'В промпте остались {плейсхолдеры}. Перед отправкой подставьте данные, иначе модель придумает их сама.'];
        }

        $findings = array_merge($warns, $findings);

        // Скоринг: 6 главных пунктов весят по 11, остальные — по 7.
        $score = 0;

        foreach (['role', 'task', 'context', 'format', 'constraints', 'examples'] as $key)
        {
            if ($checks[$key])
            {
                $score += 11;
            }
        }

        foreach (['audience', 'tone', 'unknown', 'steps'] as $key)
        {
            if ($checks[$key])
            {
                $score += 7;
            }
        }

        // Противоречие и размытость — минус: они прямо мешают исполнителю.
        foreach ($warns as $warn)
        {
            if ($warn['key'] === 'contradiction')
            {
                $score -= 12;
            }
            elseif ($warn['key'] === 'vague')
            {
                $score -= 8;
            }
            elseif ($warn['key'] === 'caps')
            {
                $score -= 5;
            }
        }

        $score = max(5, min(100, $score));

        $est = self::estimate_tokens($text);

        return [
            'score'    => $score,
            'grade'    => $score >= 85 ? 'отличный' : ($score >= 65 ? 'хороший' : ($score >= 40 ? 'средний' : 'слабый')),
            'checks'   => $checks,
            'findings' => $findings,
            'chars'    => $chars,
            'tokens'   => $est['total'],
            'missing'  => $missing,
        ];
    }

    /** Итоговая оценка набора текстов для демонстрации «похожесть»: 0..1. */
    public static function similarity_label($cosine)
    {
        $cosine = (float) $cosine;

        if ($cosine >= 0.85)
        {
            return ['title' => 'почти одно и то же', 'tone' => 'good'];
        }

        if ($cosine >= 0.55)
        {
            return ['title' => 'близки по смыслу', 'tone' => 'good'];
        }

        if ($cosine >= 0.3)
        {
            return ['title' => 'есть общая тема', 'tone' => 'info'];
        }

        if ($cosine >= 0.12)
        {
            return ['title' => 'связь слабая', 'tone' => 'warn'];
        }

        return ['title' => 'разные тексты', 'tone' => 'warn'];
    }
}
