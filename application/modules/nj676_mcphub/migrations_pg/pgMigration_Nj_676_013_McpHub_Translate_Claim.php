<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Клейм карточки на время перевода.
 *
 * Зачем. «Перевести всё» ставит цепочку задач (40 карточек на задачу), и они
 * стартуют почти одновременно. `candidates()` сортирует «без перевода» по
 * полезности — значит все задачи получали ОДИН и тот же список и переводили
 * один и тот же каталог по кругу. Токены модели тратились N раз, а счётчик
 * «переведено» врал в N раз (23.10.2026: 5 из 1037, потом застой).
 *
 * Решение. Взятую карточку помечаем `ru_claimed_at = now()`. Другая задача её
 * не видит, пока клейм жив: `apply()` снимает метку сразу после сохранения
 * перевода, а упавшая задача отпускает карточки по сроку (60 минут).
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS / CREATE INDEX IF NOT EXISTS.
 */
class pgMigration_Nj_676_013_McpHub_Translate_Claim extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS ru_claimed_at TIMESTAMPTZ');

        // Частичный индекс: «кто сейчас в работе» — редкий и маленький запрос.
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_ru_claim_idx
            ON nj_676_servers (ru_claimed_at)
            WHERE ru_claimed_at IS NOT NULL");
    }

    public function down()
    {
        // Клейм — временная метка: при откате индекс уходит, колонка остаётся
        // безвредной (её никто не читает без индекса).
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_ru_claim_idx');
    }
}
