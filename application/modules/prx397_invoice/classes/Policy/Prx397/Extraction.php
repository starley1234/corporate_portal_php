<?php defined('SYSPATH') or die('No direct script access.');

final class Policy_Prx397_Extraction
{
    public static function ownerId(Prx_Principal $principal): int
    {
        if ($principal instanceof Prx_Actor)
        {
            if ($principal->isGuest()) throw new Prx397_Error('unauthorized', 401);
            if ( ! $principal->can('prx397.ui')) throw new Prx397_Error('forbidden', 403);
            return -$principal->id();
        }
        if ($principal instanceof Prx150_Principal)
        {
            if ($principal->id() <= 0) throw new Prx397_Error('unauthorized', 401);
            if ( ! $principal->hasScope(Service_Prx397_Extraction::SCOPE))
                throw new Prx397_Error('forbidden', 403);
            return $principal->id();
        }
        throw new Prx397_Error('forbidden', 403);
    }
}
