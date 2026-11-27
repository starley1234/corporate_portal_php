<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Справочник НСИ prx400: выбор источника (Prx400_Catalog_Provider), поиск
 * для оператора и API, правка локального справочника, память сопоставлений.
 *
 * Источник — config prx400_specflow.catalog.provider (PRX400_CATALOG_PROVIDER):
 * local (по умолчанию) или http. Неизвестное значение — catalog_config, а не
 * тихий откат на local. Внешний источник — только чтение: импорт, «завести
 * позицию» и удаление отвечают catalog_readonly.
 */
final class Service_Prx400_Catalog
{
    private static ?Prx400_Catalog_Provider $provider = NULL;

    public static function provider(): Prx400_Catalog_Provider
    {
        if (self::$provider !== NULL) return self::$provider;
        $cfg = Service_Prx400_Limits::catalog();
        switch ((string) ($cfg['provider'] ?? 'local'))
        {
            case 'local': return self::$provider = new Prx400_Catalog_Local;
            case 'http': return self::$provider = new Prx400_Catalog_Http($cfg);
            default: throw new Prx400_Error('catalog_config', 503);
        }
    }

    /** Сколько ответов источника с прошлого вызова взято из устаревшего кеша (сбой НСИ). */
    public static function take_stale(): int
    {
        return self::$provider instanceof Prx400_Catalog_Http ? self::$provider->take_stale() : 0;
    }

    /** Подмена источника (тесты, CLI-оценка); NULL — снова из конфига. */
    public static function use_provider(?Prx400_Catalog_Provider $provider): void
    {
        self::$provider = $provider;
    }

    /** Поиск позиции для ручного выбора кода (UI и API). */
    public static function search(Prx_Principal $principal, string $query, int $limit = 20): array
    {
        Policy_Prx400_Spec::ownerId($principal);
        $query = mb_substr(trim($query), 0, 200);
        if (mb_strlen($query) < 2) return [];
        $items = self::provider()->search($query, max(1, min(50, $limit)));

        return array_map(static fn (array $i): array => [
            'code' => (string) $i['code'], 'name' => (string) $i['name'], 'unit' => (string) ($i['unit'] ?? ''),
            'okei_code' => (string) ($i['okei_code'] ?? ''),
            'score' => Service_Prx400_Match::score($query, (string) $i['name'].' '.(string) $i['code']),
        ], $items);
    }

    /**
     * Слова запроса оператора с написаниями (до 6): «болт м16х60» находит
     * «Болт M16x60 ГОСТ 7798» — марки бывают и латиницей, и кириллицей.
     *
     * @return array<int, string[]>
     */
    public static function words(string $query): array
    {
        $words = array_slice(preg_split('/\s+/u', trim(mb_substr($query, 0, 200)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);

        return array_map([Service_Prx400_Match::class, 'search_forms'], $words);
    }

    public static function by_code(string $code): ?array
    {
        $code = trim($code);

        return $code === '' ? NULL : self::provider()->by_code($code);
    }

    /** Поля позиции из формы/API: code и name обязательны. */
    public static function fields(array $post, array $fallback = []): array
    {
        $code = mb_substr(trim((string) ($post['code'] ?? '')), 0, 64);
        $name = mb_substr(trim((string) ($post['name'] ?? ($fallback['name'] ?? ''))), 0, 4000);
        if ($code === '' OR $name === '') throw new Prx400_Error('catalog_fields_required', 400);

        return [
            'code' => $code,
            'name' => $name,
            'unit' => mb_substr(trim((string) ($post['unit'] ?? ($fallback['unit'] ?? ''))), 0, 16) ?: 'шт.',
            'okei_code' => mb_substr(trim((string) ($post['okei_code'] ?? '')), 0, 8),
            'keywords' => mb_substr(trim((string) ($post['keywords'] ?? ($fallback['keywords'] ?? ''))), 0, 500),
        ];
    }

    /** Добавить/обновить позицию локального справочника. */
    public static function save(array $fields): array
    {
        self::require_writable();
        $item = Model_Prx400_Catalog::insert($fields);
        if ($item === NULL) throw new RuntimeException('prx400: INSERT catalog без RETURNING');

        return $item;
    }

    public static function delete(int $id): void
    {
        self::require_writable();
        if (Model_Prx400_Catalog::delete($id) < 1) throw new Prx400_Error('not_found', 404);
    }

    public static function require_writable(): void
    {
        if ( ! self::provider()->writable()) throw new Prx400_Error('catalog_readonly', 409);
    }

    /** Запомнить решение «эта строка = этот код» для следующих разборов. */
    public static function remember(array $row, string $code, string $source, int $actor_id): void
    {
        [$name, $code_doc] = Service_Prx400_Match::memory_key((string) $row['raw_name'], (string) $row['code_doc']);
        if ($name === '') return;
        Model_Prx400_Alias::remember($name, $code_doc, $code, $source, $actor_id);
    }

    /** Забыть решение по строке (оператор снял ручной код). */
    public static function forget(array $row): void
    {
        [$name, $code_doc] = Service_Prx400_Match::memory_key((string) $row['raw_name'], (string) $row['code_doc']);
        if ($name !== '') Model_Prx400_Alias::revoke_key($name, $code_doc);
    }

    public static function revoke_alias(Prx_Principal $principal, int $id): void
    {
        Policy_Prx400_Spec::require_review($principal);
        if (Model_Prx400_Alias::revoke($id) < 1) throw new Prx400_Error('not_found', 404);
    }
}
