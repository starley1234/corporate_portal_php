<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Сопоставление партии строк с НСИ: память решений → fuzzy по источнику.
 *
 *  1. Память (prx_400_aliases): та же пара «наименование + обозначение» уже
 *     получала код от человека — код применяется сразу (via: memory), если
 *     позиция ещё есть в справочнике.
 *  2. Иначе — Service_Prx400_Match::apply по кандидатам подключённого
 *     источника (Prx400_Catalog_Provider::batch).
 *  3. Флаги строки пересчитываются с учётом привязки (unit_mismatch), статус —
 *     единым правилом Match::row_status.
 */
final class Service_Prx400_Matcher
{
    private Prx400_Catalog_Provider $provider;
    /** @var callable|null */
    private $source = NULL;
    /** @var array<string, string> ключ памяти → код */
    private array $memory = [];
    /** @var array<string, array|null> */
    private array $items = [];
    /** @var array<string, array> сработавшие ключи памяти */
    private array $used = [];

    public function __construct(?Prx400_Catalog_Provider $provider = NULL)
    {
        $this->provider = $provider ?? Service_Prx400_Catalog::provider();
    }

    /** Прочитать память для всех строк партии одним запросом. */
    public function prime(array $rows): void
    {
        $keys = [];
        foreach ($rows as $row)
        {
            $key = Service_Prx400_Match::memory_key((string) ($row['raw_name'] ?? ''), (string) ($row['code_doc'] ?? ''));
            if ($key[0] !== '') $keys[implode('|', $key)] = $key;
        }
        $this->memory += Model_Prx400_Alias::lookup(array_values($keys));
    }

    /** @return array{match: array, flags: array, status: string} */
    public function assess(array $row): array
    {
        $name = (string) ($row['raw_name'] ?? '');
        $code_doc = (string) ($row['code_doc'] ?? '');
        $key = Service_Prx400_Match::memory_key($name, $code_doc);
        $remembered = $this->memory[implode('|', $key)] ?? NULL;
        $item = $remembered !== NULL ? $this->item($remembered) : NULL;

        if ($item !== NULL)
        {
            $match = Service_Prx400_Match::from_item($item, 'memory', FALSE);
            $this->used[implode('|', $key)] = $key;
        }
        else
        {
            $this->source ??= $this->provider->batch();
            $match = Service_Prx400_Match::apply($name, $code_doc, ($this->source)(Service_Prx400_Match::query($name, $code_doc)))['match'];
        }
        $flags = Service_Prx400_Checks::refresh($row, $match);

        return ['match' => $match, 'flags' => $flags, 'status' => Service_Prx400_Match::row_status($flags, $match)];
    }

    /** Отметить применения памяти (после успешной записи разбора). */
    public function record_hits(): void
    {
        if ($this->used !== []) Model_Prx400_Alias::hit(array_values($this->used));
    }

    public function memory_hits(): int
    {
        return count($this->used);
    }

    private function item(string $code): ?array
    {
        if ( ! array_key_exists($code, $this->items)) $this->items[$code] = $this->provider->by_code($code);

        return $this->items[$code];
    }
}
