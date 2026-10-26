<?php defined('SYSPATH') or die('No direct script access.');

/**
 * NJ-651 «Калории по фото тарелки» — чистая логика: без базы, без сети.
 *
 * Модель оценивает, ЧТО на тарелке и СКОЛЬКО этого примерно по весу. Дальше
 * начинается арифметика, и она целиком наша: калорийность по белкам, жирам и
 * углеводам, итог по тарелке, суточная норма человека и доля тарелки от неё.
 * Поэтому всё, что ниже, проверяется юнит-тестом без запуска модели.
 *
 * Про точность говорим честно: по фото нельзя узнать, сколько масла попало на
 * сковородку. Сервис даёт порядок величины (±20–30 %), а не медицинский
 * расчёт, и эта оговорка есть и в интерфейсе.
 */
class Service_Nj651_Meal
{
    /** Калорийность макронутриентов, ккал на грамм (Этуотер). */
    const KCAL_PROTEIN = 4.0;
    const KCAL_FAT     = 9.0;
    const KCAL_CARBS   = 4.0;

    /** Уровни активности: ключ => [подпись, коэффициент к основному обмену]. */
    const ACTIVITY = [
        'low'    => ['Сидячая работа, без спорта', 1.2],
        'light'  => ['Лёгкая нагрузка, 1–3 тренировки в неделю', 1.375],
        'medium' => ['Средняя нагрузка, 3–5 тренировок', 1.55],
        'high'   => ['Высокая нагрузка, 6–7 тренировок', 1.725],
    ];

    /** Цели: ключ => [подпись, множитель к норме]. */
    const GOALS = [
        'loss' => ['Снижение веса', 0.85],
        'keep' => ['Поддержание веса', 1.0],
        'gain' => ['Набор массы', 1.1],
    ];

    /** Значения профиля по умолчанию: «средний взрослый». */
    const DEFAULT_PROFILE = [
        'sex' => 'f', 'weight' => 68, 'height' => 170, 'age' => 35,
        'activity' => 'light', 'goal' => 'keep',
    ];

    /** Каталог закрытых миниатюр истории (строго вне docroot). */
    public static function preview_dir()
    {
        $dir = APPPATH.'../storage/nj651/previews';

        if ( ! is_dir($dir))
        {
            @mkdir($dir, 0700, TRUE);
        }

        // Повторно исправляем права после старых деплоев/созданий с umask 022.
        // В каталоге лежит персональное фото, поэтому только владелец PHP его обходит.
        @chmod($dir, 0700);

        return $dir;
    }

    /** Путь задаётся только числовым id — путь пользователя сюда не попадает. */
    protected static function preview_path($run_id)
    {
        $run_id = (int) $run_id;

        return $run_id > 0 ? self::preview_dir().DIRECTORY_SEPARATOR.$run_id.'.jpg' : '';
    }

    /**
     * Сохранить компактную JPEG-миниатюру до удаления исходного кадра.
     * Ошибка здесь не должна мешать постановке разбора в очередь.
     */
    public static function preview_save($run_id, $bytes)
    {
        $path  = self::preview_path($run_id);
        $bytes = (string) $bytes;

        if ($path === '' OR $bytes === '' OR ! function_exists('imagecreatefromstring')
            OR ! function_exists('getimagesizefromstring'))
        {
            return FALSE;
        }

        // Отсекаем фотографии с экстремальным числом пикселей до GD-декодера:
        // миниатюра необязательна и не должна выбить память из upload-запроса.
        $meta = @getimagesizefromstring($bytes);

        if ( ! is_array($meta) OR empty($meta[0]) OR empty($meta[1])
            OR (int) $meta[0] * (int) $meta[1] > 12000000)
        {
            return FALSE;
        }

        $source = @imagecreatefromstring($bytes);

        if ( ! $source)
        {
            return FALSE;
        }

        $width  = imagesx($source);
        $height = imagesy($source);

        if ($width < 1 OR $height < 1)
        {
            imagedestroy($source);
            return FALSE;
        }

        $scale = min(1, 320 / max($width, $height));
        $to_w  = max(1, (int) round($width * $scale));
        $to_h  = max(1, (int) round($height * $scale));
        $thumb = imagecreatetruecolor($to_w, $to_h);

        if ( ! $thumb)
        {
            imagedestroy($source);
            return FALSE;
        }

        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        $copied = imagecopyresampled($thumb, $source, 0, 0, 0, 0,
            $to_w, $to_h, $width, $height);

        ob_start();
        $encoded = ($copied AND imagejpeg($thumb, NULL, 76));
        $jpeg = (string) ob_get_clean();

        imagedestroy($thumb);
        imagedestroy($source);

        if ( ! $encoded OR $jpeg === '' OR strlen($jpeg) > 250000)
        {
            return FALSE;
        }

        $dir = dirname($path);
        $tmp = @tempnam($dir, '.meal-preview-');

        if ($tmp === FALSE)
        {
            return FALSE;
        }

        $saved = @file_put_contents($tmp, $jpeg, LOCK_EX);

        if ($saved !== strlen($jpeg) OR ! @rename($tmp, $path))
        {
            @unlink($tmp);
            return FALSE;
        }

        @chmod($path, 0600);

        return TRUE;
    }

