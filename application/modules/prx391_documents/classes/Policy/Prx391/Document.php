<?php defined('SYSPATH') or die('No direct script access.');

/** Единые правила грифа для карточки, списков, истории и соседних модулей. */
final class Policy_Prx391_Document extends Prx_Policy
{
    protected const VIEW_ACL = 'prx391.view';
    protected const EDIT_ACL = 'prx391.edit';

    public static function canViewAll(?Prx_Actor $actor): bool
    {
        return $actor !== NULL && $actor->can('prx391.view')
            && (bool) array_intersect($actor->roles(), ['docflow_lawyer', 'docflow_manager', 'admin']);
    }

    public static function canEdit(?Prx_Actor $actor): bool
    {
        return $actor !== NULL && $actor->can('prx391.edit');
    }

    public static function canAdmin(?Prx_Actor $actor): bool
    {
        return $actor !== NULL && $actor->can('prx391.admin');
    }

    protected function scope(Prx_Principal $actor, array $row): bool
    {
        return $actor instanceof Prx_Actor && ! $actor->isGuest()
            && (in_array($row['sensitivity'] ?? 'internal', ['public', 'internal'], TRUE)
                || self::canViewAll($actor));
    }
}
