<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Seed — засев базы веществ из конфига при первом открытии сервиса.
 *
 * Идемпотентно: конфиг — только стартовый набор (source = seed), дальше база
 * живёт сама — правки посетителей (source = user) и ИИ-изучение (source = llm)
 * конфигом не перетираются.
 */
class Service_Nj646_Seed
{
    /** @var bool засев уже проверяли в этом запросе */
    protected static $_checked = FALSE;

    public static function ensure()
    {
        if (self::$_checked)
        {
            return;
        }

        self::$_checked = TRUE;

        try
        {
            self::_seed_ingredients();
        }
        catch (Throwable $e)
        {
            // Сервис не должен падать из-за посева: показываем то, что есть.
            Kohana::$log->add(Log::WARNING, 'nj646: посев не удался: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _seed_ingredients()
    {
        Service_Nj646_Store::ensure_tables();

        $rows = (array) Service_Nj646_Store::cfg('ingredients', []);
        $n    = 0;

        foreach ($rows as $slug => $row)
        {
            if ( ! is_array($row) OR ! isset($row['ru']))
            {
                continue;
            }

            $row['slug'] = (string) $slug;
            Service_Nj646_Store::seed_ingredient($row);
            $n++;

            // Длинный конфиг сеем порциями, чтобы не держать один огромный запрос.
            if ($n % 40 === 0)
            {
                usleep(10000);
            }
        }
    }
}
