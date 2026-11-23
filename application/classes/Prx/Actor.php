<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Серверный контекст пользователя.
 *
 * Единственный способ передать «кто действует» в сервис. Создаётся только на
 * сервере из сессии/ACL; значения из запроса в него не попадают. Сервис
 * принимает Prx_Actor, а не массив из контроллера: так нельзя случайно
 * подделать user_id, роли или организацию.
 *
 *   $actor = $this->actor();                 // в контроллере
 *   $actor->can('prx300.view');              // RBAC
 *   $actor->require('prx300.view');          // 403, если нельзя
 *   $actor->organizationId();                // оргконтекст (если есть)
 *
 * Объектные ограничения (чужая запись, организация, чувствительность)
 * проверяет сервис/политика модуля; Prx_Actor их не заменяет. Реализует
 * Prx_Principal, поэтому одна политика работает и для человека, и для
 * машинного ключа prx150.
 */
final class Prx_Actor implements Prx_Principal
{
    private int $user_id;

    /** @var array<int, string> */
    private array $roles;

    private ?int $employee_id;

    private ?int $organization_id;

    private ?int $department_id;

    private ?int $site_id;

    /**
     * @param array $roles роли из Auth
     * @param array $org   orgcontext из Service_Prx300_Directory (необязательно)
     */
    public function __construct(int $user_id, array $roles = [], array $org = [])
    {
        $this->user_id = max(0, $user_id);
        $this->roles = array_values(array_unique(array_filter(
            array_map('strval', $roles),
            static function ($role) { return $role !== ''; }
        )));
        $this->employee_id = self::_id($org['employee_id'] ?? NULL);
        $this->organization_id = self::_id($org['organization_id'] ?? NULL);
        $this->department_id = self::_id($org['department_id'] ?? NULL);
        $this->site_id = self::_id($org['site_id'] ?? NULL);
    }

    /** Гость: user_id = 0, без ролей и оргконтекста. */
    public static function guest(): self
    {
        return new self(0);
    }

    /** Из модели Auth и её ролей. */
    public static function fromUser($user, array $roles = [], array $org = []): self
    {
        return new self($user ? (int) $user->id : 0, $roles, $org);
    }

    public function id(): int
    {
        return $this->user_id;
    }

    /** @return array<int, string> */
    public function roles(): array
    {
        return $this->roles;
    }

    public function isGuest(): bool
    {
        return $this->user_id === 0;
    }

    public function isAdmin(): bool
    {
        return in_array('admin', $this->roles, TRUE);
    }

    /** RBAC через общий ACL. Не заменяет объектную политику сервиса. */
    public function can(string $ability): bool
    {
        if ($this->isGuest()) {
            return FALSE;
        }

        return ACL::check($this->roles, $ability);
    }

    /** Бросает 403, если права нет. */
    public function require(string $ability, string $message = 'Доступ запрещён'): void
    {
        if ( ! $this->can($ability)) {
            throw HTTP_Exception::factory(403, $message);
        }
    }

    public function employeeId(): ?int
    {
        return $this->employee_id;
    }

    public function organizationId(): ?int
    {
        return $this->organization_id;
    }

    public function departmentId(): ?int
    {
        return $this->department_id;
    }

    public function siteId(): ?int
    {
        return $this->site_id;
    }

    /** Новый actor с тем же пользователем, но другим оргконтекстом. */
    public function withOrg(array $org): self
    {
        $clone = clone $this;
        $clone->employee_id = self::_id($org['employee_id'] ?? NULL);
        $clone->organization_id = self::_id($org['organization_id'] ?? NULL);
        $clone->department_id = self::_id($org['department_id'] ?? NULL);
        $clone->site_id = self::_id($org['site_id'] ?? NULL);

        return $clone;
    }

    /** Для логов и legacy-сервисов, которые пока принимают массив. */
    public function toArray(): array
    {
        return [
            'user_id' => $this->user_id,
            'roles' => $this->roles,
            'employee_id' => $this->employee_id,
            'organization_id' => $this->organization_id,
            'department_id' => $this->department_id,
            'site_id' => $this->site_id,
        ];
    }

    private static function _id($value): ?int
    {
        $id = (int) ($value ?? 0);

        return $id > 0 ? $id : NULL;
    }
}
