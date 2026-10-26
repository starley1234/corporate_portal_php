<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сопутствующая информация с этикетки — чистая логика, без БД и без ИИ.
 *
 * Владелец (03.10.2026): «сопутствующую информацию о производителе и товаре с
 * этикетки тоже в БД сохраняй (для проекта в целом)». Это ровно то, что
 * покупатель видит рядом с составом и что до сих пор нигде не оседало:
 * наименование и бренд товара, масса/объём, ГОСТ или ТУ, срок годности,
 * условия хранения, штрихкод, страна, ПИЩЕВАЯ ЦЕННОСТЬ (белки/жиры/углеводы,
 * сахара, соль, калорийность) — и производитель (он же импортёр):
 * название, адрес, ИНН/ОГРН, телефон, сайт.
 *
 * ПОЧЕМУ БЕЗ МОДЕЛИ. Соблазн попросить это у LLM велик, но цена — лишний
 * запрос на каждый разбор, а риск — выдуманный ИНН или ГОСТ, по которому потом
 * ищут организацию в справочнике. Разбор детерминированный: регулярные
 * выражения по строкам этикетки, проверка контрольных цифр ИНН/ОГРН и знака
 * EAN. Чего нет — того нет (`пусто = пусто`): страница показывает только
 * реально найденное, а в онтологию предприятий такие производители попадают
 * с пометкой источника `nj646`.
 *
 * Разбор работает и для фото: воркер переписывает строки этикетки в
 * `result.transcript`, а приёмка кладёт его в прогон — оттуда текст попадает
 * сюда целиком (состав — только часть этикетки).
 */
class Service_Nj646_About
{
    /** Роли организаций на этикетке: кто есть кто. */
    public static function roles()
    {
        return [
            'producer'  => 'Изготовитель',
            'importer'  => 'Импортёр',
            'address'   => 'Адрес производства',
        ];
    }

    /**
     * Разобрать текст этикетки: товар и организации.
     *
     * @param string $text строки этикетки (транскрипт фото) или текст состава
     *
     * @return array ['product' => [...], 'producers' => [...], 'nutrition' => [...]] — только найденное
     */
    public static function extract($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $flat = preg_replace('/[ \t]+/u', ' ', $text);

        $inn  = self::_number($flat, '/ИНН[\s:№-]*(\d{10}|\d{12})/u', 'inn_valid');
        $ogrn = self::_number($flat, '/ОГРН(?:ИП)?[\s:№-]*(\d{13}|\d{15})/u', 'ogrn_valid');

        $product = [
            // Наименование проверяется теми же правилами, что и строка над
            // составом (`label_name`): «Наименование: Мыло туалетное, ООО …,
            // сайт www.…» — это строка этикетки, а не имя товара.
            'name'       => self::label_name(self::_grab($flat, [
                '/наименование(?:\s+товара)?\s*[:\-]?\s*([^\n]{3,150})/ui',
                '/название(?:\s+товара)?\s*[:\-]?\s*([^\n]{3,150})/ui',
            ])),
            'brand'      => self::_grab($flat, [
                '/(?:торговая\s+марка|бренд|ТМ)\s*[:\-]?\s*([^\n]{2,80})/ui',
            ]),
            'weight'     => self::_clean(self::_grab($flat, [
                '/(?:масса\s+нетто|нетто|масса|объ[её]м)\s*[:\-]?\s*(\d[\d.,]*\s*(?:г|гр|кг|мг|мл|л|шт)\.?)/ui',
            ]), 40),
            'gost'       => self::_clean(self::_grab($flat, [
                '/\b(ГОСТ|ТУ)\s*[№:]?\s*(\d[\d.\-–—]{1,20}(?:\s*[-–—]\s*\d{2,4})?)/u',
            ]), 80, TRUE),
            'shelf_life' => self::_clean(self::_grab($flat, [
                '/(?:срок\s+годности|годен(?:\s+до|\s+в\s+течение)?|срок\s+хранения)\s*[:\-]?\s*([^\n]{2,120})/ui',
            ]), 120),
            'storage'    => self::_clean(self::_grab($flat, [
                '/(?:условия\s+хранения|хранить(?:\s+при)?|хранение)\s*[:\-]?\s*([^\n]{3,200})/ui',
            ]), 200),
            'country'    => self::_country($flat),
        ];

        // Штрихкод: принимаем только валидный EAN (иначе в «штрихкод» попадёт
        // номер партии или телефон). Российские префиксы 460–469 идут первыми.
        $product['barcode'] = self::_barcode($flat);

        // Наименование: если на этикетке его не написали явно — берём строку
        // над составом, но только если она и правда похожа на имя товара
        // (прежняя версия брала «что угодно»: владелец дал этикетку мыла, а в
        // название товара попал адрес сайта — так быть не должно).
        if ($product['name'] === '')
        {
            $product['name'] = self::label_name(self::_name_from_header($text));
        }

        $product['kind'] = Service_Nj646_Label::kind_guess($text, []);

        $producers = self::_producers($text, $inn, $ogrn, $product['country']);

        // Если ИНН/ОГРН на этикетке есть, а строки изготовителя нет — отдаём их
        // первой организации: без имени ключ не построить, а терять реквизиты
        // нельзя (по ним производитель и находится в справочнике организаций).
        if ($producers AND $inn !== '' AND $producers[0]['inn'] === '')
        {
            $producers[0]['inn'] = $inn;
        }

        if ($producers AND $ogrn !== '' AND $producers[0]['ogrn'] === '')
        {
            $producers[0]['ogrn'] = $ogrn;
        }

        // Контакты отдельными строками («сайт: …», «тел. …») — к производителю:
        // на этикетке они часто стоят ниже названия организации, а реквизиты
        // терять нельзя (владелец: «адрес не положил в атрибуты производителя»).
        self::_contacts_to_producer($producers, self::_contacts($text));

        // Пустые поля не отдаём вовсе: страница и админка показывают только то,
        // что действительно было на этикетке.
        $product   = array_filter($product, function ($v) { return (string) $v !== ''; });
        $producers = array_values(array_filter($producers, function ($p) { return (string) $p['name'] !== ''; }));

        if ( ! empty($product['kind']) AND $product['kind'] === 'auto')
        {
            unset($product['kind']);
        }

        // Пищевая ценность — отдельным ключом, а не полем товара: это таблица
        // (массив), а поля товара — строки, и фильтр пустых значений выше
        // привёл бы массив к строке («Array to string conversion»).
        $nutrition = self::nutrition($text);

        return ['product' => $product, 'producers' => $producers, 'nutrition' => $nutrition];
    }

