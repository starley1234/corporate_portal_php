<?php defined('SYSPATH') or die('No direct script access.');

/** Доступ к PDF: UI-пользователь и API-ключ — разные пространства владельцев. */
final class Policy_Prx395_Extraction
{
    /**
     * Внутренний ключ хранения: API > 0, UI < 0. Контроллеры его не создают.
     * Организация не даёт доступа к чужому заданию, даже администратору.
     */
    public static function ownerId(Prx_Principal $principal): int
    {
        if ($principal instanceof Prx_Actor)
        {
            if ($principal->isGuest()) throw new Prx395_Error('unauthorized', 401);
            if ( ! $principal->can('prx395.ui')) throw new Prx395_Error('forbidden', 403);
            return -$principal->id();
        }
        if ($principal instanceof Prx150_Principal)
        {
            if ($principal->id() <= 0) throw new Prx395_Error('unauthorized', 401);
            if ( ! $principal->hasScope(Service_Prx395_Extraction::SCOPE))
                throw new Prx395_Error('forbidden', 403);
            return $principal->id();
        }
        throw new Prx395_Error('forbidden', 403);
    }
}
