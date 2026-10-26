<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Клейм перевода принадлежит задаче очереди.
 *
 * Зачем. 24.10.2026 каталог завис на 25 карточках: задача взяла их в работу
 * (`ru_claimed_at`), перевела 8 и закончилась по своему бюджету времени.
 * Клейм живёт по часам, а не по задаче, поэтому сайт ещё 60 минут считал
 * «25 в работе», свободных карточек не было, кнопки были неактивны — а модель
 * в это время не делала ничего. Владелец видел «застряло», хотя работы просто
 * не было: клейм держал карточки за умершей задачей.
 *
 * Решение. Вместе с меткой времени храним номер задачи nj120, которая карточку
 * взяла (`ru_claimed_job`). Сайт знает, какие задачи канала живы, и снимает
 * клейм у карточек, чья задача уже закончилась — независимо от того, сколько
 * прошло времени. Старые метки без номера задачи отпускаются по времени.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS / CREATE INDEX IF NOT EXISTS.
 */
class pgMigration_Nj_676_015_McpHub_Translate_Owner extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS ru_claimed_job BIGINT');

        // «Чьи это клеймы» — маленький частичный индекс рядом с индексом 013.
        $this->run_query("CREATE INDEX IF NOT EXISTS nj_676_servers_ru_claim_job_idx
            ON nj_676_servers (ru_claimed_job)
            WHERE ru_claimed_at IS NOT NULL");
    }

    public function down()
    {
        // Номер задачи — служебная метка: при откате индекс уходит, колонка
        // остаётся безвредной (её читают только вместе с ru_claimed_at).
        $this->run_query('DROP INDEX IF EXISTS nj_676_servers_ru_claim_job_idx');
    }
}