    /** Ключ производителя — по нормализованному названию («одна запись на предприятие»). */
    public static function producer_key($name)
    {
        return hash('sha256', 'producer|'.self::normalize_name($name));
    }

    /**
     * Ключ товара: штрихкод важнее названия (он уникален), иначе бренд+название.
     * Завод-изготовитель в ключе не участвует: тот же товар у другого
     * изготовителя — это уже другой товар, но название у него будет другое.
     */
    public static function product_key(array $product, $producer_key = '')
    {
        $barcode = preg_replace('/\D/', '', (string) ($product['barcode'] ?? ''));

        if (strlen($barcode) >= 8)
        {
            return hash('sha256', 'barcode|'.$barcode);
        }

        $tail = trim((string) ($product['brand'] ?? '')).'|'.(string) ($product['name'] ?? '');

        // Рабочее имя («Мыло без названия») у разных производителей совпадает:
        // без изготовителя в ключе все такие товары слиплись бы в один.
        if ($producer_key !== '')
        {
            $tail .= '|'.$producer_key;
        }

        return hash('sha256', 'name|'.self::normalize_name($tail));
    }

    /**
     * Нормализация названия организации/товара: кавычки, регистр, лишние знаки.
     * «ООО «Ромашка»» и «ООО "РОМАШКА"» — одна и та же организация.
     */
    public static function normalize_name($name)
    {
        $name = mb_strtolower(trim((string) $name));
        $name = str_replace(['«', '»', '“', '”', '"', '”', "'", '`'], '', $name);
        $name = preg_replace('/[.,;:]+/u', ' ', $name);
        $name = preg_replace('/\s+/u', ' ', $name);

        return trim($name);
    }

    /**
     * Разбор строк этикетки на организаций.
     *
     * Этикетка почти всегда даёт одну-две строки: «Изготовитель: ООО …,
     * адрес …, ИНН …» и «Импортёр: …». Берём КАЖДУЮ подходящую строку, а не
     * только первую: у импортных товаров их две, и обе нужны проекту.
     */
    protected static function _producers($text, $inn, $ogrn, $country)
    {
        $patterns = [
            'producer' => [
                '/^[^:\n]{0,30}(?:изготовитель|производитель|изготовлено|произведено|производство)[^:\n]{0,20}[:\-]\s*(.+)$/umi',
                '/^[^:\n]{0,30}(?:изготовитель|производитель|изготовлено|произведено|производство)\s+(.+)$/umi',
            ],
            'importer' => [
                '/^[^:\n]{0,30}(?:импорт[её]р|ввозит|поставщик)[^:\n]{0,20}[:\-]\s*(.+)$/umi',
            ],
            'address' => [
                '/^[^:\n]{0,30}адрес(?:\s+производства|\s+изготовителя)?[^:\n]{0,10}[:\-]\s*(.+)$/umi',
            ],
        ];

        $out = [];

        foreach ($patterns as $role => $list)
        {
            foreach ($list as $re)
            {
                if ( ! preg_match_all($re, $text, $m))
                {
                    continue;
                }

                foreach ($m[1] as $raw)
                {
                    $parsed = self::_organisation($raw, $role, $inn, $ogrn, $country);

                    if ($parsed['name'] === '' AND $parsed['address'] === '')
                    {
                        continue;
                    }

                    $key = self::producer_key($parsed['name'] !== '' ? $parsed['name'] : $parsed['address']);

                    if (isset($out[$key]))
                    {
                        // Та же организация второй строкой (адрес отдельно) —
                        // дополняем, а не плодим записи.
                        foreach ($parsed as $field => $value)
                        {
                            if ($value !== '' AND $value !== NULL AND $out[$key][$field] === '')
                            {
                                $out[$key][$field] = $value;
                            }
                        }

                        continue;
                    }

                    $out[$key] = $parsed;
                }
            }
        }

        return array_values($out);
    }

