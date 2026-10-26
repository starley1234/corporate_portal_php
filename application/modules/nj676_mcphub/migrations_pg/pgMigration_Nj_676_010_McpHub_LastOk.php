<?php defined('SYSPATH') or die('No direct script access.');

/**
 * «Сервер отвечал по делу» — отметка последнего успешного вызова.
 *
 * Зачем. Реальный тест (24.10.2026) поймал неприятную картину: агент сделал
 * два вызова с неверными аргументами, удалённый сервер вернул ошибки, а
 * health-проба в этот же момент не смогла прочитать `tools/list` — и живой
 * open-meteo стал `degraded`, выпал из `only_ready` и с сайта выглядел
 * «Сбоит», хотя вызовы к нему проходили в ту же минуту.
 *
 * `health_checked_at` отвечает на вопрос «когда его последний раз проверяли»,
 * а `last_ok_at` — «когда он последний раз реально сработал». Это разные
 * сигналы, и `readiness()` склеивал их в один. Теперь карточка со свежим
 * успешным вызовом не объявляется сломанной из-за одной неудачной пробы.
 *
 * Миграция идемпотентна: ADD COLUMN IF NOT EXISTS.
 */
class pgMigration_Nj_676_010_McpHub_LastOk extends pgMigration
{
    public function up()
    {
        $this->run_query('ALTER TABLE nj_676_servers ADD COLUMN IF NOT EXISTS last_ok_at TIMESTAMPTZ');

        // Готовность смотрит на last_ok_at у опубликованных карточек — индекс
        // частичный, чтобы не тянуть всю таблицу при раздаче «что готово».
        $this->run_query('CREATE INDEX IF NOT EXISTS nj_676_servers_last_ok_idx
            ON nj_676_servers (health_status, last_ok_at DESC) WHERE is_published = TRUE;');
    }

    public function down()
    {
        // Сигнал живучести нужен постоянно; откат обнулил бы историю.
    }
}
