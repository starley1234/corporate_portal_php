<?php defined('SYSPATH') or die('No direct script access.');

final class Policy_Prx398_Route
{
    public static function ownerId(Prx_Principal $principal): int
    {
        if ($principal instanceof Prx_Actor)
        {
            if ($principal->isGuest()) throw new Prx398_Error('unauthorized', 401);
            if ( ! $principal->can('prx398.ui')) throw new Prx398_Error('forbidden', 403);
            return -$principal->id();
        }
        if ($principal instanceof Prx150_Principal)
        {
            if ($principal->id() <= 0) throw new Prx398_Error('unauthorized', 401);
            if ( ! $principal->hasScope(Service_Prx398_Route::SCOPE))
                throw new Prx398_Error('forbidden', 403);
            return $principal->id();
        }
        throw new Prx398_Error('forbidden', 403);
    }
}