    /**
     * Разобрать одну строку организации: название, адрес и реквизиты.
     *
     * Строка этикетки выглядит как «ООО «Ромашка», 141000, Московская обл.,
     * г. Мытищи, ул. Ленина, д. 1, ИНН 5001000000» — название отделяем от
     * адреса по маркерам (город, улица, индекс и т. п.), а служебные хвосты
     * (ИНН, телефон, сайт) вырезаем в свои поля.
     */
    protected static function _organisation($raw, $role, $inn, $ogrn, $country)
    {
        $line = trim(preg_replace('/\s+/u', ' ', (string) $raw));
        // Служебные хвосты дальше разбираются отдельно — из имени убираем.
        $line = preg_replace('/\b(?:ИНН|ОГРН(?:ИП)?|КПП)\b[\s:№-]*\d{9,15}/ui', ' ', $line);
        $line = preg_replace('/\bтел\.?[\s:№-]*[+\d][\d ()-]{6,20}/ui', ' ', $line);
        $line = preg_replace('#https?://\S+#ui', ' ', $line);

        $site = self::_grab($line, [
            '#((?:[a-z0-9][a-z0-9\-]{1,60}\.)+(?:ru|com|su|net|org|biz|info|pro)(?:/\S*)?)#ui',
        ]);
        $site = trim($site, " .,;");
        $line = trim(str_replace($site, ' ', $line));

        $phone = self::_grab($line, [
            '/(?:тел\.?|телефон|факс)[\s:№-]*([+\d][\d ()-]{6,20})/ui',
        ]);
        $line = trim(preg_replace('/(?:тел\.?|телефон|факс)[\s:№-]*[+\d][\d ()-]{6,20}/ui', ' ', $line));
        $line = trim(preg_replace('/\s*[,\-–—]+\s*$/', '', $line));

        $address_marker = '/\b(ул|улица|д|дом|кв|г|город|обл|область|край|респ|район|р-н|пр-т|проспект|проезд|ш|шоссе|пер|переулок|наб|набережная|стр|строение|корп|корпус|мкр|микрорайон|тер|пос|посёлок|пгт|а\/я|индекс)\.?\s*[\d«"А-ЯЁa-z]|^\d{5,6}\b|Россия|Российская Федерация|Республика|Казахстан|Беларусь/u';

        $parts = preg_split('/\s*,\s*/u', $line);
        $name_parts = [];
        $address_parts = [];
        $in_address = FALSE;

        foreach ($parts as $part)
        {
            $part = trim($part);

            if ($part === '')
            {
                continue;
            }

            $starts_address = ( ! $in_address AND (
                preg_match($address_marker, $part)
                OR preg_match('/^\d{5,6}\b/u', $part)
            ));

            if ($starts_address)
            {
                $in_address = TRUE;
            }

            if ($in_address)
            {
                $address_parts[] = $part;
            }
            else
            {
                $name_parts[] = $part;
            }
        }

        // Название длиннее 200 знаков — это уже не название, значит склейка.
        $name = self::_clean(implode(', ', $name_parts), 200);
        $name = preg_replace('/^(?:изготовитель|производитель|импорт[её]р|поставщик|адрес[^:]*)[:\-\s]+/ui', '', $name);
        $name = trim((string) $name, " ,;.-");

        $address = self::_clean(implode(', ', $address_parts), 400);
        $address = preg_replace('/^(?:изготовитель|производитель|импорт[её]р|поставщик)[:\-\s]+/ui', '', $address);
        $address = trim((string) $address, " ,;.-");

        // Роль «адрес» — это адрес, а не ещё одна организация: если имени нет,
        // строку не выбрасываем, но и организацией не считаем.
        if ($role === 'address')
        {
            $address = $address !== '' ? $address : $name;
            $name    = '';
        }

        return [
            'role'    => $role,
            'name'    => (string) $name,
            'address' => (string) $address,
            'inn'     => self::inn_valid($inn) ? $inn : '',
            'ogrn'    => self::ogrn_valid($ogrn) ? $ogrn : '',
            'phone'   => self::_clean($phone, 40),
            'site'    => self::_clean($site, 120),
            'country' => (string) $country,
        ];
    }

    /** Страна: явная запись или вывод из адреса («Россия», «Республика Беларусь»). */
    protected static function _country($text)
    {
        $country = self::_clean(self::_grab($text, [
            '/(?:страна\s+(?:происхождения|изготовления|производства)|сделано\s+в|произведено\s+в)[\s:\-]*([A-Za-zА-Яа-яЁё][A-Za-zА-Яа-яЁё\-\s]{2,50})/ui',
        ]), 60);

        if ($country !== '')
        {
            return $country;
        }

        foreach (['Российская Федерация' => 'Россия', 'Россия' => 'Россия', 'Республика Беларусь' => 'Беларусь',
                  'Беларусь' => 'Беларусь', 'Казахстан' => 'Казахстан', 'Узбекистан' => 'Узбекистан'] as $needle => $label)
        {
            if (preg_match('/'.preg_quote($needle, '/').'/u', $text))
            {
                return $label;
            }
        }

        return '';
    }

    /** Наименование из первой строки этикетки — только если это не служебная строка. */
    protected static function _name_from_header($text)
    {
        foreach (array_slice(self::_lines($text), 0, 3) as $line)
        {
            $line = trim($line);

            if (mb_strlen($line) < 3 OR mb_strlen($line) > 120)
            {
                continue;
            }

            if (preg_match('/^(состав|ингредиенты|продукт|пищевая ценность|энергетическая|хранить|срок|годен|изготовитель|производитель|адрес|ТУ|ГОСТ)\b/ui', $line))
            {
                continue;
            }

            // Строка состава («сахар, соль, …») названием не бывает.
            if (substr_count($line, ',') >= 2)
            {
                continue;
            }

            // Строку проверяют те же правила, что и явное «Наименование»
            // (контакты, реквизиты, адрес, юрлицо): имя берём первое годное.
            $name = self::label_name(self::_name_cut($line));

            if ($name !== '')
            {
                return $name;
            }
        }

        return '';
    }

    /** Штрихкод: первый валидный EAN; российские префиксы (460–469) — вперёд. */
    protected static function _barcode($text)
    {
        if ( ! preg_match_all('/\b(\d{13}|\d{12}|\d{8})\b/u', $text, $m))
        {
            return '';
        }

        $ru = '';
        $any = '';

        foreach ($m[1] as $digits)
        {
            if ( ! self::ean_valid($digits))
            {
                continue;
            }

            if ($ru === '' AND preg_match('/^(46\d)/', $digits))
            {
                $ru = $digits;
            }

            if ($any === '')
            {
                $any = $digits;
            }
        }

        return $ru !== '' ? $ru : $any;
    }

    /**
     * Название товара для списка разборов: «Утренний кефир 1 %», «Шоколад
     * „Алёнка“». Нужно человеку, а не базе: по составу список не читается.
     *
     * Порядок поиска:
     *   1. ЯВНЫЕ пометки этикетки («Наименование», «Название», «Торговая
     *      марка») — их даёт extract(): там название написано прямо;
     *   2. строки ПЕРЕД началом состава — на фото-транскрипте название обычно
     *      стоит над составом (после состава его искать бессмысленно: это уже
     *      ингредиенты);
     *   3. пусто — вызывающий сам решает, что поставить (см. Store::title_auto:
     *      он даёт рабочее имя по типу продукта и помечает его как рабочее).
     *
     * Ничего не выдумываем: нет названия на этикетке — вернём пусто. Строки
     * вида «Наименование: …» проверяются `label_name()` — она же отсекает
     * адреса сайтов, телефоны, ГОСТ и реквизиты.
     */
    public static function product_title($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);

        $about = self::extract($text);

        foreach ([$about['product']['name'] ?? '', $about['product']['brand'] ?? ''] as $given)
        {
            $given = self::_title_clean($given);

            if ($given !== '')
            {
                return $given;
            }
        }

        $lines = preg_split('/\n/u', $text);
        $before = [];

