<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-646 Store — часть `Profiles`: профиль посетителя (аллергии, диета, кожа) и «удалить мои данные».
 *
 * Трейт класса `Service_Nj646_Store` (см. его шапку): разделы вынесены из одного
 * файла, публичные имена методов не менялись. Свойства и общие помощники
 * (`self::db()`, `self::_all()`, `self::ensure_*()`…) живут в самом классе.
 */
trait Service_Nj646_Store_Profiles
{

    public static function profile($visitor)
    {
        self::ensure_tables();

        $row = self::_one('SELECT * FROM nj_646_profiles WHERE visitor = '.self::db()->quote((string) $visitor));

        if ( ! $row)
        {
            return ['skin' => '', 'diet' => 'none', 'conditions' => [], 'allergies' => ''];
        }

        $row['conditions'] = self::_json_decode($row['conditions']);

        return $row;
    }

    public static function profile_save($visitor, array $profile)
    {
        self::ensure_tables();

        $db = self::db();

        $skin  = preg_match('/^[a-z_]{0,20}$/', (string) ($profile['skin'] ?? '')) ? (string) $profile['skin'] : '';
        $diet  = in_array(($profile['diet'] ?? 'none'), ['none', 'vegetarian', 'vegan'], TRUE) ? $profile['diet'] : 'none';
        $cond  = array_values(array_filter(array_map('strval', (array) ($profile['conditions'] ?? [])),
            function ($c) { return preg_match('/^[a-z_]{2,20}$/', $c); }));

        DB::query(NULL, 'INSERT INTO nj_646_profiles (visitor, skin, diet, conditions, allergies, updated_at)
                         VALUES ('.$db->quote((string) $visitor).', '.$db->quote($skin).', '.$db->quote($diet).',
                                 '.self::_json($cond).', '.$db->quote(mb_substr((string) ($profile['allergies'] ?? ''), 0, 400)).',
                                 now())
                         ON CONFLICT ("visitor") DO UPDATE SET
                             skin = EXCLUDED.skin, diet = EXCLUDED.diet,
                             conditions = EXCLUDED.conditions, allergies = EXCLUDED.allergies,
                             updated_at = now()')->execute();

        return self::profile($visitor);
    }

    /**
     * Забыть посетителя: профиль, разборы, прогоны и голоса — по его метке.
     *
     * Зачем кнопка. Профиль хранит аллергии и особенности (данные медицинского
     * характера), а разборы — текст, который человек вставил с упаковки. Правило
     * «само удалится через год» человека не устраивает: пока кнопки нет, он не
     * может распорядиться своими данными (ревизия 03.10.2026, п. 3). Здесь одно
     * место, которое знает про все таблицы с меткой, — страница профиля его и
     * зовёт.
     *
     * Что НЕ удаляем. Карточки веществ, товары, производителей и очередь
     * незнакомых: это общая база, собранная из этикеток, и к конкретному
     * человеку она не относится (просьба владельца — сопутствующие данные
     * этикетки копить для проекта в целом). Живой прогон из очереди тоже не
     * выбрасываем — воркер уже читает фото, и задача сломалась бы на середине;
     * у живого прогона стирается метка, и результат ни к кому не привязан.
     *
     * @return array счётчики удалённого (для честного ответа человеку)
     */
    public static function forget_visitor($visitor, $user_id = NULL)
    {
        self::ensure_tables();

        $db      = self::db();
        $visitor = (string) $visitor;
        $user_id = (int) $user_id;

        $out = ['profiles' => 0, 'analyses' => 0, 'runs' => 0, 'votes' => 0, 'runs_detached' => 0];

        if ($visitor === '' AND $user_id <= 0)
        {
            return $out;
        }

        // Профиль и голоса — только по метке: колонки user_id у них нет.
        $by_visitor = $visitor !== '' ? 'visitor = '.$db->quote($visitor) : 'FALSE';
        // Разборы и прогоны у вошедшего могут быть привязаны ещё и к логину.
        $by_person  = $user_id > 0 ? '('.$by_visitor.' OR user_id = '.$user_id.')' : $by_visitor;

        $out['profiles'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_profiles WHERE '.$by_visitor, 'c');
        DB::query(NULL, 'DELETE FROM nj_646_profiles WHERE '.$by_visitor)->execute();

        // Голос — вклад посетителя в общую базу, и отозвать его он вправе.
        $out['votes'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_votes WHERE '.$by_visitor, 'c');
        DB::query(NULL, 'DELETE FROM nj_646_votes WHERE '.$by_visitor)->execute();

        $out['analyses'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_analyses WHERE '.$by_person, 'c');
        DB::query(NULL, 'DELETE FROM nj_646_analyses WHERE '.$by_person)->execute();

        $live = "status IN ('queued', 'running')";

        $out['runs'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_runs WHERE '.$by_person.' AND NOT ('.$live.')', 'c');
        DB::query(NULL, 'DELETE FROM nj_646_runs WHERE '.$by_person.' AND NOT ('.$live.')')->execute();

        $out['runs_detached'] = (int) self::_one('SELECT COUNT(*) AS c FROM nj_646_runs WHERE '.$by_person.' AND '.$live, 'c');

        if ($out['runs_detached'] > 0)
        {
            DB::update('nj_646_runs')
                ->set(['visitor' => '', 'client_ip' => '', 'user_id' => NULL])
                ->where('visitor', '=', $visitor)
                ->where('status', 'IN', ['queued', 'running'])
                ->execute();
        }

        return $out;
    }
}
