<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Публичная ошибка машинного API: стабильный код + HTTP-статус.
 *
 * В коде и details нет внутренних путей, токенов и чужих данных. Ответ
 * всегда одинаковый: {"error":{"code":"…","details":{…}}}.
 */
class Prx150_Error extends RuntimeException
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
