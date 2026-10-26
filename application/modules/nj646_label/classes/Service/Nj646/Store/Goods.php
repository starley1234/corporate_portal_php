<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Goods`: товары и производители с этикеток: сопутствующая информация, дубли и слияние.
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Goods
{

    /**
     * ЕДИНАЯ дверь сохранения сопутствующей информации с этикетки.
     *
     * Вызывается после разбора (текст) и для фото (строки этикетки лежат в
     * прогоне). Разбор детерминированный — Service_Nj646_About, без модели:
     * выдуманный ИНН или ГОСТ хуже пробела, потому что по нему потом ищут
     * организацию. Найденное раскладывается по трём местам:
     *
     *   * производитель → `nj_646_producers` (одна запись на организацию)
     *     + связь с онтологией предприятий (nj607) и справочником (nj594);
     *   * товар        → `nj_646_products` (штрихкод/бренд+название);
     *   * разбор       → колонки `producer_id`, `product_id`, снимок `about`.
     *
     * @return array ['saved' => bool, 'about' => [...], 'producer' => ?, 'product' => ?]
     */
    public static function goods_save($text, array $opts = [])
    {
        self::ensure_goods();

        $about = Service_Nj646_About::extract($text);

        $producer = NULL;
        $first_id = 0;

        foreach ((array) $about['producers'] as $row)
        {
            $saved = self::producer_upsert($row, $opts);

            if ( ! $saved)
            {
                continue;
            }

            // Товару отдаём ИЗГОТОВИТЕЛЯ, а не импортёра: импортёр — это
            // посредник, и в карточке товара он только путал бы.
            if ($first_id === 0 OR ($producer AND (string) $producer['role'] === 'importer' AND (string) $saved['role'] !== 'importer'))
            {
                $first_id = (int) $saved['id'];
                $producer = $saved;
            }
        }

        // ТОВАР ЗАВОДИМ ВСЕГДА, когда разбор состоялся.
        //
        // Владелец (03.10.2026): «в БД ты должен хранить перечень товаров +
        // производитель с его составом — и показывать в разных списках разбор
        // ТОВАРА». Раньше товара не было, если на этикетке не нашлось названия
        // или штрихкода: списки показывали «63/100 · нейтрально» без предмета
        // разговора. Теперь у разбора всегда есть товар, а имя ему либо даёт
        // этикетка, либо придумывает модуль (рабочее имя + пометка name_auto).
        $data  = (array) $about['product'];
        $title = trim((string) ($opts['title'] ?? ''));

        if ($title !== '' AND trim((string) ($data['name'] ?? '')) === '')
        {
            $data['name']      = $title;
            $data['name_auto'] = ! empty($opts['title_auto']);
        }

        if ( ! empty($opts['product_kind']))
        {
            $data['product_kind'] = (string) $opts['product_kind'];
        }

        if ( ! empty($opts['kind']) AND in_array((string) $opts['kind'], ['food', 'cosmetic', 'both'], TRUE))
        {
            $data['kind'] = (string) $opts['kind'];
        }

        // Пищевая ценность с этикетки (только еда её и печатает) едет вместе с
        // товаром: таблица «белки/жиры/углеводы» — такая же сопутствующая
        // информация, как масса и ГОСТ, и нужна она ровно там же.
        if ( ! empty($about['nutrition']))
        {
            $data['nutrition'] = (array) $about['nutrition'];
        }

        $product = self::product_upsert($data, $first_id);

        return [
            'saved'    => (bool) ($product OR $producer),
            'about'    => $about,
            'producer' => $producer,
            'product'  => $product,
            'ids'      => ['producer_id' => $first_id, 'product_id' => (int) ($product['id'] ?? 0)],
        ];
    }

    /**
     * Карточка производителя: найти по ключу и дополнить, иначе создать.
     *
     * Дополняем, но НЕ перетираем: если в базе уже есть адрес, а на новой
     * этикетке его нет — адрес остаётся; если реквизиты расходятся, первое
     * значение сохраняется (расхождение — тема для модерации, а не для
     * молчаливой подмены).
     */
    public static function producer_upsert(array $data, array $opts = [])
    {
        self::ensure_goods();

        $name = trim((string) ($data['name'] ?? ''));

        if (mb_strlen($name) < 3)
        {
            return NULL;
        }

        $fields = [
            'name'    => mb_substr($name, 0, 300),
            'role'    => in_array((string) ($data['role'] ?? ''), ['producer', 'importer', 'address'], TRUE)
                ? (string) $data['role'] : 'producer',
            'address' => mb_substr(trim((string) ($data['address'] ?? '')), 0, 400),
            'country' => mb_substr(trim((string) ($data['country'] ?? '')), 0, 80),
            'inn'     => mb_substr(preg_replace('/\D/', '', (string) ($data['inn'] ?? '')), 0, 12),
            'ogrn'    => mb_substr(preg_replace('/\D/', '', (string) ($data['ogrn'] ?? '')), 0, 15),
            'phone'   => mb_substr(trim((string) ($data['phone'] ?? '')), 0, 40),
            'site'    => mb_substr(trim((string) ($data['site'] ?? '')), 0, 120),
        ];

        $key = Service_Nj646_About::producer_key($fields['name']);
        $row = self::_one('SELECT * FROM nj_646_producers WHERE name_key = '.self::db()->quote($key));

        if ($row)
        {
            $set = [];
            $enriched = FALSE;

            foreach ($fields as $field => $value)
            {
                if ($value !== '' AND (string) $row[$field] === '')
                {
                    $set[$field] = $value;
                    $enriched = TRUE;
                }
            }

            $set['hits']       = DB::expr('"hits" + 1');
            $set['last_seen']  = DB::expr('now()');
            $set['updated_at'] = DB::expr('now()');
            $set['raw']        = DB::expr(self::_json($data));

            DB::update('nj_646_producers')->set($set)->where('id', '=', (int) $row['id'])->execute();

            $row = self::producer_get((int) $row['id']);

            // Появились реквизиты — самое время связать с общей онтологией и
            // справочником организаций (там поиск по ИНН/ОГРН).
            if ($enriched AND ($fields['inn'] !== '' OR $fields['ogrn'] !== ''))
            {
                self::producer_link_external((int) $row['id']);
                $row = self::producer_get((int) $row['id']);
            }

            return $row;
        }

        $id = (int) self::_one('INSERT INTO nj_646_producers
                                    (name_key, name, role, address, country, inn, ogrn, phone, site, source, raw)
                                VALUES (
                                    '.self::db()->quote($key).',
                                    '.self::db()->quote($fields['name']).',
                                    '.self::db()->quote($fields['role']).',
                                    '.self::db()->quote($fields['address']).',
                                    '.self::db()->quote($fields['country']).',
                                    '.self::db()->quote($fields['inn']).',
                                    '.self::db()->quote($fields['ogrn']).',
                                    '.self::db()->quote($fields['phone']).',
                                    '.self::db()->quote($fields['site']).',
                                    '.self::db()->quote(mb_substr((string) ($opts['source'] ?? 'label'), 0, 24)).',
                                    '.self::_json($data).')
                                RETURNING id', 'id');

        if ($id <= 0)
        {
            return NULL;
        }

        // Онтология предприятий и справочник организаций — сразу, но не в
        // ущерб разбору: любые сбои только в журнал (см. producer_link_external).
        self::producer_link_external($id);

        return self::producer_get($id);
    }

    /**
     * Связать производителя с проектом: онтология предприятий (nj607) и
     * справочник организаций (nj594, поиск по ИНН/ОГРН).
     *
     * Ничего не выдумываем: в nj594 попадаем только при точном совпадении
     * реквизитов (справочник ведётся по данным ФНС), а в онтологии заводим
     * сущность-предприятие со ссылкой на карточку производителя. Ошибки
     * соседних модулей разбор не должны ломать — только журнал.
     *
     * @return array ['ontology_url' => string, 'org_id' => int]
     */
    public static function producer_link_external($id)
    {
        $row = self::producer_get((int) $id);

        if ( ! $row)
        {
            return ['ontology_url' => '', 'org_id' => 0];
        }

        $out = ['ontology_url' => (string) $row['ontology_url'], 'org_id' => (int) $row['org_id']];

        // 1. Общая онтология предприятий (nj607): одна сущность на компанию.
        if ((int) $row['ontology_id'] <= 0 AND class_exists('Service_Nj607_Ontology'))
        {
            try
            {
                $entity_id = (int) Service_Nj607_Ontology::remember((string) $row['name'], [
                    'kind'    => 'company',
                    'module'  => 'nj646',
                    'code'    => 'producer-'.(int) $row['id'],
                    'path'    => '/label/producer/'.(int) $row['id'],
                    'label'   => 'Производитель товара',
                    'details' => (string) $row['address'],
                    'inn'     => (string) $row['inn'],
                    'ogrn'    => (string) $row['ogrn'],
                    'source'  => 'nj646',
                ]);

                $url = $entity_id > 0
                    ? (string) Service_Nj607_Ontology::entity_url((string) $row['name'], 'company')
                    : '';

                if ($entity_id > 0)
                {
                    DB::update('nj_646_producers')
                        ->set([
                            'ontology_id'  => $entity_id,
                            'ontology_url' => mb_substr($url, 0, 300),
                            'updated_at'   => DB::expr('now()'),
                        ])
                        ->where('id', '=', (int) $row['id'])->execute();

                    $out['ontology_url'] = $url;
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj646: производитель :i в онтологию: :e',
                    [':i' => (int) $row['id'], ':e' => $e->getMessage()]);
            }
        }

        // 2. Справочник организаций (nj594) — только точное совпадение.
        if ($out['org_id'] <= 0 AND class_exists('Service_Nj594_Orgs'))
        {
            try
            {
                $org = NULL;

                if ((string) $row['ogrn'] !== '' AND Service_Nj594_Orgs::ogrn_valid((string) $row['ogrn']))
                {
                    $org = Service_Nj594_Orgs::by_ogrn((string) $row['ogrn']);
                }

                if ( ! $org AND (string) $row['inn'] !== '' AND Service_Nj594_Orgs::inn_valid((string) $row['inn']))
                {
                    foreach (Service_Nj594_Orgs::search((string) $row['inn'], 5) as $candidate)
                    {
                        if ((string) ($candidate['inn'] ?? '') === (string) $row['inn'])
                        {
                            $org = $candidate;
                            break;
                        }
                    }
                }

                if ($org)
                {
                    Service_Nj594_Orgs::link('nj646', 'producer-'.(int) $row['id'], (int) $org['id'],
                        (string) $row['name'], '/label/producer/'.(int) $row['id'], 'producer');

                    DB::update('nj_646_producers')
                        ->set(['org_id' => (int) $org['id'], 'updated_at' => DB::expr('now()')])
                        ->where('id', '=', (int) $row['id'])->execute();

                    $out['org_id'] = (int) $org['id'];
                }
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj646: производитель :i в справочник организаций: :e',
                    [':i' => (int) $row['id'], ':e' => $e->getMessage()]);
            }
        }

        return $out;
    }

    /**
     * Карточка товара: по ключу (штрихкод → иначе «бренд + название»).
     * Как и у производителя, известное не перетирается, а дополняется.
     */
    public static function product_upsert(array $data, $producer_id = 0)
    {
        self::ensure_goods();

        // Пустые поля отбрасываем, но МАССИВЫ (пищевая ценность) приводить к
        // строке нельзя: `(string) []` — это warning и потеря таблицы.
        $data = array_filter((array) $data, function ($v) {
            return is_array($v) ? $v !== [] : (string) $v !== '';
        });

        $name    = mb_substr(trim((string) ($data['name'] ?? '')), 0, 200);
        $barcode = preg_replace('/\D/', '', (string) ($data['barcode'] ?? ''));

        if ($name === '' AND $barcode === '')
        {
            return NULL;
        }

        // Если имя рабочее, в ключ идёт ещё и производитель: иначе «Мыло без
        // названия» разных заводов слиплось бы в один товар.
        //
        // Скобки здесь не для красоты. Было `A AND B ? x : y` — а `AND` связывает
        // слабее `?:`, то есть это `A AND (B ? x : y)`: в ключ уходило `TRUE`
        // («|1») у ВСЕХ производителей сразу, и товары разных заводов слипались
        // ровно так, как этот ключ и должен был предотвратить (поймал
        // `.tools/php_ternary_lint.py`).
        $auto   = ( ! empty($data['name_auto']) AND (int) $producer_id > 0);
        $suffix = $auto ? 'p'.(int) $producer_id : '';

        $key = Service_Nj646_About::product_key($data, $suffix);
        $row = self::product_by_key($key);

        // Ключи, созданные до исправления, несут «|1» вместо «|p<id>»: такую
        // карточку находим и переклеиваем ключ на верный, а не плодим дубль на
        // ровном месте. Если правильный ключ уже занят — не трогаем ничего:
        // карточка найдётся обычным путём.
        if ( ! $row AND $suffix !== '')
        {
            $legacy_key = Service_Nj646_About::product_key($data, '1');
            $legacy     = $legacy_key === $key ? NULL
                : self::_one('SELECT * FROM nj_646_products WHERE key = '.self::db()->quote($legacy_key));

            if ($legacy AND ! self::_one('SELECT id FROM nj_646_products WHERE key = '.self::db()->quote($key)))
            {
                DB::update('nj_646_products')->set(['key' => $key, 'updated_at' => DB::expr('now()')])
                    ->where('id', '=', (int) $legacy['id'])->execute();

                $legacy['key'] = $key;
                $row           = $legacy;

                Kohana::$log->add(Log::INFO, 'nj646: ключ товара :id переклеен на верный (производитель в ключе)',
                    [':id' => (int) $legacy['id']]);
            }
        }

        $fields = [
            'name'       => $name,
            'brand'      => mb_substr(trim((string) ($data['brand'] ?? '')), 0, 120),
            'kind'       => in_array((string) ($data['kind'] ?? ''), ['food', 'cosmetic', 'both'], TRUE)
                ? (string) $data['kind'] : 'auto',
            'barcode'    => mb_substr($barcode, 0, 20),
            'weight'     => mb_substr(trim((string) ($data['weight'] ?? '')), 0, 40),
            'gost'       => mb_substr(trim((string) ($data['gost'] ?? '')), 0, 80),
            'shelf_life' => mb_substr(trim((string) ($data['shelf_life'] ?? '')), 0, 120),
            'storage'    => mb_substr(trim((string) ($data['storage'] ?? '')), 0, 200),
            'country'    => mb_substr(trim((string) ($data['country'] ?? '')), 0, 80),
            'product_kind' => mb_substr(trim((string) ($data['product_kind'] ?? '')), 0, 24),
        ];

        if ($row)
        {
            $set = [];

            foreach ($fields as $field => $value)
            {
                if ($value !== '' AND $value !== 'auto' AND (string) $row[$field] === '')
                {
                    $set[$field] = $value;
                }
            }

            // Рабочее имя меняем на настоящее, когда оно появилось (модель
            // дала название задачей `title`): «Мыло без названия» → «Мыло
            // «Лаванда»». Пометку снимаем в той же строке.
            //
            // Флаг читаем ТОЛЬКО через `flag()`: PostgreSQL отдаёт `false`
            // строкой «f», а `! empty('f')` — правда. Из-за этого условие
            // срабатывало на КАЖДОМ товаре и откатывало название, которое
            // человек поправил руками, к тому, что напечатано на этикетке.
            if (self::flag($row['name_auto'] ?? FALSE) AND $fields['name'] !== ''
                AND empty($data['name_auto']) AND $fields['name'] !== (string) $row['name'])
            {
                $set['name']      = $fields['name'];
                $set['name_auto'] = DB::expr('FALSE');

                // Имя входит в ключ: без переклейки следующий разбор той же
                // этикетки посчитал бы НОВЫЙ ключ и завёл дубль (прежний ключ
                // при этом уедет в псевдонимы — старые ссылки не потеряются).
                $rekey = self::product_rekey_set($row, ['name' => $fields['name'], 'name_auto' => FALSE]);
                $set   = $rekey + $set;
            }

            if ((int) $producer_id > 0 AND (int) $row['producer_id'] <= 0)
            {
                $set['producer_id'] = (int) $producer_id;
            }

            // Пищевая ценность: ДОПОЛНЯЕМ, не перетираем. Правка руками важнее
            // новой этикетки (OCR ошибается), а пустая таблица не должна
            // стирать уже сохранённую (см. nutrition_merge).
            $merged = self::nutrition_merge(
                self::_json_decode($row['nutrition'] ?? []),
                (array) ($data['nutrition'] ?? []),
                (string) ($row['nutrition_source'] ?? ''));

            if ($merged['changed'])
            {
                $set['nutrition']        = DB::expr(self::_nutrition_json($merged['nutrition']));
                $set['nutrition_source'] = $merged['source'];
            }

            // `hits` считает product_touch(): один разбор — одно касание товара,
            // а не «обновление записи». Иначе счётчик врал бы на каждом заходе.
            $set['last_seen']  = DB::expr('now()');
            $set['updated_at'] = DB::expr('now()');
            $set['raw']        = DB::expr(self::_json($data));

            DB::update('nj_646_products')->set($set)->where('id', '=', (int) $row['id'])->execute();

            return self::product_get((int) $row['id']);
        }

        $nutrition = Service_Nj646_About::nutrition_sanitize((array) ($data['nutrition'] ?? []));

        $id = (int) self::_one('INSERT INTO nj_646_products
                                    (key, producer_id, name, brand, kind, product_kind, name_auto,
                                     barcode, weight, gost, shelf_life, storage, country,
                                     nutrition, nutrition_source, raw)
                                VALUES (
                                    '.self::db()->quote($key).',
                                    '.((int) $producer_id > 0 ? (int) $producer_id : 'NULL').',
                                    '.self::db()->quote($fields['name']).',
                                    '.self::db()->quote($fields['brand']).',
                                    '.self::db()->quote($fields['kind']).',
                                    '.self::db()->quote($fields['product_kind']).',
                                    '.( ! empty($data['name_auto']) ? 'TRUE' : 'FALSE').',
                                    '.self::db()->quote($fields['barcode']).',
                                    '.self::db()->quote($fields['weight']).',
                                    '.self::db()->quote($fields['gost']).',
                                    '.self::db()->quote($fields['shelf_life']).',
                                    '.self::db()->quote($fields['storage']).',
                                    '.self::db()->quote($fields['country']).',
                                    '.self::_nutrition_json($nutrition).',
                                    '.self::db()->quote($nutrition ? 'label' : '').',
                                    '.self::_json($data).')
                                RETURNING id', 'id');

        return $id > 0 ? self::product_get($id) : NULL;
    }

    /**
     * Товар и производитель «для показа»: только нужные поля и готовые ссылки.
     *
     * Одна форма на страницу разбора, карточку товара и JSON-ответ разбора —
     * иначе ссылки в онтологию/справочник пришлось бы собирать в трёх местах.
     */
    public static function goods_view($product, $producer)
    {
        $out = ['product' => NULL, 'producer' => NULL];

        if ($product)
        {
            $out['product'] = [
                'id'         => (int) $product['id'],
                'name'       => (string) $product['name'],
                'brand'      => (string) $product['brand'],
                'barcode'    => (string) $product['barcode'],
                'weight'     => (string) $product['weight'],
                'gost'       => (string) $product['gost'],
                'shelf_life' => (string) $product['shelf_life'],
                'storage'    => (string) $product['storage'],
                'country'    => (string) $product['country'],
                'kind'       => (string) $product['kind'],
                // Пищевая ценность: таблица + нейтральные пометки («много
                // сахаров»), чтобы страницы не считали пороги каждая по-своему.
                'nutrition'       => self::nutrition_of($product),
                'nutrition_flags' => Service_Nj646_About::nutrition_flags(self::nutrition_of($product)),
                'hits'       => (int) $product['hits'],
                'first_seen' => (string) $product['first_seen'],
                'last_seen'  => (string) $product['last_seen'],
                'url'        => '/label/product/'.(int) $product['id'],
            ];
        }

        if ($producer)
        {
            $out['producer'] = [
                'id'           => (int) $producer['id'],
                'name'         => (string) $producer['name'],
                'role'         => (string) $producer['role'],
                'address'      => (string) $producer['address'],
                'country'      => (string) $producer['country'],
                'inn'          => (string) $producer['inn'],
                'ogrn'         => (string) $producer['ogrn'],
                'phone'        => (string) $producer['phone'],
                'site'         => (string) $producer['site'],
                'hits'         => (int) $producer['hits'],
                'url'          => '/label/producer/'.(int) $producer['id'],
                // Связи с проектом: справочник организаций (nj594, по ОГРН) и
                // общая онтология предприятий (nj607).
                'org_url'      => ((int) $producer['org_id'] > 0 AND (string) $producer['ogrn'] !== '')
                    ? '/orgs/'.(string) $producer['ogrn'] : '',
                'ontology_url' => (string) $producer['ontology_url'],
            ];
        }

        return $out;
    }

    /** Производитель по id (jsonb-поле raw раскодировано). */
    public static function producer_get($id)
    {
        self::ensure_goods();

        $row = self::_one('SELECT * FROM nj_646_producers WHERE id = '.(int) $id);

        if ($row)
        {
            $row['raw'] = self::_json_decode($row['raw'] ?? []);
        }

        return $row;
    }

    /** Товар по id. */
    public static function product_get($id)
    {
        self::ensure_goods();

        $row = self::_one('SELECT * FROM nj_646_products WHERE id = '.(int) $id);

        if ($row)
        {
            $row['raw']         = self::_json_decode($row['raw'] ?? []);
            $row['nutrition']   = self::nutrition_of($row);
            $row['key_aliases'] = (array) self::_json_decode($row['key_aliases'] ?? []);
        }

        return $row;
    }

    /**
     * Пищевая ценность строки товара: jsonb → проверенный массив (или []).
     *
     * Списки берут товары одним `SELECT *`, и колонка приезжает строкой: эта
     * обёртка — единственное место, где она раскодируется и проверяется, чтобы
     * вьюхи не разбирали JSON каждая по-своему.
     */
    /**
     * Готовая к записи в jsonb строка: пустая таблица — это `{}`, а не `[]`.
     *
     * Колонка `nutrition` — объект, и пустой PHP-массив json_encode отдал бы
     * как `[]`: в базе появлялись бы два разных «пусто», а отборы вида
     * `nutrition::text = '{}'` (дочитывание старых товаров) мимо них
     * проходили бы.
     */
    protected static function _nutrition_json($nutrition)
    {
        return self::_json($nutrition ? $nutrition : (object) []);
    }

    public static function nutrition_of($row)
    {
        $value = is_array($row) ? ($row['nutrition'] ?? []) : $row;

        return Service_Nj646_About::nutrition_sanitize(self::_json_decode($value));
    }

    /**
     * Слить таблицу пищевой ценности: что знаем + что принесла этикетка.
     *
     * Правило как у остальных полей товара: ДОПОЛНЯЕМ, НЕ ПЕРЕТИРАЕМ.
     *   * поля, которых не было, добавляются;
     *   * поля, которые уже есть, остаются (OCR путает 0,5 и 9,5 — молчаливая
     *     подмена хуже пробела, расхождение правит админ);
     *   * таблица, заполненная РУКАМИ (`nutrition_source = manual`), этикеткой
     *     не трогается вовсе;
     *   * основа («на 100 г» / «на 100 мл») меняется только когда своей не было:
     *     мешать граммы с миллилитрами в одной таблице нельзя.
     *
     * @return array ['nutrition' => [...], 'source' => 'label|manual', 'changed' => bool]
     */
    public static function nutrition_merge($current, $incoming, $source = '')
    {
        $current  = Service_Nj646_About::nutrition_sanitize($current);
        $incoming = Service_Nj646_About::nutrition_sanitize($incoming);
        $source   = (string) $source === 'manual' ? 'manual' : ($current ? 'label' : '');

        if ( ! $incoming OR $source === 'manual')
        {
            return ['nutrition' => $current, 'source' => $source, 'changed' => FALSE];
        }

        if ( ! $current)
        {
            return ['nutrition' => $incoming, 'source' => 'label', 'changed' => TRUE];
        }

        $values  = $current['values'];
        $changed = FALSE;

        foreach ($incoming['values'] as $field => $value)
        {
            if ( ! array_key_exists($field, $values))
            {
                $values[$field] = $value;
                $changed = TRUE;
            }
        }

        $basis   = $current['basis'];
        $portion = $current['portion'] !== '' ? $current['portion'] : $incoming['portion'];

        if ($portion !== $current['portion'])
        {
            $changed = TRUE;
        }

        return [
            'nutrition' => ['basis' => $basis, 'portion' => $portion, 'values' => $values],
            'source'    => 'label',
            'changed'   => $changed,
        ];
    }

    /**
     * Сохранить пищевую ценность руками (админка): таблица целиком.
     *
     * Правка руками — последнее слово: после неё этикетка таблицу не меняет
     * (`nutrition_source = manual`), иначе следующий же разбор того же товара
     * вернул бы ошибку OCR. Пустая форма очищает таблицу и снимает пометку —
     * так можно откатиться к автоматическому разбору.
     */
    public static function product_nutrition_save($id, $data, $source = 'manual')
    {
        self::ensure_goods();

        $id = (int) $id;

        if ($id <= 0 OR ! self::_one('SELECT id FROM nj_646_products WHERE id = '.$id))
        {
            return FALSE;
        }

        $nutrition = Service_Nj646_About::nutrition_sanitize($data);
        $source    = $nutrition ? ((string) $source === 'label' ? 'label' : 'manual') : '';

        DB::update('nj_646_products')
            ->set([
                'nutrition'        => DB::expr(self::_nutrition_json($nutrition)),
                'nutrition_source' => $source,
                'updated_at'       => DB::expr('now()'),
            ])
            ->where('id', '=', $id)
            ->execute();

        return TRUE;
    }

    /** Производители для админки/поиска: свежие по последней встрече. */
    public static function producers_list($limit = 30, $offset = 0, $q = '')
    {
        self::ensure_goods();

        $where = '';

        if (trim((string) $q) !== '')
        {
            $like = self::db()->quote('%'.trim((string) $q).'%');
            $where = ' WHERE name ILIKE '.$like.' OR inn LIKE '.$like.' OR ogrn LIKE '.$like;
        }

        return self::_all('SELECT * FROM nj_646_producers'.$where.'
                            ORDER BY last_seen DESC, id DESC
                            LIMIT '.(int) max(1, min(200, $limit)).' OFFSET '.(int) max(0, $offset));
    }

    /** Товары для админки/поиска. */
    public static function products_list($limit = 30, $offset = 0, $q = '')
    {
        self::ensure_goods();

        $where = '';

        if (trim((string) $q) !== '')
        {
            $like = self::db()->quote('%'.trim((string) $q).'%');
            $where = ' WHERE name ILIKE '.$like.' OR brand ILIKE '.$like.' OR barcode LIKE '.$like;
        }

        return self::_all('SELECT p.*, pr.name AS producer_name
                             FROM nj_646_products p
                             LEFT JOIN nj_646_producers pr ON pr.id = p.producer_id'
                            .$where.'
                            ORDER BY p.last_seen DESC, p.id DESC
                            LIMIT '.(int) max(1, min(200, $limit)).' OFFSET '.(int) max(0, $offset));
    }

    /**
     * Товары того же типа — «с чем сравнить» (ссылки на /label/compare/<a>/<b>).
     *
     * Только те, у кого есть разобранный состав: сравнение без него
     * бессмысленно — предлагать такое человеку значит вести его в тупик.
     * Сначала своего типа (печенье к печенью), а если таких нет — свежие
     * разобранные товары вообще: сравнить творог с печеньем тоже можно.
     */
    public static function products_similar($kind, $exclude_id = 0, $limit = 8)
    {
        self::ensure_goods();

        $where = ' WHERE p.last_analysis_id IS NOT NULL';

        if (trim((string) $kind) !== '')
        {
            $where .= ' AND p.product_kind = '.self::db()->quote(trim((string) $kind));
        }

        if ((int) $exclude_id > 0)
        {
            $where .= ' AND p.id <> '.(int) $exclude_id;
        }

        return self::_all('SELECT p.id, p.name, p.name_auto, p.product_kind, p.last_score, p.last_verdict,
                                  p.analyses_count, pr.name AS producer_name
                             FROM nj_646_products p
                             LEFT JOIN nj_646_producers pr ON pr.id = p.producer_id'
                            .$where.'
                            ORDER BY p.last_seen DESC, p.id DESC
                            LIMIT '.(int) max(1, min(50, $limit)));
    }

    /** Список из jsonb — строкой через запятую (экспорт, письма, таблицы). */
    public static function list_text($value)
    {
        return implode(', ', array_map('strval', self::_json_decode($value)));
    }

    /** Все карточки базы для выгрузки (CSV в админке). */
    public static function ingredients_export($limit = 5000)
    {
        self::ensure_tables();

        return self::_all('SELECT slug, name_ru, name_int, kind, risk, verdict, category, e_code,
                                  status, source, confidence, hits, summary, details, synonyms, avoid
                             FROM nj_646_ingredients
                            ORDER BY category, name_ru
                            LIMIT '.(int) max(1, min(20000, $limit)));
    }

    /** Сколько производителей и товаров накопилось (подписи в админке). */
    /**
     * Отметить на товаре состоявшийся разбор: итог, время и счётчики.
     *
     * Нужен спискам: карточка товара и «Мои разборы» показывают оценку состава
     * без второго запроса к `nj_646_analyses`, а сортировка «что чаще разбирают»
     * не считает подзапросом.
     */
    public static function product_touch($product_id, $analysis_id, $score, $verdict)
    {
        $product_id = (int) $product_id;

        if ($product_id <= 0)
        {
            return FALSE;
        }

        self::ensure_goods();

        DB::update('nj_646_products')
            ->set([
                'last_analysis_id' => (int) $analysis_id,
                'last_score'       => max(0, min(100, (int) $score)),
                'last_verdict'     => mb_substr((string) $verdict, 0, 12),
                'analyses_count'   => DB::expr('"analyses_count" + 1'),
                'hits'             => DB::expr('"hits" + 1'),
                'last_seen'        => DB::expr('now()'),
                'updated_at'       => DB::expr('now()'),
            ])
            ->where('id', '=', $product_id)
            ->execute();

        return TRUE;
    }

    /**
     * Связать СТАРЫЕ разборы с товарами: владелец увидит их в списках как товары.
     *
     * Владелец (03.10.2026): «в БД ты должен хранить перечень товаров +
     * производитель с его составом». Разборы, сделанные до появления товаров,
     * лежат без `product_id` — и списки показывали их строкой «63/100 ·
     * нейтрально». Метод берёт снимок этикетки (`about`) и заводит по нему
     * товар с производителем; если снимка нет — разбирает `raw_text`.
     *
     * Идемпотентен: берёт только записи без товара, пачкой (по умолчанию 200),
     * поэтому вызывается кнопкой из админки сколько угодно раз.
     *
     * @return int сколько разборов получило товар
     */
    public static function analyses_backfill_products($limit = 200)
    {
        self::ensure_goods();

        $limit = max(1, min(500, (int) $limit));

        $rows = self::_all('SELECT id, kind, product_kind, title, title_auto, score, verdict,
                                   about, raw_text, producer_id
                            FROM nj_646_analyses
                            WHERE product_id IS NULL OR product_id = 0
                            ORDER BY id ASC LIMIT '.$limit); // по возрастанию: свежий разбор обработается последним и станет «последним» у товара

        $done = 0;

        foreach ($rows as $row)
        {
            $about = self::_json_decode($row['about'] ?? []);
            $data  = (array) ($about['product'] ?? []);

            $title = trim((string) $row['title']);
            $auto  = self::flag($row['title_auto']);

            if ($title !== '' AND trim((string) ($data['name'] ?? '')) === '')
            {
                $data['name']      = $title;
                $data['name_auto'] = $auto;
            }

            $kind_slug = (string) ($row['product_kind'] ?? '');

            if ($kind_slug === '')
            {
                $sample    = trim((string) ($data['name'] ?? '').' '.(string) $row['raw_text']);
                $kind_slug = (string) Service_Nj646_Label::product_kind($sample)['slug'];
            }

            $data['product_kind'] = $kind_slug;
            $data['kind']         = in_array((string) $row['kind'], ['food', 'cosmetic', 'both'], TRUE)
                ? (string) $row['kind'] : 'auto';

            // Производителя берём из снимка, если он не привязан к разбору.
            $producer_id = (int) ($row['producer_id'] ?? 0);

            if ($producer_id <= 0 AND ! empty($about['producers'][0]))
            {
                $saved = self::producer_upsert((array) $about['producers'][0], []);

                if ($saved)
                {
                    $producer_id = (int) $saved['id'];
                }
            }

            $product = self::product_upsert($data, $producer_id);

            if ( ! $product)
            {
                continue;
            }

            $set = ['product_id' => (int) $product['id']];

            if ($producer_id > 0)
            {
                $set['producer_id'] = $producer_id;
            }

            DB::update('nj_646_analyses')->set($set)->where('id', '=', (int) $row['id'])->execute();

            self::product_touch((int) $product['id'], (int) $row['id'], (int) $row['score'], (string) $row['verdict']);
            $done++;
        }

        return $done;
    }

    /**
     * Дочитать пищевую ценность у товаров, разобранных ДО появления этой таблицы.
     *
     * Колонка `nutrition` появилась в миграции 005, а разборы копились с
     * весны: у старых товаров таблица пустая, хотя текст этикетки лежит в
     * `nj_646_analyses.raw_text`. Метод перечитывает последний разбор товара
     * тем же парсером, что и живой поток (`About::nutrition`), и дополняет
     * карточку через `nutrition_merge()` — руками введённые цифры
     * (`nutrition_source = manual`) не трогаются.
     *
     * Идемпотентен: берёт только товары с пустой таблицей, пачкой (по
     * умолчанию 200), поэтому кнопку в админке можно жать сколько угодно.
     *
     * @param  int $limit сколько товаров смотреть за один заход
     * @return int сколько карточек получило пищевую ценность
     */
    public static function products_backfill_nutrition($limit = 200)
    {
        self::ensure_goods();
        self::ensure_indexes();

        $limit = max(1, min(500, (int) $limit));

        // Товары без таблицы, у которых есть хотя бы один разбор с текстом.
        $rows = self::_all("SELECT p.id,
                                   (SELECT a.raw_text FROM nj_646_analyses a
                                     WHERE a.product_id = p.id AND a.raw_text <> ''
                                     ORDER BY a.id DESC LIMIT 1) AS raw_text
                              FROM nj_646_products p
                             WHERE (p.nutrition IS NULL OR p.nutrition::text IN ('{}', '[]', 'null'))
                               AND p.nutrition_source <> 'manual'
                             ORDER BY p.id ASC LIMIT ".$limit);

        $done = 0;

        foreach ($rows as $row)
        {
            $text = trim((string) ($row['raw_text'] ?? ''));

            if ($text === '')
            {
                continue;
            }

            $found = Service_Nj646_About::nutrition($text);

            if ( ! $found)
            {
                continue;
            }

            DB::update('nj_646_products')
                ->set(['nutrition' => DB::expr(self::_nutrition_json($found)), 'nutrition_source' => 'label'])
                ->where('id', '=', (int) $row['id'])
                ->execute();

            $done++;
        }

        return $done;
    }

    public static function goods_counts()
    {
        self::ensure_goods();

        return [
            'producers' => (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_producers', 'c'),
            'products'  => (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_products', 'c'),
        ];
    }

    /** Товары производителя (карточка производителя на сайте). */
    public static function producer_products($producer_id, $limit = 30)
    {
        self::ensure_goods();

        return self::_all('SELECT * FROM nj_646_products
                            WHERE producer_id = '.(int) $producer_id.'
                            ORDER BY hits DESC, id DESC LIMIT '.(int) max(1, min(100, $limit)));
    }

    /** Разборы товара — по ним видно, менялся ли состав (и «какой чище»). */
    public static function product_analyses($product_id, $limit = 20)
    {
        self::ensure_tables();

        return self::_all('SELECT id, kind, source, title, score, verdict, counts, created_at, raw_text, unknown
                             FROM nj_646_analyses WHERE product_id = '.(int) $product_id.'
                             ORDER BY id DESC LIMIT '.(int) max(1, min(100, $limit)));
    }

    /** Разборы товаров производителя. */
    public static function producer_analyses($producer_id, $limit = 20)
    {
        self::ensure_tables();

        return self::_all('SELECT a.id, a.kind, a.source, a.title, a.score, a.verdict, a.created_at,
                                  p.name AS product_name, p.brand AS product_brand
                             FROM nj_646_analyses a
                             LEFT JOIN nj_646_products p ON p.id = a.product_id
                            WHERE a.producer_id = '.(int) $producer_id.'
                            ORDER BY a.id DESC LIMIT '.(int) max(1, min(100, $limit)));
    }

    /** Правка карточки производителя из админки (пустые поля не трогаем). */
    public static function producer_save($id, array $fields)
    {
        self::ensure_goods();

        $row = self::producer_get($id);

        if ( ! $row)
        {
            return FALSE;
        }

        $set = ['updated_at' => DB::expr('now()')];
        $allowed = ['name' => 300, 'role' => 16, 'address' => 400, 'country' => 80,
                    'inn' => 12, 'ogrn' => 15, 'phone' => 40, 'site' => 120];

        foreach ($allowed as $field => $max)
        {
            if (array_key_exists($field, $fields))
            {
                $value = trim((string) $fields[$field]);

                if ($field === 'inn' OR $field === 'ogrn')
                {
                    $value = preg_replace('/\D/', '', $value);
                }

                if ($field === 'role' AND ! in_array($value, ['producer', 'importer', 'address'], TRUE))
                {
                    continue;
                }

                $set[$field] = mb_substr($value, 0, $max);
            }
        }

        // Название меняется — пересчитываем ключ (одна запись на организацию).
        if (isset($set['name']) AND $set['name'] !== '' AND $set['name'] !== (string) $row['name'])
        {
            $key = Service_Nj646_About::producer_key((string) $set['name']);
            $clash = self::_one('SELECT id FROM nj_646_producers WHERE name_key = '.self::db()->quote($key)
                .' AND id <> '.(int) $id, 'id');

            if ($clash)
            {
                return FALSE;
            }

            $set['name_key'] = $key;
        }

        DB::update('nj_646_producers')->set($set)->where('id', '=', (int) $id)->execute();

        // Реквизиты могли появиться/измениться — связи с проектом обновляем.
        self::producer_link_external((int) $id);

        return TRUE;
    }

    /** Правка карточки товара из админки. */
    /**
     * Товар по ключу: сначала сам ключ, потом ПРЕЖНИЕ ключи (`key_aliases`).
     *
     * Ключ считается от названия, а название правят руками и уточняет модель.
     * Поиск только по `key` означал бы, что после каждой правки следующий
     * разбор заводит дубль (проверено на стенде 03.10.2026).
     */
    public static function product_by_key($key)
    {
        $key = (string) $key;

        if ($key === '')
        {
            return NULL;
        }

        $row = self::_one('SELECT * FROM nj_646_products WHERE key = '.self::db()->quote($key));

        if ($row)
        {
            return $row;
        }

        // `@>` по jsonb-массиву: ищем ключ среди прежних (GIN-индекс
        // `nj_646_products_aliases_idx`).
        return self::_one('SELECT * FROM nj_646_products
                            WHERE key_aliases @> '.self::_json([$key]).'::jsonb
                            ORDER BY id ASC LIMIT 1');
    }

    /**
     * Поля для UPDATE, когда у товара меняется ключ: новый `key` + старый в псевдонимы.
     *
     * Возвращает ПУСТОЙ массив, если переклеивать нечего: ключ не изменился
     * или новый ключ уже занят другим товаром (тогда это задача для «слияния
     * дублей», а не для тихой перезаписи — ключ уникален).
     *
     * @param  array $row     текущая строка товара
     * @param  array $changes новые значения полей (name/brand/barcode/name_auto)
     * @return array ['key' => …, 'key_aliases' => DB::expr(…)] или []
     */
    public static function product_rekey_set(array $row, array $changes)
    {
        $next = array_merge([
            'name'      => (string) ($row['name'] ?? ''),
            'brand'     => (string) ($row['brand'] ?? ''),
            'barcode'   => (string) ($row['barcode'] ?? ''),
            'name_auto' => self::flag($row['name_auto'] ?? FALSE),
        ], $changes);

        // Суффикс производителя участвует в ключе только у рабочих имён
        // («Мыло без названия» разных заводов — разные товары).
        $producer_id = (int) ($row['producer_id'] ?? 0);
        $suffix      = ( ! empty($next['name_auto']) AND $producer_id > 0) ? 'p'.$producer_id : '';
        $key         = Service_Nj646_About::product_key($next, $suffix);
        $current     = (string) ($row['key'] ?? '');

        if ($key === '' OR $key === $current)
        {
            return [];
        }

        $taken = self::_one('SELECT id FROM nj_646_products
                              WHERE key = '.self::db()->quote($key).'
                                AND id <> '.(int) ($row['id'] ?? 0));

        if ($taken)
        {
            Kohana::$log->add(Log::INFO, 'nj646: ключ товара :id не переклеен — он уже у товара :other (дубль чинится слиянием)',
                [':id' => (int) ($row['id'] ?? 0), ':other' => (int) $taken['id']]);

            return [];
        }

        $aliases = self::_json_decode($row['key_aliases'] ?? []);
        $aliases = array_values(array_unique(array_filter(array_merge(
            is_array($aliases) ? $aliases : [], [$current]), 'strlen')));

        // Держим список коротким: прежние ключи нужны, чтобы догнать разбор по
        // старой этикетке, а не как вечная история переименований.
        $aliases = array_slice($aliases, -10);

        return ['key' => $key, 'key_aliases' => DB::expr(self::_json($aliases))];
    }

    /**
     * Настоящее название от модели — на карточку товара.
     *
     * Задача `title` даёт название разбору (`analysis_title_set`), но товар
     * оставался с рабочим «<Тип> без названия»: следующий разбор ТОЙ ЖЕ
     * этикетки считал ключ от настоящего имени, не находил карточку и заводил
     * дубль. Меняем только рабочее имя (`name_auto`) и обязательно через
     * переклейку ключа — прежний уходит в псевдонимы.
     *
     * @return bool переименовали ли
     */
    public static function product_name_from_model($id, $name)
    {
        self::ensure_goods();

        $row = self::product_get((int) $id);

        if ( ! $row OR ! self::flag($row['name_auto'] ?? FALSE))
        {
            return FALSE;
        }

        $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $name)), 0, 200);

        if ($name === '' OR $name === (string) $row['name'])
        {
            return FALSE;
        }

        $set = ['name' => $name, 'name_auto' => DB::expr('FALSE'), 'updated_at' => DB::expr('now()')];
        $set += self::product_rekey_set($row, ['name' => $name, 'name_auto' => FALSE]);

        DB::update('nj_646_products')->set($set)->where('id', '=', (int) $row['id'])->execute();

        return TRUE;
    }

    public static function product_save($id, array $fields)
    {
        self::ensure_goods();

        $row = self::product_get($id);

        if ( ! $row)
        {
            return FALSE;
        }

        $set = ['updated_at' => DB::expr('now()')];
        $allowed = ['name', 'brand', 'kind', 'barcode', 'weight', 'gost', 'shelf_life', 'storage', 'country'];

        foreach ($allowed as $field)
        {
            if (array_key_exists($field, $fields))
            {
                $table = ['name' => 200, 'brand' => 120, 'kind' => 12, 'barcode' => 20, 'weight' => 40,
                          'gost' => 80, 'shelf_life' => 120, 'storage' => 200, 'country' => 80];
                $value = trim((string) $fields[$field]);

                if ($field === 'barcode')
                {
                    $value = preg_replace('/\D/', '', $value);
                }

                if ($field === 'kind' AND ! in_array($value, ['food', 'cosmetic', 'both', 'auto'], TRUE))
                {
                    continue;
                }

                $set[$field] = mb_substr($value, 0, $table[$field]);
            }
        }

        // Человек назвал товар сам — значит имя больше не рабочее, и разбор
        // этикетки не должен его переписывать.
        if (isset($set['name']) AND $set['name'] !== '' AND self::flag($row['name_auto'] ?? FALSE))
        {
            $set['name_auto'] = DB::expr('FALSE');
        }

        // Название, бренд и штрихкод входят в ключ товара. Правка руками без
        // переклейки означала бы, что следующий разбор той же этикетки заведёт
        // ВТОРОЙ товар, а разборы и пищевая ценность останутся на первом.
        $rekey = array_intersect_key($set, array_flip(['name', 'brand', 'barcode']));

        if (isset($set['name_auto']))
        {
            $rekey['name_auto'] = FALSE;
        }

        $set += self::product_rekey_set($row, $rekey);

        DB::update('nj_646_products')->set($set)->where('id', '=', (int) $id)->execute();

        return TRUE;
    }

    /**
     * Удалить производителя. Товары и разборы остаются, но теряют ссылку:
     * удаление карточки не должно уносить с собой историю разборов.
     */
    public static function producer_delete($id)
    {
        self::ensure_goods();

        $id = (int) $id;

        if ($id <= 0)
        {
            return FALSE;
        }

        DB::update('nj_646_products')->set(['producer_id' => DB::expr('NULL')])
            ->where('producer_id', '=', $id)->execute();
        DB::update('nj_646_analyses')->set(['producer_id' => DB::expr('NULL')])
            ->where('producer_id', '=', $id)->execute();
        DB::delete('nj_646_producers')->where('id', '=', $id)->execute();

        return TRUE;
    }

    /** Удалить товар: разборы остаются с сохранённым снимком `about`. */
    public static function product_delete($id)
    {
        self::ensure_goods();

        $id = (int) $id;

        if ($id <= 0)
        {
            return FALSE;
        }

        DB::update('nj_646_analyses')->set(['product_id' => DB::expr('NULL')])
            ->where('product_id', '=', $id)->execute();
        DB::delete('nj_646_products')->where('id', '=', $id)->execute();

        return TRUE;
    }

    /**
     * Дубли-подозрения среди производителей и товаров.
     *
     * Карточки растут из этикеток, а один завод на разных этикетках пишется
     * по-разному («ООО „Ромашка“» и «Ромашка-Трейд»). Правила сравнения — в
     * чистом `Service_Nj646_Merge`; здесь только сборка строк из базы с числом
     * разборов (по ним видно, какая карточка «потяжелее»).
     *
     * @return array ['producers' => [...], 'products' => [...]]
     */
    public static function goods_duplicates($limit = 200)
    {
        self::ensure_goods();

        $limit = (int) max(10, min(500, $limit));

        $producers = self::_all('SELECT p.*, (SELECT COUNT(*) FROM nj_646_analyses a
                                                  WHERE a.producer_id = p.id) AS analyses,
                                        (SELECT COUNT(*) FROM nj_646_products pr
                                                  WHERE pr.producer_id = p.id) AS products
                                   FROM nj_646_producers p
                                  ORDER BY p.hits DESC, p.id DESC
                                  LIMIT '.$limit);

        $products = self::_all('SELECT p.*, pr.name AS producer_name,
                                       (SELECT COUNT(*) FROM nj_646_analyses a
                                         WHERE a.product_id = p.id) AS analyses
                                  FROM nj_646_products p
                                  LEFT JOIN nj_646_producers pr ON pr.id = p.producer_id
                                 ORDER BY p.hits DESC, p.id DESC
                                 LIMIT '.$limit);

        return [
            'producers' => Service_Nj646_Merge::duplicates($producers, 'producer'),
            'products'  => Service_Nj646_Merge::duplicates($products, 'product'),
        ];
    }

    /**
     * Что переедет при объединении — для плана и для подписи кнопки.
     *
     * @return array ['analyses' => N, 'products' => M, 'glued' => K]
     */
    public static function goods_merge_counts($kind, $keep_id, $drop_id)
    {
        self::ensure_goods();

        $keep_id = (int) $keep_id;
        $drop_id = (int) $drop_id;

        $out = ['analyses' => 0, 'products' => 0, 'glued' => 0];

        if ($kind === 'product')
        {
            $out['analyses'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses
                                                  WHERE product_id = '.$drop_id, 'c');

            return $out;
        }

        $out['analyses'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses
                                              WHERE producer_id = '.$drop_id, 'c');

        // Товары второй карточки: одноимённые товарам главной придётся СЛИТЬ
        // (у `nj_646_products.key` уникальный индекс — просто переписать
        // `producer_id` нельзя), остальные переезжают как есть.
        foreach (self::_all('SELECT id, "key" AS k FROM nj_646_products WHERE producer_id = '.$drop_id) as $row)
        {
            $twin = (int) self::_one('SELECT id FROM nj_646_products WHERE "key" = '.self::db()->quote((string) $row['k']), 'id');

            if ($twin > 0 AND $twin !== (int) $row['id'])
            {
                $out['glued']++;
            }
        }

        $out['products'] = count(self::_all('SELECT id FROM nj_646_products WHERE producer_id = '.$drop_id))
            - $out['glued'];

        return $out;
    }

    /**
     * Слить двух производителей: вторая карточка исчезает, история переезжает.
     *
     * Главная карточка побеждает, её пустые поля заполняются из второй, `hits`
     * складываются. Товары второй переезжают на главную, а одноимённые (по
     * ключу) — сливаются с уже имеющимися: разборы уходят на существующий товар,
     * дубликат удаляется (иначе уникальный `key` не пустит переезд).
     *
     * @return array|null ['analyses' => N, 'products' => M, 'glued' => K] или NULL
     */
    public static function producer_merge($keep_id, $drop_id)
    {
        self::ensure_goods();

        $keep_id = (int) $keep_id;
        $drop_id = (int) $drop_id;

        if ($keep_id <= 0 OR $drop_id <= 0 OR $keep_id === $drop_id)
        {
            return NULL;
        }

        $keep = self::producer_get($keep_id);
        $drop = self::producer_get($drop_id);

        if ( ! $keep OR ! $drop)
        {
            return NULL;
        }

        $db = self::db();

        // Объединение — несколько записей сразу (товары, разборы, карточка),
        // поэтому одной транзакцией: половина переезда хуже, чем отказ.
        $db->begin();

        try
        {
            $moved = 0;
            $glued = 0;

            foreach (self::_all('SELECT id, "key" AS k FROM nj_646_products WHERE producer_id = '.$drop_id) as $row)
            {
                $twin = (int) self::_one('SELECT id FROM nj_646_products WHERE "key" = '.$db->quote((string) $row['k']), 'id');

                if ($twin > 0 AND $twin !== (int) $row['id'])
                {
                    DB::update('nj_646_analyses')->set(['product_id' => $twin])
                        ->where('product_id', '=', (int) $row['id'])->execute();

                    DB::delete('nj_646_products')->where('id', '=', (int) $row['id'])->execute();
                    $glued++;
                    continue;
                }

                DB::update('nj_646_products')->set(['producer_id' => $keep_id, 'updated_at' => DB::expr('now()')])
                    ->where('id', '=', (int) $row['id'])->execute();
                $moved++;
            }

            $analyses = (int) DB::update('nj_646_analyses')->set(['producer_id' => $keep_id])
                ->where('producer_id', '=', $drop_id)->execute();

            $set = ['updated_at' => DB::expr('now()'), 'hits' => (int) $keep['hits'] + (int) $drop['hits']];

            foreach (Service_Nj646_Merge::PRODUCER_FILL as $field)
            {
                if (trim((string) ($keep[$field] ?? '')) === '' AND trim((string) ($drop[$field] ?? '')) !== '')
                {
                    $set[$field] = $drop[$field];
                }
            }

            DB::update('nj_646_producers')->set($set)->where('id', '=', $keep_id)->execute();
            DB::delete('nj_646_producers')->where('id', '=', $drop_id)->execute();

            $db->commit();
        }
        catch (Throwable $e)
        {
            $db->rollback();

            Kohana::$log->add(Log::ERROR, 'nj646: слияние производителей не удалось: :e', [':e' => $e->getMessage()]);

            return NULL;
        }

        return ['analyses' => $analyses, 'products' => $moved, 'glued' => $glued];
    }

    /**
     * Слить два товара: разборы второй карточки переезжают, вторая исчезает.
     *
     * После переезда статистика главной пересчитывается по факту (последний
     * разбор, их число): счётчики двух карточек после слияния врут.
     *
     * @return array|null ['analyses' => N]
     */
    public static function product_merge($keep_id, $drop_id)
    {
        self::ensure_goods();

        $keep_id = (int) $keep_id;
        $drop_id = (int) $drop_id;

        if ($keep_id <= 0 OR $drop_id <= 0 OR $keep_id === $drop_id)
        {
            return NULL;
        }

        $keep = self::product_get($keep_id);
        $drop = self::product_get($drop_id);

        if ( ! $keep OR ! $drop)
        {
            return NULL;
        }

        $db = self::db();
        $db->begin();

        try
        {
            $analyses = (int) DB::update('nj_646_analyses')->set(['product_id' => $keep_id])
                ->where('product_id', '=', $drop_id)->execute();

            $set = ['updated_at' => DB::expr('now()'), 'hits' => (int) $keep['hits'] + (int) $drop['hits']];

            foreach (Service_Nj646_Merge::PRODUCT_FILL as $field)
            {
                if (trim((string) ($keep[$field] ?? '')) === '' AND trim((string) ($drop[$field] ?? '')) !== '')
                {
                    $set[$field] = $drop[$field];
                }
            }

            if (empty($keep['producer_id']) AND ! empty($drop['producer_id']))
            {
                $set['producer_id'] = (int) $drop['producer_id'];
            }

            $last = self::_one('SELECT id, score, verdict FROM nj_646_analyses
                                 WHERE product_id = '.$keep_id.' ORDER BY id DESC LIMIT 1');

            // Ключ исчезающего товара переезжает в псевдонимы главной карточки:
            // разбор по ТОЙ ЖЕ этикетке (в ней осталось старое название) после
            // слияния обязан попадать в главную, а не воскрешать дубль.
            $aliases = self::_json_decode($keep['key_aliases'] ?? []);
            $aliases = array_merge(is_array($aliases) ? $aliases : [], [(string) $drop['key']],
                (array) self::_json_decode($drop['key_aliases'] ?? []));
            $aliases = array_values(array_unique(array_filter($aliases, 'strlen')));
            $aliases = array_slice(array_diff($aliases, [(string) $keep['key']]), -10);

            $set['key_aliases'] = DB::expr(self::_json(array_values($aliases)));

            $set['analyses_count']   = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses
                                                          WHERE product_id = '.$keep_id, 'c');
            $set['last_analysis_id'] = $last ? (int) $last['id'] : DB::expr('NULL');
            $set['last_score']       = $last ? max(0, min(100, (int) $last['score'])) : DB::expr('NULL');

            if ($last)
            {
                $set['last_verdict'] = mb_substr((string) $last['verdict'], 0, 12);
            }

            DB::update('nj_646_products')->set($set)->where('id', '=', $keep_id)->execute();
            DB::delete('nj_646_products')->where('id', '=', $drop_id)->execute();

            $db->commit();
        }
        catch (Throwable $e)
        {
            $db->rollback();

            Kohana::$log->add(Log::ERROR, 'nj646: слияние товаров не удалось: :e', [':e' => $e->getMessage()]);

            return NULL;
        }

        return ['analyses' => $analyses, 'products' => 0, 'glued' => 0];
    }

    /**
     * Похожие вещества из той же категории — «что ещё бывает в таких составах».
     *
     * Карточка вещества сама по себе статична: человек прочитал про один
     * ингредиент и ушёл. Похожие (та же категория и вид, опубликованные,
     * чаще встречающиеся) дают следующий шаг. Само вещество исключаем.
     */
    public static function ingredient_similar(array $card, $limit = 6)
    {
        self::ensure_tables();

        $category = trim((string) ($card['category'] ?? ''));

        if ($category === '')
        {
            return [];
        }

        $kind = in_array((string) ($card['kind'] ?? ''), ['food', 'cosmetic', 'both'], TRUE)
            ? (string) $card['kind'] : 'both';

        return self::_all('SELECT slug, name_ru, name_int, risk, verdict, category, e_code, hits
                             FROM nj_646_ingredients
                            WHERE category = '.self::db()->quote($category).'
                              AND kind = '.self::db()->quote($kind).'
                              AND status = \'published\'
                              AND id <> '.(int) ($card['id'] ?? 0).'
                            ORDER BY hits DESC, name_ru
                            LIMIT '.(int) max(1, min(12, $limit)));
    }

    /** Всего разборов (для «показано N из M» в истории админа). */
    public static function analyses_count()
    {
        self::ensure_tables();

        return (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses', 'c');
    }

    /** Сколько разборов сделал посетитель за последний час (лимит). */
    public static function analyses_last_hour($visitor)
    {
        self::ensure_tables();

        $row = self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses
                           WHERE visitor = '.self::db()->quote((string) $visitor).'
                             AND created_at > now() - interval \'1 hour\'');

        return (int) ($row['c'] ?? 0);
    }

    /**
     * Поля строки списка разборов: сам разбор + ТОВАР и производитель.
     *
     * Владелец (03.10.2026): «показывать в разных списках (в моих разборах, у
     * админа и т.п.) разбор ТОВАРА, а не разбор с названием “63/100 ·
     * нейтрально”». Поэтому списки берут имя, бренд и тип продукта из товара,
     * а `title` разбора остаётся запасным вариантом (старые записи, удалённый
     * товар). Оценка и вердикт товара (`last_score`, `last_verdict`) — это итог
     * последнего разбора: по ним список сортируется и рисует значок, не считая
     * подзапрос по каждому товару.
     */
    protected static function _list_sql(array $extra = [])
    {
        self::ensure_goods();

        $columns = 'a.id, a.kind, a.product_kind, a.title, a.title_auto, a.source,
                       a.score, a.verdict, a.counts, a.unknown, a.raw_text, a.created_at,
                       p.id AS product_id, p.name AS product_name, p.brand AS product_brand,
                       p.name_auto AS product_name_auto, p.product_kind AS product_kind_slug,
                       p.last_score, p.last_verdict, p.analyses_count,
                       pr.id AS producer_id, pr.name AS producer_name';

        // Дополнительные колонки — ТОЛЬКО сюда: они часть списка SELECT, и попасть
        // после JOIN-ов не могут. На этом уже обожглись: `analyses_page()` клеила
        // свои колонки к готовой строке запроса, и админская история отвечала 500
        // («relation "a.user_id" does not exist») — ловит дым `LabelAdminTest`.
        if ($extra)
        {
            $columns .= ",\n                       ".implode(",\n                       ", $extra);
        }

        return 'SELECT '.$columns.'
                FROM nj_646_analyses a
                LEFT JOIN nj_646_products p ON p.id = a.product_id
                LEFT JOIN nj_646_producers pr ON pr.id = a.producer_id';
    }
}
