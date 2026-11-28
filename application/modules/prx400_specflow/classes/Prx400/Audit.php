<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Журнал действий prx400 (/prx100/audit, категория prx400): удаления и
 * изменения справочника/шаблонов — то, что меняет результат чужих разборов.
 * Пишется в сервисах, поэтому одинаково покрывает UI и API. Ядро пишет
 * «кто» по сессии; для API-ключа это «system» — поэтому ключ кладём в data.
 */
final class Prx400_Audit
{
    public static function log(Prx_Principal $principal, string $action, $object_id = NULL, array $data = []): void
    {
        if ($principal instanceof Prx150_Principal)
        {
            $data['api_key'] = $principal->id().' '.$principal->name();
        }

        Audit::log('prx400', $action, $object_id, $data);
    }
}
