<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Машинный контекст: ключ prx150, а не пользователь портала.
 *
 * Ролей и can() здесь нет: внешний потребитель действует в рамках scope
 * ключа, а не ACL сотрудника. Объектная политика может сравнить только
 * организацию — и только если ключ выдан на организацию.
 *
 * Создаётся из строки `prx_150_api_clients` фабрикой
 * Service_Prx150_Keys::principal(); из запроса значения не попадают.
 */
final class Prx150_Principal implements Prx_Principal
{
    private int $id;

    private string $name;

    private ?int $organization_id;

    /** @var array<int, string> */
    private array $scopes;

    /** @var array<string, mixed> файловые лимиты ключа (пусто = безлимит) */
    private array $file_limits;

    /** @param array<int, string> $scopes @param array<string, mixed> $file_limits */
    public function __construct(int $id, string $name, ?int $organization_id, array $scopes = [], array $file_limits = [])
    {
        $this->id = $id;
        $this->name = $name;
        $this->organization_id = $organization_id;
        $this->scopes = array_values(array_unique(array_map('strval', $scopes)));
        $this->file_limits = $file_limits;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function organizationId(): ?int
    {
        return $this->organization_id;
    }

    /** @return array<int, string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    /**
     * Файловые лимиты ключа: 'max_file_bytes' / 'max_files_per_request' /
     * 'allowed_formats'. Отсутствующий ключ = безлимит (глобальный лимит
     * модуля остаётся в силе); потребитель обязан проверить перед приёмом.
     */
    public function fileLimits(): array
    {
        return $this->file_limits;
    }

    /** Scope API, не пользовательский ACL. Пустое требование закрыто даже для `*`. */
    public function hasScope(string $scope): bool
    {
        return $this->id > 0 && $scope !== ''
            && (in_array($scope, $this->scopes, TRUE) || in_array('*', $this->scopes, TRUE));
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'organization_id' => $this->organization_id,
            'scopes'            => $this->scopes,
            'file_limits'       => $this->file_limits,
        ];
    }
}
