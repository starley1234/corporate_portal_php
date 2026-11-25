<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Доменная ошибка модуля: стабильный код + HTTP-статус + детали.
 *
 * Один класс на оба канала. Сервис бросает доменную ошибку, не зная, откуда его
 * позвали; точка входа сама переводит её в свой формат:
 *   * страница/UI — HTTP-статус и текст (контроллер ловит Prx_DomainError);
 *   * внешний машинный API — JSON {"error":{"code","details"}} (Controller_Prx150_Machine).
 *
 * Поэтому машинный `Prx150_Error` — частный случай (наследник), а не отдельная
 * иерархия: модуль-сервис не обязан зависеть от prx150 ради класса ошибки.
 * В коде и details нет внутренних путей, токенов и чужих данных.
 */
class Prx_DomainError extends RuntimeException
{
    private array $details;

    public function __construct(string $code, int $status, array $details = [])
    {
        parent::__construct($code);
        $this->code = $status;
        $this->details = $details;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function payload(): array
    {
        $error = ['code' => $this->getMessage()];

        if ($this->details !== [])
        {
            $error['details'] = $this->details;
        }

        return ['error' => $error];
    }
}
