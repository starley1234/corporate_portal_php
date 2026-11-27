<?php defined('SYSPATH') or die('No direct script access.');

/**
 * portal:maintain — обслуживание портала по таймеру раз в минуту.
 *
 * С 27.11.2026 — тот же проход, что `schedule:run`: исполняются расписания
 * манифестов модулей (`schedule` в config/prx_module.php), а не зашитый здесь
 * список модулей. Обслуживание очереди prx120 и доставка уведомлений prx597
 * остались раз в минуту (их манифесты), к ним добавились уборки prx395–400,
 * эскалации prx393 и автоматизация prx391. Старые строки cron с
 * `portal:maintain` продолжают работать без правки.
 *
 *     * * * * *  cd /srv/portal && bin/console portal:maintain
 *
 * Опции и состояние — bin/console schedule:run --only=… / schedule:list.
 */
class Task_Portal_Maintain extends Task_Schedule_Run
{
}
