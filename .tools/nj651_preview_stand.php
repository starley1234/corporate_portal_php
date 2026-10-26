<?php
/**
 * Файловый стенд NJ651: закрытый preview, уменьшение картинки и уборка tmp.
 * Запуск: N2J_ROOT=<repo> node /tmp/phpwasm/phpwasm_stand.mjs .tools/nj651_preview_stand.php
 */

if ( ! defined('APPPATH'))
{
    define('APPPATH', '/app/application/');
}

require_once '/app/application/modules/nj651_meal/classes/Service/Nj651/Meal.php';

$ok  = 0;
$bad = 0;

function check_preview($label, $condition)
{
    global $ok, $bad;

    if ($condition)
    {
        $ok++;
        echo "ok   $label\n";
    }
    else
    {
        $bad++;
        echo "ФЕЙЛ $label\n";
    }
}

$dir = Service_Nj651_Meal::preview_dir();
check_preview('каталог preview доступен', is_dir($dir));
check_preview('каталог preview закрыт от посторонних (0700)',
    (fileperms($dir) & 0777) === 0700);
check_preview('невалидный id не создаёт preview',
    Service_Nj651_Meal::preview_save(0, 'not an image') === FALSE);

$source = imagecreatetruecolor(640, 360);
$fill   = imagecolorallocate($source, 90, 140, 190);
imagefill($source, 0, 0, $fill);
ob_start();
imagepng($source);
$png = (string) ob_get_clean();
imagedestroy($source);

$run_id = 987651;
$saved  = Service_Nj651_Meal::preview_save($run_id, $png);
$file   = $dir.DIRECTORY_SEPARATOR.$run_id.'.jpg';
clearstatcache(TRUE, $file);

check_preview('preview создаётся из исходного кадра', $saved === TRUE AND is_file($file));
check_preview('JPEG-файл доступен только владельцу (0600)',
    is_file($file) AND (fileperms($file) & 0777) === 0600);
check_preview('preview выдаёт JPEG, а не исходный PNG',
    strncmp(Service_Nj651_Meal::preview_bytes($run_id), "\xFF\xD8\xFF", 3) === 0);

$jpeg_info = getimagesizefromstring(Service_Nj651_Meal::preview_bytes($run_id));
check_preview('preview уменьшен до 320 px по длинной стороне',
    is_array($jpeg_info) AND (int) $jpeg_info[0] === 320 AND (int) $jpeg_info[1] === 180);

$temp = $dir.DIRECTORY_SEPARATOR.'.meal-preview-orphan';
file_put_contents($temp, 'partial upload');
touch($temp, time() - 90000);
check_preview('GC удаляет оборванный temp старше суток',
    Service_Nj651_Meal::preview_gc(90, 10) === 1 AND ! file_exists($temp));

check_preview('удаление run удаляет его preview',
    Service_Nj651_Meal::preview_delete($run_id) AND ! file_exists($file));

echo "\n".($bad === 0 ? "NJ651 PREVIEW: OK ($ok)\n" : "NJ651 PREVIEW: ПРОВАЛ ($bad из ".($ok + $bad).")\n");
exit($bad === 0 ? 0 : 1);
