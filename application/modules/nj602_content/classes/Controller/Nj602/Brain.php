<?php defined('SYSPATH') or die('No direct script access.');

/**
 * nj602_content: «Рост знаний» — витрина обучения платформы.
 *
 * Показывает, насколько платформа стала умнее: база знаний (nj560),
 * семантический индекс (nj115), опубликованные статьи и источники (nj602).
 * Данные только агрегатами — содержимое заметок здесь не раскрываем.
 */
class Controller_Nj602_Brain extends Controller_Erp100_Base {

    public function before()
    {
        parent::before();

        $this->set_title('Рост знаний платформы');
        $this->set_description('Как N2J становится полезнее: база знаний, семантический индекс, статьи и источники — живая статистика работы сервисов.');
    }

    public function action_index()
    {
        $k = class_exists('Service_Nj560_Knowledge') ? Service_Nj560_Knowledge::stats() : [];
        $s = class_exists('Service_Nj115_Semantic') ? Service_Nj115_Semantic::stats() : [];

        $this->template->content = View::factory('602/brain', [
            'k'       => $k,
            's'       => $s,
            'months'  => $this->_growth(),
            'a'       => $this->_articles(),
        ]);
    }

    /** Рост по месяцам: заметки базы знаний + статьи журнала. */
    protected function _growth()
    {
        $db = Database::instance();

        try
        {
            return $db->query(Database::SELECT,
                "SELECT to_char(d.month, 'MM.YYYY') AS label,
                        COALESCE(k.cnt, 0) AS docs,
                        COALESCE(a.cnt, 0) AS articles
                 FROM generate_series(date_trunc('month', now()) - interval '5 months',
                                      date_trunc('month', now()), interval '1 month') AS d(month)
                 LEFT JOIN (SELECT date_trunc('month', created_at) AS m, COUNT(*) AS cnt
                            FROM nj_560_docs GROUP BY 1) k ON k.m = d.month
                 LEFT JOIN (SELECT date_trunc('month', created_at) AS m, COUNT(*) AS cnt
                            FROM nj_602_articles GROUP BY 1) a ON a.m = d.month
                 ORDER BY d.month", FALSE)->as_array();
        }
        catch (Throwable $e)
        {
            return [];
        }
    }

    /** Статьи журнала: статусы и суммарные источники. */
    protected function _articles()
    {
        $db = Database::instance();

        try
        {
            $row = $db->query(Database::SELECT,
                "SELECT COUNT(*) FILTER (WHERE status = 'published') AS published,
                        COUNT(*) FILTER (WHERE status = 'review') AS review,
                        COUNT(*) FILTER (WHERE status = 'collecting') AS collecting,
                        COUNT(*) AS total,
                        COALESCE(SUM(jsonb_array_length(sources)), 0) AS sources
                 FROM nj_602_articles", FALSE)->current();

            return $row ? (array) $row : [];
        }
        catch (Throwable $e)
        {
            return [];
        }
    }
}