    public static function preview_exists($run_id)
    {
        $path = self::preview_path($run_id);

        $size = ($path !== '' AND is_file($path)) ? (int) @filesize($path) : 0;

        return $size >= 4 AND $size <= 250000;
    }

    /** Прочитать JPEG для owner-checked API, а не отдавать файл как public URL. */
    public static function preview_bytes($run_id)
    {
        $path = self::preview_path($run_id);

        if ($path === '' OR ! is_file($path))
        {
            return '';
        }

        $size = (int) @filesize($path);

        if ($size < 4 OR $size > 250000)
        {
            return '';
        }

        $bytes = (string) @file_get_contents($path);

        return strncmp($bytes, "\xFF\xD8\xFF", 3) === 0 ? $bytes : '';
    }

    public static function preview_delete($run_id)
    {
        $path = self::preview_path($run_id);

        return $path === '' OR ! is_file($path) OR @unlink($path);
    }

    /**
     * Удалять миниатюры по сроку истории, а оборванные временные файлы — через день.
     */
    public static function preview_gc($days = 90, $limit = 200)
    {
        $dir   = self::preview_dir();
        $files = array_merge(
            glob($dir.DIRECTORY_SEPARATOR.'*.jpg') ?: [],
            glob($dir.DIRECTORY_SEPARATOR.'.meal-preview-*') ?: []
        );
        $now       = time();
        $cut       = $now - max(1, (int) $days) * 86400;
        $temp_cut  = $now - 86400;
        $gone      = 0;

        // glob сортирует по имени, а id прогона не совпадает с возрастом
        // файлов после удаления строк. Старейшие проверяем первыми, иначе
        // лимит мог бы вечно обходить старые файлы с большими id.
        usort($files, function ($a, $b) {
            return ((int) @filemtime($a)) <=> ((int) @filemtime($b));
        });

        foreach (array_slice($files, 0, max(1, (int) $limit)) as $path)
        {
            $mtime     = @filemtime($path);
            $temporary = strpos(basename($path), '.meal-preview-') === 0;
            $deadline  = $temporary ? $temp_cut : $cut;

            if ($mtime !== FALSE AND $mtime < $deadline AND @unlink($path))
            {
                $gone++;
            }
        }

        return $gone;
    }

