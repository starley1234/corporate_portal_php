<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Источник справочника НСИ для prx400 (матчинг, ручной выбор кода, экспорт).
 *
 * Позиция — массив {id: int (0 у внешнего), code, name, unit, okei_code,
 * keywords}; code — идентичность позиции в любом источнике. Память
 * сопоставлений (prx_400_aliases) и пороги доверия от источника не зависят.
 * Реализации: Prx400_Catalog_Local (prx_400_catalog), Prx400_Catalog_Http
 * (внешний справочник, только чтение). Выбор — Service_Prx400_Catalog::provider().
 */
interface Prx400_Catalog_Provider
{
    /** Машинное имя источника: local|http. */
    public function name(): string;

    /** Можно ли заводить/править позиции из prx400 (импорт, «завести из строки»). */
    public function writable(): bool;

    /** Позиций в справочнике; NULL — источник не сообщает. */
    public function count(): ?int;

    /**
     * Источник кандидатов для партии строк (один разбор): callable(query) → позиции.
     * Окончательный скор считает Service_Prx400_Match.
     */
    public function batch(): callable;

    /** Поиск для оператора/API: подстрока или похожесть, не больше $limit позиций. */
    public function search(string $query, int $limit): array;

    public function by_code(string $code): ?array;
}
