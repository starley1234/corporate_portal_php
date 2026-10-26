<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-645 Seed — засев «Лаборатории LLM» стартовыми данными из конфига.
 *
 * База накапливает знания от посетителей, но пустая лаборатория бесполезна:
 * n-граммной модели нужен корпус, квизу — термины, библиотеке — промпты.
 * Поэтому при первом открытии страницы модуль проверяет три счётчика и
 * досыпает то, чего нет. Идемпотентно: повторный вызов ничего не делает.
 *
 * Стартовый корпус сразу помечается `approved` (он наш) и попадает в модель
 * через `rebuild_ngrams()`; всё, что приносят посетители, ждёт модерации.
 */
class Service_Nj645_Seed
{
    /** @var bool защита от повторного засева в одном запросе */
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
            Service_Nj645_Store::ensure_tables();
            self::_seed_terms();
            self::_seed_prompts();
            self::_seed_corpus();
        }
        catch (Throwable $e)
        {
            Kohana::$log->add(Log::WARNING, 'nj645: seed: :e', [':e' => $e->getMessage()]);
        }
    }

    protected static function _seed_terms()
    {
        $row  = Service_Nj645_Store::db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_645_terms', FALSE)->current();
        $have = $row ? (int) $row['c'] : 0;

        if ($have > 0)
        {
            return;
        }

        foreach ((array) Service_Nj645_Store::cfg('glossary', []) as $row)
        {
            Service_Nj645_Store::term_upsert(
                (string) $row[0], (string) $row[1], (string) $row[2], (int) $row[3],
                (string) $row[4], (string) ($row[5] ?? ''), (string) ($row[6] ?? ''));
        }
    }

    protected static function _seed_prompts()
    {
        $row  = Service_Nj645_Store::db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_645_prompts', FALSE)->current();
        $have = $row ? (int) $row['c'] : 0;

        if ($have > 0)
        {
            return;
        }

        foreach ((array) Service_Nj645_Store::cfg('prompts', []) as $row)
        {
            Service_Nj645_Store::prompt_upsert(
                (string) $row[0], (string) $row[1], (string) $row[2], (array) $row[3], (string) $row[4], 'seed');
        }
    }

    protected static function _seed_corpus()
    {
        $row  = Service_Nj645_Store::db()->query(Database::SELECT,
            'SELECT COUNT(*) AS c FROM nj_645_corpus WHERE status = \'approved\'', FALSE)->current();
        $have = $row ? (int) $row['c'] : 0;

        if ($have > 0)
        {
            return;
        }

        $db = Service_Nj645_Store::db();

        foreach ((array) Service_Nj645_Store::cfg('corpus_seed', []) as $row)
        {
            $text = Service_Nj645_Lab::normalize_text((string) $row['text']);
            $hash = hash('sha256', $text);

            if ($text === '')
            {
                continue;
            }

            $est = Service_Nj645_Lab::estimate_tokens($text);

            try
            {
                $db->query(Database::INSERT,
                    'INSERT INTO nj_645_corpus
                        (visitor, kind, lang, title, content, content_hash, chars, tokens_est, source, status)
                     VALUES (\'\', '.$db->quote((string) $row['kind']).', '.$db->quote((string) $row['lang']).',
                             '.$db->quote((string) $row['title']).', '.$db->quote($text).', '.$db->quote($hash).',
                             '.(int) mb_strlen($text).', '.(int) $est['total'].', \'seed\', \'approved\')
                     ON CONFLICT ("content_hash") DO NOTHING', FALSE)->execute();
            }
            catch (Throwable $e)
            {
                Kohana::$log->add(Log::WARNING, 'nj645: seed corpus: :e', [':e' => $e->getMessage()]);
            }
        }

        Service_Nj645_Store::rebuild_ngrams();
    }
}
