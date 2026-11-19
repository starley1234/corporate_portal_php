<?php defined('SYSPATH') or die('No direct script access.');

class Service_Prx120_Probe
{
    public static function submit(): array
    {
        $nonce = bin2hex(random_bytes(8));
        $id = Service_Prx120_JobQueue::push('echo', ['title' => 'Проверка воркера', 'probe' => $nonce], [
            'module' => 'prx120', 'priority' => 1, 'max_attempts' => 1, 'timeout_sec' => 30,
        ]);
        if ($id < 1) throw new RuntimeException('Не удалось поставить проверочную задачу');
        return [$id, $nonce];
    }
}