        foreach ((array) $lines as $line)
        {
            $line = self::_title_clean($line);

            // Начался состав — выше него названия не бывает.
            if (self::_composition_line($line))
            {
                break;
            }

            if ($line !== '')
            {
                $before[] = $line;
            }

            if (count($before) >= 6)
            {
                break;
            }
        }

        // Ближе к составу стоит само название (выше — бренд, вес, «ГОСТ»):
        // идём с конца и берём первую строку, похожую на название товара.
        foreach (array_reverse($before) as $line)
        {
            if (self::_title_looks_like_name($line))
            {
                return mb_substr($line, 0, 120);
            }
        }

        return '';
    }

    /** Строка начинает состав (или ингредиенты) — выше неё название. */
    protected static function _composition_line($line)
    {
        if ($line === '')
        {
            return FALSE;
        }

        return (bool) preg_match(
            '/^(состав|ингредиент|ingredients|aqua|water|вода|water\s*\(aqua\)|aqua\s*\(water\))\b/ui',
            $line);
    }

    /**
     * Строка — ГОДНОЕ название товара? Строгие правила, одно место на весь класс.
     *
     * Владелец (03.10.2026): «товар определяй лучше… я дал этикетку мыла, а он в
     * название товара положил адрес сайта». Поэтому имя проходит проверку
     * четырьмя ситами: длина и буквы, служебные слова, контакты (сайт, почта,
     * телефон, «интернет-магазин»), реквизиты и адрес.
     *
     * @param string $value строка-кандидат (может быть с «Наименование: …»)
     */
    public static function label_name($value)
    {
        $value = self::_name_cut((string) $value);

        return self::_title_looks_like_name($value) ? $value : '';
    }

    /**
     * Обрезать строку до самого названия: дальше служебный текст («состав»,
     * «изготовитель», «адрес», «сайт»), вес и реквизиты — это уже не имя.
     */
    protected static function _name_cut($value)
    {
        $value = self::_title_clean($value);
        $value = preg_split(
            '/\b(?:состав|ингредиент|изготовит|производител|импорт[её]р|адрес|срок|годен|хранить|услови[яе]|масса|нетто|брутто|госстандарт|гост|ту\s|штрих|партия|дата\s+изготовл|тел\.?|телефон|факс|инн|огрн|кпп|сайт|www|http)/ui',
            $value, 2)[0];

        // «Шоколад молочный „Алёнка“, 100 г» — имя в первой части, но если она
        // короткая («Мыло»), вторая часть уточняет и её стоит оставить.
        $parts = preg_split('/\s*,\s*/u', self::_title_clean($value));

        if (count($parts) > 1)
        {
            $value = (mb_strlen($parts[0]) >= 8 OR count($parts) > 2)
                ? $parts[0] : $parts[0].', '.$parts[1];
        }

        return trim(preg_replace('/\s+/u', ' ', $value), " ,;.-–—");
    }

    /** Контакты отдельными строками этикетки: сайт, почта, телефон. */
    protected static function _contacts($text)
    {
        return [
            'site'  => trim(self::_grab($text, [
                '/\b((?:[a-z0-9][a-z0-9\-]{1,60}\.)+(?:ru|com|su|net|org|biz|info|pro)(?:\/\S*)?)/ui',
                '/\bсайт\s*[:\-]?\s*([^\n]{3,120})/ui',
            ]), " .,;"),
            'email' => trim(self::_grab($text, [
                '/\b([\w.\-]+@[\w\-]+\.[a-z]{2,10})/ui',
            ]), " .,;"),
            'phone' => trim(self::_grab($text, [
                '/(?:тел\.?|телефон|факс|горячая\s+линия)[\s:№-]*([+\d][\d ()-]{7,20})/ui',
            ]), " .,;"),
        ];
    }

    /** Контакты, стоящие отдельной строкой, — первому производителю. */
    protected static function _contacts_to_producer(array &$producers, array $contacts)
    {
        if ( ! $producers)
        {
            return;
        }

        foreach ($contacts as $field => $value)
        {
            if ($value !== '' AND (string) $producers[0][$field] === '')
            {
                $producers[0][$field] = $value;
            }
        }
    }

    /** Строка похожа на НАЗВАНИЕ товара, а не на реквизит, вес и служебный текст. */
    protected static function _title_looks_like_name($line)
    {
        $line = self::_title_clean($line);
        $len  = mb_strlen($line);

        if ($len < 3 OR $len > 80)
        {
            return FALSE;
        }

        // Одни цифры, даты, телефоны, штрихкоды, «0,5 л», «100 г» — не название.
        if (preg_match('/^[\d\s\.,\-\/\+:№%×*]+$/u', $line)
            OR preg_match('/^[+\d\s\-()]{7,}$/u', $line)
            OR preg_match('~^https?://~ui', $line)
            OR preg_match('~\S+@\S+\.\S+~u', $line))
        {
            return FALSE;
        }

        // Контакты и место торговли названием не бывают: «www.…», «…-shop.ru»,
        // «интернет-магазин», «официальный сайт».
        if (preg_match('~\b(?:www\.|сайт|интернет-магазин|официальн\w+ сайт|магазин)\b~ui', $line)
            OR preg_match('~\b[a-z0-9\-]{2,}\.(?:ru|com|su|net|org|biz|info|pro)\b~ui', $line)
            OR preg_match('~\b(?:8|7)?[\s\-]?\(?\d{3}\)?[\s\-]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}\b~u', $line))
        {
            return FALSE;
        }

        // Реквизиты и адрес: «ИНН 5001000000», «ул. Ленина, д. 1», «Московская
        // обл.». Хвостовая точка уже срезана `_title_clean`, поэтому слова
        // проверяются как слова («обл\.» не сработало бы на «Московская обл»).
        if (preg_match('~\b(?:инн|огрн|кпп|ул|обл|край|респ|район|р-н|кв|стр|корп|наб|пер|проезд|проспект|шоссе|микрорайон|пгт|пос|рп|а/я|д|г)\b\s*[\dА-ЯЁA-Z]?~ui', $line)
            AND preg_match('~\b(?:инн|огрн|кпп|ул|обл|край|респ|район|р-н|кв|стр|корп|наб|пер|проезд|проспект|шоссе|микрорайон|пгт|пос|рп|а/я)\b|\b[дг]\b\s*[\dА-ЯЁA-Z]~ui', $line))
        {
            return FALSE;
        }

        // Юрлицо («ООО «Ромашка»», «ИП Иванов») — это организация, не товар.
        if (preg_match('/^(?:ООО|ОАО|ЗАО|ПАО|АО|ИП|ОО|ТОО|ЧП)\b/ui', $line))
        {
            return FALSE;
        }

        // Служебные строки этикетки — точно не название.
        if (preg_match(
            '/\b(состав|ингредиент|ингредиенты|энергетическ|пищевая ценност|срок годност|годен до|условия хранен|хранить|изготовит|импорт|адрес|товарн(ый|ого) знак|знак обращения|штрих|госстандарт|гост|ту\s|транспорт|упакован|партия|дата изготовл|белк|жир|углевод|ккал|кдж|масса нетто|нетто|брутто|допускается|не допускается)\b/ui',
            $line))
        {
            return FALSE;
        }

        // «0,5 л», «450 г», «180 мл» — это вес рядом с названием, не название.
        if (preg_match('/^[\d\s\.,]+\s*(г|гр|кг|мг|мл|л|шт|см)\b/ui', $line))
        {
            return FALSE;
        }

        // ИНН/ОГРН-подобные номера.
        if (preg_match('/^(инн|огрн|огрнип)\b/ui', $line))
        {
            return FALSE;
        }

        // В строке должны быть буквы, и их должно быть больше, чем цифр.
        $letters = preg_match_all('/[A-Za-zА-Яа-яЁё]/u', $line);
        $digits  = preg_match_all('/\d/u', $line);

        return $letters >= 3 AND $letters >= $digits;
    }

    /** Причесать строку-кандидата: пробелы, кавычки, хвостовая пунктуация. */
    protected static function _title_clean($value)
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        if ($value === '')
        {
            return '';
        }

        // Кавычки и точки с концов не несут смысла: «„Алёнка“» → «Алёнка».
        // Парные кавычки снимаем ЗАБАЛАНСИРОВАННО: раскрывающая слева,
        // закрывающая справа — иначе «Мыло „Лаванда“» теряло закрывающую и
        // название выглядело оборванным.
        $value = trim($value, "\"'`.,;·-–— ");
        $value = preg_replace('/^[«„“”]+/u', '', $value);
        $value = preg_replace('/[»“”]+$/u', '', $value);
        $value = trim($value, "\"'`.,;·-–— ");

        // Пустая болтовня вроде «—» или «•••» названием не становится.
        if (mb_strlen($value) < 2 OR ! preg_match('/[A-Za-zА-Яа-яЁё]/u', $value))
        {
            return '';
        }

        return mb_substr($value, 0, 120);
    }

    /**
     * Контрольная цифра EAN-13/EAN-8/UPC-A: без неё «штрихкодом» становится
     * любой 13-значный номер (партия, дата-время в маркировке).
     */
    public static function ean_valid($digits)
    {
        $digits = preg_replace('/\D/', '', (string) $digits);
        $len = strlen($digits);

        if ( ! in_array($len, [8, 12, 13], TRUE))
        {
            return FALSE;
        }

        $sum = 0;

        // Считаем от последней цифры влево: чётные позиции (с конца) — вес 3.
        for ($i = $len - 2, $pos = 1; $i >= 0; $i--, $pos++)
        {
            $sum += (int) $digits[$i] * ($pos % 2 === 1 ? 3 : 1);
        }

        return ((10 - ($sum % 10)) % 10) === (int) $digits[$len - 1];
    }

    /** ИНН: только верные контрольные цифры (10 знаков — ЮЛ, 12 — ФЛ/ИП). */
    public static function inn_valid($inn)
    {
        $inn = preg_replace('/\D/', '', (string) $inn);

        if ( ! preg_match('/^\d{10}$|^\d{12}$/', $inn))
        {
            return FALSE;
        }

        $digits = array_map('intval', str_split($inn));

        if (strlen($inn) === 10)
        {
            $weights = [2, 4, 10, 3, 5, 9, 4, 6, 8];
            $sum = 0;

            foreach ($weights as $i => $w)
            {
                $sum += $w * $digits[$i];
            }

            return ($sum % 11 % 10) === $digits[9];
        }

        $w1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $w2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $s1 = 0;
        $s2 = 0;

        foreach ($w1 as $i => $w)
        {
            $s1 += $w * $digits[$i];
        }

        foreach ($w2 as $i => $w)
        {
            $s2 += $w * $digits[$i];
        }

        return ($s1 % 11 % 10) === $digits[10] AND ($s2 % 11 % 10) === $digits[11];
    }

    /** ОГРН (13) и ОГРНИП (15): контрольная цифра — младший разряд от деления на 11. */
    public static function ogrn_valid($ogrn)
    {
        $ogrn = preg_replace('/\D/', '', (string) $ogrn);
        $len  = strlen($ogrn);

        if ( ! in_array($len, [13, 15], TRUE))
        {
            return FALSE;
        }

        $body  = substr($ogrn, 0, -1);
        $check = (int) substr($ogrn, -1);

        // Остаток по модулю 11 считаем своей арифметикой: bcmath есть не на
        // каждой площадке, а ОГРН длиннее, чем влезает в целое безопасно.
        $rest = 0;

        foreach (str_split($body) as $digit)
        {
            $rest = ($rest * 10 + (int) $digit) % 11;
        }

        return ($rest % 10) === $check;
    }

    // ------------------------------------------------------------------
    // Пищевая ценность (для продуктов)
    // ------------------------------------------------------------------

    /**
     * Поля пищевой ценности: порядок показа, подпись, единица и потолок.
     *
     * Одно место на всё: разбор этикетки, форма админки, карточка товара и
     * проверка значений. Потолок — не придирка, а защита от OCR: «белки 755 г
     * на 100 г» не бывает, это склеенные цифры соседней колонки.
     */
    public static function nutrition_fields()
    {
        return [
            'kcal'    => ['title' => 'Калорийность',          'unit' => 'ккал', 'max' => 1000],
            'kj'      => ['title' => 'Энергия',               'unit' => 'кДж',  'max' => 4200],
            'protein' => ['title' => 'Белки',                 'unit' => 'г',    'max' => 100],
            'fat'     => ['title' => 'Жиры',                  'unit' => 'г',    'max' => 100],
            'fat_sat' => ['title' => 'в т. ч. насыщенные',    'unit' => 'г',    'max' => 100],
            'carbs'   => ['title' => 'Углеводы',              'unit' => 'г',    'max' => 100],
            'sugars'  => ['title' => 'в т. ч. сахара',        'unit' => 'г',    'max' => 100],
            'fiber'   => ['title' => 'Пищевые волокна',       'unit' => 'г',    'max' => 100],
            'salt'    => ['title' => 'Соль',                  'unit' => 'г',    'max' => 100],
            'sodium'  => ['title' => 'Натрий',                'unit' => 'г',    'max' => 100],
        ];
    }

    /** На что считается таблица: 100 г, 100 мл или порция. */
    public static function nutrition_bases()
    {
        return [
            '100g'    => 'на 100 г',
            '100ml'   => 'на 100 мл',
            'portion' => 'на порцию',
        ];
    }

    /**
     * Пищевая ценность с этикетки — БЕЗ МОДЕЛИ, как и остальное в этом классе.
     *
     * Владелец (03.10.2026): «добавь функционал сохранения пищевой ценности
     * (для продуктов)». Таблица «белки/жиры/углеводы/калорийность» есть почти
     * на каждой упаковке еды, и она ценнее состава для половины вопросов
     * («сколько сахара?», «насколько жирный?»). Просить её у модели — платить
     * за каждый разбор и рисковать выдуманными цифрами; регулярные выражения
     * по строкам этикетки дают либо правду, либо пусто.
     *
     * Три правила, на которых держится разбор:
     *
     *   1. ОКНО. Числа на этикетке — это ещё и масса, цена, ГОСТ и телефон.
     *      Поэтому ищем только там, где этикетка сама объявила таблицу
     *      («пищевая ценность», «энергетическая ценность», «nutrition»).
     *   2. ЧАСТНОЕ ВПЕРЁД ОБЩЕГО. «в том числе насыщенные» разбирается раньше
     *      «жиров», «в том числе сахара» — раньше «углеводов», и найденный
     *      кусок вырезается: иначе общее поле заберёт чужое число.
     *   3. ПРОМЕЖУТОК ЧИСТЫЙ. Между названием и числом не должно быть другого
     *      названия («белки/жиры/углеводы — 7,5/24/58») и перевода строки:
     *      пусто честнее, чем белки, записанные в жиры.
     *
     * @param  string $text строки этикетки (транскрипт фото) или текст состава
     * @return array  ['basis' => '100g|100ml|portion', 'portion' => '…', 'values' => [...]] или []
     */
    public static function nutrition($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // Неразрывные и тонкие пробелы приходят из транскрипта модели: без
        // замены «7,5 г» с узким пробелом не совпадёт ни с одним шаблоном.
        $text = str_replace(["\xC2\xA0", "\xE2\x80\x89", "\xE2\x80\xAF"], ' ', $text);
        $flat = preg_replace('/[ \t]+/u', ' ', $text);
        $low  = mb_strtolower((string) $flat);

        $anchor = '/(пищева\w*\s+(?:и\s+энергетическ\w*\s+)?ценност|энергетическ\w*\s+ценност|калорийност|nutrition(?:al)?(?:\s+(?:facts|information|value))?)/u';

        if ( ! preg_match($anchor, $low, $m, PREG_OFFSET_CAPTURE))
        {
            return [];
        }

        // Окно: от объявления таблицы и дальше. Таблица на этикетке короткая —
        // 700 знаков хватает и на двуязычную («на 100 г / per 100 g»).
        $window = substr($low, (int) $m[0][1], 700);
        $values = self::_nutrition_values($window);

        if ( ! $values)
        {
            return [];
        }

        $basis = '100g';

        if (preg_match('/на\s*100\s*мл|per\s*100\s*ml/u', $window))
        {
            $basis = '100ml';
        }
        elseif ( ! preg_match('/на\s*100\s*(?:г|гр|g)\b|per\s*100\s*g\b/u', $window)
            AND preg_match('/на\s*(?:одну\s*)?порци|per\s*serving/u', $window))
        {
            $basis = 'portion';
        }

        $portion = self::_clean(self::_grab($window, [
            '/порци[яюи][^\d\n]{0,12}(\d+[.,]?\d*\s*(?:г|гр|мл|g|ml))/u',
            '/serving[^\d\n]{0,12}(\d+[.,]?\d*\s*(?:g|ml))/u',
        ]), 20);

        return ['basis' => $basis, 'portion' => $portion, 'values' => $values];
    }

    /**
     * Числа таблицы: поле → значение в граммах (кроме ккал/кДж).
     *
     * Миллиграммы приводим к граммам сразу (натрий часто печатают в мг): в базе
     * одна единица на поле, иначе карточка товара сравнивала бы граммы с
     * миллиграммами.
     */
    protected static function _nutrition_values($window)
    {
        // Частное раньше общего: насыщенные — до жиров, сахара — до углеводов.
        $patterns = [
            'fat_sat' => '(?:в\s*т(?:ом)?\.?\s*ч(?:исле)?\.?\s*)?насыщенн\w*(?:\s+жирн\w*)?(?:\s+кислот\w*)?|saturates|saturated\s+fat',
            'sugars'  => '(?:в\s*т(?:ом)?\.?\s*ч(?:исле)?\.?\s*)?сахар\w*|of\s+which\s+sugars|sugars',
            'fiber'   => 'пищевы\w*\s+волокн\w*|клетчатк\w*|fibre|fiber',
            'protein' => 'белк\w*|protein',
            'fat'     => 'жир\w*|fat',
            'carbs'   => 'углевод\w*|carbohydrates?|carbs',
            'salt'    => 'сол[ьи]|salt',
            'sodium'  => 'натри[йя]|sodium',
            'kcal'    => 'ккал|kcal',
            'kj'      => 'кдж|kj',
        ];

        // Слова других полей в промежутке — признак «цифра не от этого поля».
        $foreign = '/(белк|жир|углевод|сахар|сол[ьи]|натри|волокн|клетчатк|насыщ|энерг|ккал|кдж|protein|fat|carb|sugar|salt|sodium|fibre|fiber|energy|kcal|kj)/u';

        // Для взгляда НАЗАД энергия не считается чужим названием: у неё число
        // стоит ПЕРЕД словом («42 ккал, жиры 1,5 г»), и строка «…ккал, жиры»
        // иначе читалась бы как перечисление названий без значений.
        $foreign_names = '/(белк|жир|углевод|сахар|сол[ьи]|натри|волокн|клетчатк|насыщ|protein|fat|carb|sugar|salt|sodium|fibre|fiber)/u';

        $fields = self::nutrition_fields();
        $rest   = (string) $window;
        $out    = [];

        foreach ($patterns as $field => $label_re)
        {
            // Единицы энергии стоят ПОСЛЕ числа («480 ккал»), всё остальное —
            // после названия («белки 7,5 г»).
            $re = in_array($field, ['kcal', 'kj'], TRUE)
                ? '/(\d+(?:[.,]\d+)?)\s*(?:'.$label_re.')/u'
                : '/(?:'.$label_re.')([^\d\n]{0,20}?)(\d+(?:[.,]\d+)?)\s*(мг|г|гр|mg|g)?/u';

            if ( ! preg_match($re, $rest, $m, PREG_OFFSET_CAPTURE))
            {
                continue;
            }

            if (in_array($field, ['kcal', 'kj'], TRUE))
            {
                $value = (float) str_replace(',', '.', $m[1][0]);
                $unit  = '';
            }
            else
            {
                // Промежуток занят чужим названием — это соседняя колонка.
                if (preg_match($foreign, (string) $m[1][0]))
                {
                    continue;
                }

                // Перечисление «белки/жиры/углеводы — 7,5/24/58»: чужое название
                // стоит ВПЛОТНУЮ перед нашим — только хвост слова и разделитель,
                // ни одной цифры между ними. Значит число принадлежит первому
                // полю списка, а колонок у этикетки больше, чем мы разберём.
                $before = substr($rest, max(0, (int) $m[0][1] - 40), min(40, (int) $m[0][1]));

                if (preg_match_all($foreign_names, $before, $fm, PREG_OFFSET_CAPTURE))
                {
                    $last = end($fm[0]);
                    $tail = substr($before, (int) $last[1] + strlen((string) $last[0]));

                    if (preg_match('/^\p{L}{0,8}\s*[\/,;·|]\s*$/u', $tail))
                    {
                        continue;
                    }
                }

                $value = (float) str_replace(',', '.', $m[2][0]);
                $unit  = isset($m[3][0]) ? (string) $m[3][0] : '';
            }

            if (in_array($unit, ['мг', 'mg'], TRUE))
            {
                $value = $value / 1000;
            }

            $max = (float) ($fields[$field]['max'] ?? 1000);

            if ($value < 0 OR $value > $max)
            {
                continue;
            }

            $out[$field] = round($value, 3);

            // Разобранный кусок вырезаем: общее поле («жиры») не должно
            // забрать число частного («в том числе насыщенные»).
            $rest = substr_replace($rest, ' ', (int) $m[0][1], strlen((string) $m[0][0]));
        }

        return $out;
    }

    /**
     * Привести произвольный массив (форма админки, старая запись) к хранимому виду.
     *
     * Единственная дверь проверки: и разбор этикетки, и правка руками проходят
     * через неё — в базу не попадёт ни «очень много», ни 755 г белка.
     */
    public static function nutrition_sanitize($data)
    {
        $data = is_array($data) ? $data : [];
        // Скобки обязательны: `AND` связывает слабее `?:` и `=` — без них в
        // $in уезжал результат первого isset (правило модуля, php_ternary_lint).
        $in   = (isset($data['values']) AND is_array($data['values'])) ? $data['values'] : $data;
        $out  = [];

        foreach (self::nutrition_fields() as $field => $spec)
        {
            if ( ! array_key_exists($field, $in))
            {
                continue;
            }

            $raw = $in[$field];

            if ($raw === NULL OR (is_string($raw) AND trim($raw) === ''))
            {
                continue;
            }

            $text = str_replace([' ', "\xC2\xA0", ','], ['', '', '.'], (string) $raw);

            // «много», «н/д», «—» — это не число: (float) сделал бы из них 0,
            // и в карточке появился бы честный на вид ноль вместо пустоты.
            if ( ! is_numeric($text))
            {
                continue;
            }

            $value = (float) $text;

            if ( ! is_finite($value) OR $value < 0 OR $value > (float) $spec['max'])
            {
                continue;
            }

            $out[$field] = round($value, 3);
        }

        if ( ! $out)
        {
            return [];
        }

        $basis = (string) ($data['basis'] ?? '100g');

        return [
            'basis'   => array_key_exists($basis, self::nutrition_bases()) ? $basis : '100g',
            'portion' => self::_clean((string) ($data['portion'] ?? ''), 20),
            'values'  => $out,
        ];
    }

    /**
     * Что таблица говорит о продукте: короткие нейтральные пометки.
     *
     * Это НЕ диагноз и не рекомендация (правило модуля «совет — не диагноз»):
     * пороги взяты из общепринятых ориентиров маркировки (ВОЗ и британская
     * FSA: сахара 22,5 г, насыщенные жиры 5 г, соль 1,5 г на 100 г — «много»;
     * 5 г / 1,5 г / 0,3 г — «мало»), и порог назван прямо в тексте, чтобы
     * человек видел, с чем сравнили.
     *
     * Считаются только таблицы на 100 г / 100 мл: «на порцию» порог не
     * применим — порция у каждого своя.
     */
    public static function nutrition_flags($nutrition)
    {
        $nutrition = self::nutrition_sanitize($nutrition);

        if ( ! $nutrition OR $nutrition['basis'] === 'portion')
        {
            return [];
        }

        $v    = $nutrition['values'];
        $per  = $nutrition['basis'] === '100ml' ? '100 мл' : '100 г';
        $out  = [];

        $rules = [
            ['sugars',  22.5, 'high', 'много сахаров: :value г на '.$per.' (ориентир «много» — от 22,5 г)'],
            ['sugars',   5.0, 'low',  'мало сахаров: :value г на '.$per.' (ориентир «мало» — до 5 г)'],
            ['fat_sat',  5.0, 'high', 'много насыщенных жиров: :value г на '.$per.' (ориентир «много» — от 5 г)'],
            ['salt',     1.5, 'high', 'много соли: :value г на '.$per.' (ориентир «много» — от 1,5 г)'],
            ['salt',     0.3, 'low',  'мало соли: :value г на '.$per.' (ориентир «мало» — до 0,3 г)'],
            ['fiber',    6.0, 'high', 'много пищевых волокон: :value г на '.$per.' (от 6 г — «высокое содержание»)'],
        ];

        foreach ($rules as $rule)
        {
            [$field, $limit, $kind, $text] = $rule;

            if ( ! isset($v[$field]))
            {
                continue;
            }

            $hit = $kind === 'high' ? $v[$field] >= $limit : $v[$field] <= $limit;

            if ( ! $hit OR isset($out[$field]))
            {
                continue;
            }

            $out[$field] = [
                'field' => $field,
                'kind'  => $kind === 'high' ? ($field === 'fiber' ? 'good' : 'warn') : 'good',
                'text'  => str_replace(':value', self::nutrition_number($v[$field]), $text),
            ];
        }

        // Соль на этикетке могли написать натрием: 1 г натрия ≈ 2,5 г соли.
        if ( ! isset($out['salt']) AND isset($v['sodium']) AND $v['sodium'] * 2.5 >= 1.5)
        {
            $out['salt'] = [
                'field' => 'sodium',
                'kind'  => 'warn',
                'text'  => 'много соли: натрий '.self::nutrition_number($v['sodium']).' г на '.$per
                    .' — это примерно '.self::nutrition_number(round($v['sodium'] * 2.5, 2)).' г соли',
            ];
        }

        return array_values($out);
    }

    /**
     * Пересчитать таблицу «на порцию» в «на 100 г/мл» — когда порция названа числом.
     *
     * Сравнивать два товара и применять ориентиры маркировки можно только на
     * одной основе: «180 ккал на порцию» и «480 ккал на 100 г» рядом — это
     * ловушка, а не сравнение. Если производитель написал размер порции
     * («30 г», «250 мл»), пересчёт — простая арифметика, и он честнее отказа.
     *
     * Пересчитанное НЕ сохраняется в базу: в карточке остаётся то, что
     * напечатано на упаковке, а пересчёт живёт только на экране и помечен
     * (`recalculated = TRUE`). Иначе через месяц никто не отличит цифру
     * производителя от нашей арифметики.
     *
     * @param  array $nutrition таблица товара
     * @return array та же форма + ['recalculated' => bool, 'from_portion' => '30 г'] или []
     */
    public static function nutrition_per100($nutrition)
    {
        $nutrition = self::nutrition_sanitize($nutrition);

        if ( ! $nutrition)
        {
            return [];
        }

        if ($nutrition['basis'] !== 'portion')
        {
            return $nutrition + ['recalculated' => FALSE, 'from_portion' => ''];
        }

        $portion = (string) $nutrition['portion'];

        if ( ! preg_match('/(\d+(?:[.,]\d+)?)\s*(г|гр|g|мл|ml)\b/iu', $portion, $m))
        {
            return [];     // «1 батончик» в граммы не перевести — честно молчим
        }

        $size = (float) str_replace(',', '.', $m[1]);

        // Слишком мелкая или слишком крупная «порция» — скорее опечатка, чем
        // порция: умножать на 50 или делить на 10 мы не станем.
        if ($size < 5 OR $size > 500)
        {
            return [];
        }

        $factor = 100 / $size;
        $values = [];

        foreach ($nutrition['values'] as $field => $value)
        {
            $values[$field] = round($value * $factor, 2);
        }

        $unit = preg_match('/мл|ml/iu', $m[2]) ? '100ml' : '100g';

        // Проверка на потолки та же, что у любой таблицы: пересчёт мог вывести
        // значение за пределы разумного (значит, порция указана неверно).
        $out = self::nutrition_sanitize(['basis' => $unit, 'portion' => '', 'values' => $values]);

        if ( ! $out)
        {
            return [];
        }

        return $out + ['recalculated' => TRUE, 'from_portion' => $portion];
    }

    /** Число для показа: 7,5 — а не 7.500 (запятая, без хвостовых нулей). */
    public static function nutrition_number($value)
    {
        $value = (float) $value;
        $text  = rtrim(rtrim(number_format($value, 2, ',', ' '), '0'), ',');

        return $text === '' ? '0' : $text;
    }

    /** Первая непустая группа из списка шаблонов (или ''). */
    protected static function _grab($text, array $patterns)
    {
        foreach ($patterns as $re)
        {
            if (preg_match($re, (string) $text, $m))
            {
                // Шаблон с двумя группами (ГОСТ + номер) склеиваем.
                $value = isset($m[2]) ? trim($m[1].' '.$m[2]) : trim((string) $m[1]);

                if ($value !== '')
                {
                    return $value;
                }
            }
        }

        return '';
    }

    /** Число-реквизит с проверкой контрольных цифр (иначе — пусто). */
    protected static function _number($text, $re, $validator)
    {
        if ( ! preg_match($re, (string) $text, $m))
        {
            return '';
        }

        $value = (string) $m[1];

        return self::$validator($value) ? $value : '';
    }

    /** Обрезка и очистка значения: многоточие-хвосты, лишние пробелы. */
    protected static function _clean($value, $max, $keep_case = FALSE)
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));
        $value = preg_replace('/\s*[|;]+\s*$/', '', $value);
        $value = trim($value, " ,;.-—–");

        if (mb_strlen($value) > $max)
        {
            $value = mb_substr($value, 0, $max);
        }

        return $value;
    }

    /** Строки текста без пустых. */
    protected static function _lines($text)
    {
        return array_values(array_filter(array_map('trim', explode("\n", (string) $text)),
            function ($line) { return $line !== ''; }));
    }
}