    /** Удалить только свой терминальный разбор, его кадр и миниатюру. */
    public static function run_delete($run_id, $visitor, $user_id = NULL)
    {
        $run_id = (int) $run_id;
        $run    = $run_id > 0 ? Vision::run_get('nj_651_runs', $run_id) : NULL;

        if ( ! $run OR ! Vision::run_owned($run, $visitor, $user_id)
            OR in_array((string) $run['status'], ['queued', 'running'], TRUE))
        {
            return FALSE;
        }

        $db = Vision::db();

        if ((int) ($run['user_id'] ?? 0) > 0)
        {
            $owner = 'user_id = '.(int) $user_id;
        }
        else
        {
            $owner = 'COALESCE(user_id, 0) = 0 AND visitor = '.$db->quote((string) $visitor);
        }

        $row = $db->query(Database::SELECT,
            'DELETE FROM nj_651_runs
              WHERE id = '.$run_id.' AND '.$owner."
                AND status NOT IN ('queued', 'running')
              RETURNING id", FALSE)->current();

        if ( ! $row)
        {
            return FALSE;
        }

        self::preview_delete($run_id);
        Vision::photo_drop('nj651_meal', $run_id);

        return TRUE;
    }

    /**
     * Сырой ответ модели → блюда с посчитанной калорийностью и итогом.
     *
     * Что чиним за моделью:
     *   * нет калорий, но есть БЖУ — считаем по Этуотеру;
     *   * нет БЖУ, но есть калории — оставляем как есть (нулевые БЖУ честнее
     *     придуманных);
     *   * калории расходятся с БЖУ больше чем на треть — верим БЖУ: сумма
     *     макронутриентов проверяема, а «на глаз 450 ккал» — нет.
     *
     * @param  array $raw ответ воркера
     * @return array dishes|total|balance|note
     */
    public static function shape(array $raw)
    {
        $dishes = [];

        foreach ((array) ($raw['dishes'] ?? []) as $row)
        {
            if ( ! is_array($row))
            {
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', (string) ($row['name'] ?? '')));

            if ($name === '')
            {
                continue;
            }

            $grams   = self::num($row['grams'] ?? 0);
            $protein = self::num($row['protein'] ?? 0);
            $fat     = self::num($row['fat'] ?? 0);
            $carbs   = self::num($row['carbs'] ?? 0);
            $kcal    = self::num($row['kcal'] ?? 0);

            $by_macro = $protein * self::KCAL_PROTEIN + $fat * self::KCAL_FAT + $carbs * self::KCAL_CARBS;

            if ($by_macro > 0 AND ($kcal <= 0 OR abs($kcal - $by_macro) > $by_macro / 3))
            {
                $kcal = $by_macro;
            }

            if ($kcal <= 0)
            {
                continue;
            }

            $dishes[] = [
                'name'       => mb_substr($name, 0, 120),
                'grams'      => (int) round(max(0, min(5000, $grams))),
                'kcal'       => (int) round($kcal),
                'protein'    => round($protein, 1),
                'fat'        => round($fat, 1),
                'carbs'      => round($carbs, 1),
                'confidence' => (int) max(0, min(100, self::num($row['confidence'] ?? 60))),
            ];

            if (count($dishes) >= 20)
            {
                break;
            }
        }

        $total = [
            'kcal'    => (int) array_sum(array_column($dishes, 'kcal')),
            'protein' => round(array_sum(array_column($dishes, 'protein')), 1),
            'fat'     => round(array_sum(array_column($dishes, 'fat')), 1),
            'carbs'   => round(array_sum(array_column($dishes, 'carbs')), 1),
            'grams'   => (int) array_sum(array_column($dishes, 'grams')),
        ];

        return [
            'dishes'  => $dishes,
            'total'   => $total,
            'balance' => self::balance($total['protein'], $total['fat'], $total['carbs']),
            'note'    => mb_substr(trim((string) ($raw['note'] ?? '')), 0, 300),
            'title'   => $dishes ? $dishes[0]['name'] : 'Тарелка',
        ];
    }

    /**
     * Доли белков, жиров и углеводов в калориях тарелки, %.
     *
     * Считаем именно по калориям, а не по граммам: 10 г жира — это вдвое
     * больше энергии, чем 10 г белка, и «на глаз по весу» картина получается
     * обратной действительности.
     */
    public static function balance($protein, $fat, $carbs)
    {
        $p = max(0, (float) $protein) * self::KCAL_PROTEIN;
        $f = max(0, (float) $fat) * self::KCAL_FAT;
        $c = max(0, (float) $carbs) * self::KCAL_CARBS;

        $sum = $p + $f + $c;

        if ($sum <= 0)
        {
            return ['protein' => 0.0, 'fat' => 0.0, 'carbs' => 0.0];
        }

        return [
            'protein' => round($p * 100 / $sum, 1),
            'fat'     => round($f * 100 / $sum, 1),
            'carbs'   => round($c * 100 / $sum, 1),
        ];
    }

    /**
     * Профиль из параметров адреса: пол, вес, рост, возраст, активность, цель.
     *
     * Пустое поле — это не ошибка, а «считай по среднему взрослому»: сервис
     * обязан показать осмысленный ответ сразу, до всякой настройки.
     */
    public static function profile(array $query)
    {
        $p = self::DEFAULT_PROFILE;

        $sex = strtolower(trim((string) ($query['sex'] ?? '')));

        if (in_array($sex, ['m', 'f'], TRUE))
        {
            $p['sex'] = $sex;
        }

        foreach (['weight' => [30, 250], 'height' => [120, 230], 'age' => [14, 100]] as $key => $range)
        {
            $value = self::num($query[$key] ?? 0);

            if ($value > 0)
            {
                $p[$key] = (int) round(max($range[0], min($range[1], $value)));
            }
        }

        $activity = (string) ($query['activity'] ?? '');

        if (isset(self::ACTIVITY[$activity]))
        {
            $p['activity'] = $activity;
        }

        $goal = (string) ($query['goal'] ?? '');

        if (isset(self::GOALS[$goal]))
        {
            $p['goal'] = $goal;
        }

        return $p;
    }

    /**
     * Суточная норма по формуле Миффлина — Сан-Жеора (она же в большинстве
     * калькуляторов): основной обмен × активность × множитель цели.
     *
     * Норма БЖУ: белок 1,6 г на кг веса (верхняя граница обычной рекомендации
     * для активного человека), жиры — 30 % калорий, остальное углеводы.
     *
     * @return array bmr|kcal|protein|fat|carbs
     */
    public static function norm(array $profile)
    {
        $p = $profile + self::DEFAULT_PROFILE;

        $bmr = 10 * (float) $p['weight'] + 6.25 * (float) $p['height'] - 5 * (float) $p['age']
             + ($p['sex'] === 'm' ? 5 : -161);

        $kcal = $bmr
              * (float) (self::ACTIVITY[$p['activity']][1] ?? 1.375)
              * (float) (self::GOALS[$p['goal']][1] ?? 1.0);

        $protein = 1.6 * (float) $p['weight'];
        $fat     = $kcal * 0.30 / self::KCAL_FAT;
        $carbs   = max(0, ($kcal - $protein * self::KCAL_PROTEIN - $fat * self::KCAL_FAT) / self::KCAL_CARBS);

        return [
            'bmr'     => (int) round($bmr),
            'kcal'    => (int) round($kcal),
            'protein' => (int) round($protein),
            'fat'     => (int) round($fat),
            'carbs'   => (int) round($carbs),
        ];
    }

    /** Доля тарелки от суточной нормы, % (0, если норма неизвестна). */
    public static function share($kcal, $norm_kcal)
    {
        $norm_kcal = (float) $norm_kcal;

        return $norm_kcal > 0 ? round((float) $kcal * 100 / $norm_kcal, 1) : 0.0;
    }

    /**
     * Короткий вывод по тарелке — то, ради чего человек это открыл.
     *
     * Не диагноз и не диета: три ориентира, которые видно из чисел.
     */
    public static function verdict(array $total, array $norm)
    {
        $share = self::share($total['kcal'], $norm['kcal']);
        $out   = [];

        if ($share >= 60)
        {
            $out[] = 'Это больше половины дневной нормы за один приём пищи ('.self::pct($share).').';
        }
        elseif ($share >= 30)
        {
            $out[] = 'Полноценный приём пищи: примерно треть дневной нормы ('.self::pct($share).').';
        }
        else
        {
            $out[] = 'Лёгкий приём пищи: '.self::pct($share).' дневной нормы.';
        }

        $balance = self::balance($total['protein'], $total['fat'], $total['carbs']);

        if ($balance['fat'] >= 45)
        {
            $out[] = 'Больше 45 % калорий дают жиры — тарелка жирнее обычной рекомендации.';
        }

        if ($total['protein'] > 0 AND $norm['protein'] > 0 AND $total['protein'] >= $norm['protein'] / 3)
        {
            $out[] = 'Белка в тарелке примерно треть дневной нормы — это хорошо.';
        }

        return $out;
    }

    // ------------------------------------------------------------ помощники

    /** Число из ответа модели: «180 г», «12,5», 300 — всё это числа. */
    public static function num($value)
    {
        if (is_int($value) OR is_float($value))
        {
            return (float) $value;
        }

        $text = str_replace([',', ' ', "\xC2\xA0"], ['.', '', ''], (string) $value);
        $text = preg_replace('/[^0-9.\-]/', '', $text);

        return $text === '' ? 0.0 : (float) $text;
    }

    /** Проценты для интерфейса: «32,5 %». */
    public static function pct($value)
    {
        return number_format((float) $value, 1, ',', ' ').' %';
    }

    /** Склонение «1 блюдо / 2 блюда / 5 блюд». */
    public static function dishes_word($n)
    {
        $n   = abs((int) $n);
        $ten = $n % 100;
        $one = $n % 10;

        if ($ten >= 11 AND $ten <= 14)
        {
            return 'блюд';
        }

        if ($one === 1)
        {
            return 'блюдо';
        }

        if ($one >= 2 AND $one <= 4)
        {
            return 'блюда';
        }

        return 'блюд';
    }
}
