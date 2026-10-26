<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Вход «Проверка компании» с главной (nj635 → /check).
 *
 * Одна строка и две кнопки, потому что человеку нужно разное:
 *   «Проверить»    — быстро посмотреть реквизиты, вакансии и сигналы;
 *   «Полный отчёт» — сразу запустить всё и получить результат на одной странице.
 *
 * Вынесено в отдельный вид, чтобы главная и стенд проверяли один и тот же код.
 */
$q = isset($q) ? (string) $q : '';
?>
<form method="post" action="/check" class="nj-fade-up mt-3" style="animation-delay:.3s">
    <input type="hidden" name="csrf" value="<?php echo HTML::chars(Security::token()); ?>">
    <div class="grid grid-cols-2 gap-2 sm:flex">
        <label class="sr-only" for="home-check">Компания, ИНН/ОГРН, сайт или ссылка на вакансию</label>
        <input id="home-check" type="text" name="q" value="<?php echo HTML::chars($q); ?>" required autocomplete="off" aria-describedby="home-check-help" placeholder="Название, ИНН, сайт или ссылка на вакансию"
               class="n2j-input col-span-2 min-w-0">
        <button type="submit" name="act" value="card" class="n2j-btn-secondary whitespace-nowrap">
            <i class="fas fa-magnifying-glass-chart text-blue-500" aria-hidden="true"></i>Проверить
        </button>
        <button type="submit" name="act" value="run" class="n2j-btn-process whitespace-nowrap">
            <i class="fas fa-bolt" aria-hidden="true"></i>Полный отчёт
        </button>
    </div>
</form>
<p id="home-check-help" class="nj-fade-up mt-2 text-xs text-gray-500 dark:text-gray-400">Кратко — реквизиты и вакансии; полный отчёт включает AI-досье и наблюдение без переходов по сервисам.</p>
