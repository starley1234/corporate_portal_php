<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Ошибка идемпотентности: состояние `conflict` или `expired`.
 *
 * Сообщение — стабильный код состояния (`idempotency_conflict` и т.п.),
 * поэтому машинный контроллер может вернуть его потребителю как есть.
 */
class Prx_Idempotency_Exception extends RuntimeException
{
    private string $state;

    public function __construct(string $state)
    {
        parent::__construct($state);
        $this->state = $state;
    }

    public function state(): string
    {
        return $this->state;
    }
}
