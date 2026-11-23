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

    /** @param array<int, string> $scopes */
    public function __construct(int $id, string $name, ?int $organization_id, array $scopes = [])
    {
        $this->id = $id;
        $this->name = $name;
        $this->organization_id = $organization_id;
        $this->scopes = array_values(array_unique(array_map('strval', $scopes)));
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

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'organization_id' => $this->organization_id,
            'scopes'          => $this->scopes,
        ];
    }
}
