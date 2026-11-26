<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Точки расширения ядра без знания о бизнес-модулях.
 *
 * База страницы (Controller_Prx102_Base) не должна вызывать конкретные модули
 * (prx110, prx300, prx599): иначе правка любого из них задевает всю платформу.
 * Вместо этого модуль в своём `init.php` регистрирует обработчик именованной
 * точки, а ядро её вызывает. Модуль не подключён — обработчика нет, поведение
 * деградирует мягко (нет избранного, пустой оргконтекст, нет проверки сессии).
 *
 *   // init.php модуля-владельца
 *   Prx_Hooks::on('actor.org', fn($user_id) => Service_Prx300_Directory::context_for_user($user_id));
 *
 *   // ядро
 *   $org = Prx_Hooks::call('actor.org', $user_id);
 *
 * call() возвращает первый не-NULL результат, all() — все (для нескольких
 * «сторожей», например проверки сессии).
 */
final class Prx_Hooks
{
    /** @var array<string, list<callable>> */
    private static array $listeners = [];

    public static function on(string $name, callable $callback): void
    {
        self::$listeners[$name][] = $callback;
    }

    /** Первый не-NULL результат обработчиков точки (или NULL). */
    public static function call(string $name, ...$args)
    {
        foreach (self::$listeners[$name] ?? [] as $callback)
        {
            $result = $callback(...$args);

            if ($result !== NULL) return $result;
        }

        return NULL;
    }

    /** @return array<int, mixed> все результаты обработчиков точки */
    public static function all(string $name, ...$args): array
    {
        $results = [];

        foreach (self::$listeners[$name] ?? [] as $callback)
        {
            $results[] = $callback(...$args);
        }

        return $results;
    }

    /** Сбросить обработчики (тесты). */
    public static function reset(): void
    {
        self::$listeners = [];
    }
}
