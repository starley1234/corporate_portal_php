<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Тестовые данные (модуль NJ-627) — ФИО, телефоны и главное: реквизиты
 * организаций с корректными контрольными цифрами (ИНН 10/12, КПП, ОГРН,
 * ОГРНИП, СНИЛС). Используется для QA: данные валидны по алгоритму, но
 * не принадлежат реальным людям и компаниям.
 */
class Service_Nj627_Testdata {

    protected static $last_names  = ['Иванов', 'Петров', 'Сидоров', 'Кузнецова', 'Смирнов', 'Волков', 'Соколова', 'Козлов', 'Новикова', 'Фёдоров'];
    protected static $first_names = ['Иван', 'Пётр', 'Сергей', 'Анна', 'Мария', 'Ольга', 'Дмитрий', 'Елена', 'Николай', 'Татьяна'];
    protected static $patronyms  = ['Иванович', 'Петровна', 'Сергеевич', 'Андреевна', 'Николаевич', 'Павловна', 'Михайлович', 'Алексеевна'];

    /** Пачка сущностей по типу. */
    public static function generate(string $type, int $count = 5): array
    {
        $count = max(1, min(100, $count));
        $out   = [];

        for ($i = 0; $i < $count; $i++)
        {
            $out[] = self::one($type);
        }

        return $out;
    }

    /** Одна сущность. */
    public static function one(string $type): ?array
    {
        switch ($type)
        {
            case 'fio':
                $g = random_int(0, 1) === 0 ? 'f' : 'm';
                return ['fio' => sprintf('%s %s %s', self::$last_names[array_rand(self::$last_names)], self::$first_names[array_rand(self::$first_names)], self::$patronyms[array_rand(self::$patronyms)]), 'gender' => $g];

            case 'phone':
                return ['phone' => sprintf('+7 %03d %03d-%02d-%02d', random_int(900, 999), random_int(0, 999), random_int(0, 99), random_int(0, 99))];

            case 'company':
                return self::company();

            case 'email':
                return ['email' => self::email()];

            default:
                return NULL;
        }
    }

    /** Компания: название + реквизиты с корректными контрольными цифрами. */
    public static function company(): array
    {
        $inn  = self::inn10();
        $ogrn = self::ogrn();

        return [
            'name'  => self::company_name(),
            'inn'   => $inn,
            'kpp'   => self::kpp(),
            'ogrn'  => $ogrn,
            'snils' => self::snils(),
        ];
    }

    /** ИНН юрлица (10 цифр, контрольная по n1—n3…n10). */
    public static function inn10(): string
    {
        $base = '7700'.sprintf('%05d', random_int(0, 99999)); // регион 77 + 5 цифр
        $w1   = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $sum  = 0;

        for ($i = 0; $i < 9; $i++)
        {
            $sum += (int) $base[$i] * $w1[$i];
        }

        return $base.($sum % 11 % 10);
    }

    /** ИНН ИП/физлица (12 цифр). */
    public static function inn12(): string
    {
        $base = '7700'.sprintf('%05d', random_int(0, 99999)).'0'; // 10 цифр + место
        $w1   = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $w2   = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
        $base[10] = (string) self::_checksum($base, $w1);
        $base[11] = (string) self::_checksum($base, $w2);

        return $base;
    }

    protected static function _checksum(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $i => $w)
        {
            $sum += (int) $digits[$i] * $w;
        }

        return $sum % 11 % 10;
    }

    /** КПП: 4 знака кода налоговой + 2 (причина) + 3 (порядковый) = 9 цифр. */
    public static function kpp(): string
    {
        return '7700'.'01'.sprintf('%03d', random_int(1, 999));
    }

    /** ОГРН юрлица (13 цифр, контрольная = остаток от деления на 11). */
    public static function ogrn(): string
    {
        $base = '1'.sprintf('%02d', random_int(2, 20)).'77'.sprintf('%07d', random_int(0, 9999999)).'0';
        $n    = (int) substr($base, 0, 12);
        $cd   = $n % 11 % 10;
        $base[12] = (string) $cd;

        return $base;
    }

    /** ОГРНИП (15 цифр, контрольная = остаток от деления на 13). */
    public static function ogrnip(): string
    {
        $base = '3'.sprintf('%02d', random_int(2, 20)).'77'.sprintf('%09d', random_int(0, 999999999)).'0';
        $n    = (int) substr($base, 0, 14);
        $cd   = $n % 13 % 10;
        $base[14] = (string) $cd;

        return $base;
    }

    /** СНИЛС: XXX-XXX-XXX YY (контрольная сумма по 9 цифрам). */
    public static function snils(): string
    {
        $d = sprintf('%09d', random_int(1, 999999999));
        $sum = 0;

        for ($i = 0; $i < 9; $i++)
        {
            $sum += (int) $d[$i] * (9 - $i);
        }

        $cd = $sum < 100 ? $sum : ($sum === 100 ? 0 : $sum % 101);

        return substr($d, 0, 3).'-'.substr($d, 3, 3).'-'.substr($d, 6, 3).' '.sprintf('%02d', $cd);
    }

    /** Проверка контрольной цифры ИНН (10 или 12). */
    public static function validate_inn(string $inn): bool
    {
        $inn = preg_replace('/\D/', '', $inn);

        if (strlen($inn) === 10)
        {
            $w = [2, 4, 10, 3, 5, 9, 4, 6, 8];

            return self::_checksum(substr($inn, 0, 9), $w) === (int) $inn[9];
        }

        if (strlen($inn) === 12)
        {
            $w1 = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];
            $w2 = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

            return self::_checksum(substr($inn, 0, 10), $w1) === (int) $inn[10]
                && self::_checksum(substr($inn, 0, 11), $w2) === (int) $inn[11];
        }

        return false;
    }

    /** Проверка контрольной цифры ОГРН (13) или ОГРНИП (15). */
    public static function validate_ogrn(string $ogrn): bool
    {
        $ogrn = preg_replace('/\D/', '', $ogrn);

        if (strlen($ogrn) === 13)
        {
            return ((int) substr($ogrn, 0, 12) % 11 % 10) === (int) $ogrn[12];
        }

        if (strlen($ogrn) === 15)
        {
            return ((int) substr($ogrn, 0, 14) % 13 % 10) === (int) $ogrn[14];
        }

        return false;
    }

    protected static function company_name(): string
    {
        $stems = ['Строй', 'Техно', 'Софт', 'Пром', 'Агро', 'Логистик', 'Медиа', 'Энерго'];
        $tails = ['Сервис', 'Групп', 'Трейд', 'Лаб', 'Инвест', 'Прогресс'];

        return 'ООО «'.$stems[array_rand($stems)].$tails[array_rand($tails)].'»';
    }

    protected static function email(): string
    {
        $logins = ['ivan.ivanov', 'petr.s', 'test.user', 'qa', 'dev'];

        return $logins[array_rand($logins)].'.'.random_int(1, 999).'@example.test';
    }
}
