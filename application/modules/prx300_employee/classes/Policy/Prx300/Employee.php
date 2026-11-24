<?php defined('SYSPATH') or die('No direct script access.');

/** HR-доступ: ACL из Prx_Actor, организация — из актуальной связи с сотрудником. */
final class Policy_Prx300_Employee
{
    public static function requireAbility(Prx_Actor $actor, string $ability, string $message = 'Недостаточно прав для этой операции'): void
    {
        if ( ! $actor->can($ability)) throw new DomainException($message);
    }

    public static function canReadAdministration(Prx_Actor $actor): bool
    {
        return $actor->can('prx300.admin.view') || $actor->can('prx300.manage')
            || $actor->can('prx300.import') || $actor->can('prx300.identity');
    }

    public static function requireImportRead(Prx_Actor $actor): void
    {
        if ( ! $actor->can('prx300.import') && ! $actor->can('prx300.admin.view') && ! $actor->can('prx300.manage'))
            throw new DomainException('Нужен HR-доступ к импорту');
    }

    public static function requireAdministration(Prx_Actor $actor): void
    {
        if ( ! self::canReadAdministration($actor)) throw new DomainException('Нужен HR-доступ к кадровым данным');
    }

    /** Не доверяем organizationId из сохранённого actor: связь могла быть отозвана. */
    public static function organizationScope(Prx_Actor $actor): array
    {
        if ( ! $actor->isGuest() && $actor->isAdmin())
            return ['global' => TRUE, 'organization_id' => NULL, 'organization_code' => ''];

        $own = $actor->isGuest() ? NULL : Service_Prx300_Directory::profile_for_user($actor->id());
        return [
            'global' => FALSE,
            'organization_id' => (int) ($own['organization_id'] ?? 0),
            'organization_code' => (string) ($own['organization_code'] ?? ''),
        ];
    }

    public static function assertOrganization(Prx_Actor $actor, ?int $organization_id = NULL): array
    {
        $scope = self::organizationScope($actor);
        if ( ! $scope['global'] && $scope['organization_id'] <= 0)
            throw new DomainException('Пользователь не сопоставлен с сотрудником, поэтому организационный доступ не определён');
        if ($organization_id !== NULL && ! $scope['global'] && $organization_id !== $scope['organization_id'])
            throw new DomainException('Запись относится к другой организации');
        return $scope;
    }

    public static function directoryStatuses(): array
    {
        $statuses = Kohana::$config->load('prx300_employee')->get('directory.statuses');
        return array_values(array_intersect((array) ($statuses ?? ['active', 'on_leave']), ['active', 'on_leave', 'suspended']));
    }

    /** NULL означает все организации — только для portal-admin. */
    public static function directoryOrganization(Prx_Actor $actor, array $filters = []): ?int
    {
        if (self::canReadAdministration($actor))
        {
            $scope = self::assertOrganization($actor);
            if ( ! $scope['global']) return $scope['organization_id'];
            $requested = (int) ($filters['organization_id'] ?? 0);
            return $requested > 0 ? $requested : NULL;
        }

        self::requireAbility($actor, 'prx300.directory', 'Справочник сотрудников недоступен');
        $own = Service_Prx300_Directory::profile_for_user($actor->id());
        if ($own === NULL)
            throw new DomainException('Справочник доступен после сопоставления аккаунта с сотрудником');
        if ( ! in_array($own['employment_status'], self::directoryStatuses(), TRUE))
            throw new DomainException('Справочник недоступен при текущем кадровом статусе');
        return (int) $own['organization_id'];
    }

    public static function batchInScope(Prx_Actor $actor, array $batch): bool
    {
        $scope = self::organizationScope($actor);
        if ($scope['global']) return TRUE;
        $codes = json_decode((string) ($batch['organization_codes'] ?? '[]'), TRUE);
        return $scope['organization_code'] !== '' && $codes === [$scope['organization_code']];
    }

    public static function assertBatch(Prx_Actor $actor, array $batch): void
    {
        if ( ! self::batchInScope($actor, $batch)) throw new DomainException('Пакет относится к другой организации');
    }
}
