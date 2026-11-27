<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Доступ к спецификациям prx400. Владелец записи — подписанный ID
 * (docs/FOUNDATION.md): API-ключ prx150 > 0, сотрудник UI < 0.
 *
 *  - UI (Prx_Actor): рабочее место с правом prx400.view видит ВСЕ
 *    спецификации — включая пришедшие по API: их проверяет оператор (HITL);
 *    изменения — с prx400.run.
 *  - API (Prx150_Principal): ключ со scope prx400.extract видит только свои
 *    записи; чужая — 404, как несуществующая.
 *
 * Контроллеры владельца не вычисляют: только ownerId()/require_*().
 */
final class Policy_Prx400_Spec
{
    // Ключи application/config/acl.php. В проверках ниже — литералами: так их
    // видит acl-lint (make acl-lint); константы — для контроллеров и видов.
    public const VIEW_ACL = 'prx400.view';
    public const EDIT_ACL = 'prx400.run';

    public static function ownerId(Prx_Principal $principal): int
    {
        if ($principal instanceof Prx_Actor)
        {
            if ($principal->isGuest()) throw new Prx400_Error('unauthorized', 401);
            if ( ! $principal->can('prx400.view')) throw new Prx400_Error('forbidden', 403);
            return -$principal->id();
        }
        if ($principal instanceof Prx150_Principal)
        {
            if ($principal->id() <= 0) throw new Prx400_Error('unauthorized', 401);
            if ( ! $principal->hasScope(Service_Prx400_Specs::SCOPE)) throw new Prx400_Error('forbidden', 403);
            return $principal->id();
        }
        throw new Prx400_Error('forbidden', 403);
    }

    /** Право на запуск разбора и правки (UI — prx400.run, API-ключ — scope). */
    public static function require_edit(Prx_Principal $principal): int
    {
        $owner = self::ownerId($principal);
        if ($principal instanceof Prx_Actor AND ! $principal->can('prx400.run'))
        {
            throw new Prx400_Error('forbidden', 403);
        }

        return $owner;
    }

    /**
     * Работа с позициями (HITL): правка, подтверждение, код НСИ, завершение,
     * перезапуск. UI — prx400.run; API-ключ — scope prx400.review поверх
     * prx400.extract и только по своим записям (require_view).
     *
     * @return int подписанный ID действующего лица (UI < 0, ключ > 0) — для
     *             журнала правок и verified_by, как owner_id
     */
    public static function require_review(Prx_Principal $principal): int
    {
        $owner = self::require_edit($principal);
        if ($principal instanceof Prx150_Principal AND ! $principal->hasScope('prx400.review'))
        {
            throw new Prx400_Error('forbidden', 403);
        }

        return $owner;
    }

    /** Видимость конкретной записи; чужая для API-ключа — not_found. */
    public static function require_view(Prx_Principal $principal, ?array $spec): array
    {
        self::ownerId($principal);
        if ($spec === NULL OR ! self::visible($principal, $spec))
        {
            throw new Prx400_Error('not_found', 404);
        }

        return $spec;
    }

    public static function visible(Prx_Principal $principal, array $spec): bool
    {
        if ($principal instanceof Prx_Actor)
        {
            return $principal->can('prx400.view');
        }

        return (int) $spec['owner_id'] === self::ownerId($principal);
    }
}
