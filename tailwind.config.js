/**
 * Сборка Tailwind для prx.ru.
 *
 * До 28.09.2026 страницы подключали cdn.tailwindcss.com — это не файл, а
 * компилятор, который на КАЖДОЙ загрузке страницы разбирал вёрстку в браузере.
 * Внешний хост без фолбэка (его блокировка = сайт без стилей), лишняя работа у
 * каждого посетителя и предупреждение самого Tailwind «Play CDN не для
 * продакшена». Теперь css собирается здесь и лежит в public_html/assets/css.
 *
 *   npm run build:css     собрать (минифицированно)
 *   npm run watch:css     пересобирать на лету при разработке
 *
 * CI пересобирает и сверяет с тем, что в репозитории: расхождение = ошибка.
 *
 * Конфигурация — ОБЪЕДИНЕНИЕ четырёх инлайновых `tailwind.config`, которые
 * жили в разных шаблонах и потихоньку расходились:
 *   views/template_tailwindcss.php          primary, dark, shadow-xs, ring-3
 *   prx218/layout/template.php              primary, dark, status_*
 *   prx111/migrate/index.php                 только darkMode
 *   601/finish.php                          конфига не было вовсе
 */
module.exports = {
    darkMode: 'class',

    // Где искать имена классов. Tailwind читает файлы как текст, поэтому
    // тернарники в PHP (`$x ? 'bg-red-500' : 'bg-green-500'`) он видит —
    // не видит только склейку (`'bg-' . $color`), для неё есть safelist ниже.
    content: [
        './application/**/*.php',
        './public_html/*.php',
        './public_html/assets/js/**/*.js',
    ],

    theme: {
        extend: {
            colors: {
                primary: '#3b82f6',
                dark: '#0f172a',

                // статусы модулей в админке prx218
                status_alpha: '#f59e0b',
                status_beta: '#3b82f6',
                status_stable: '#10b981',
                status_archived: '#6b7280',
            },

            // --- совместимость с именами Tailwind v4 -------------------------
            // Вёрстка писалась по эталону дизайна на v4, а собираем v3, где
            // этих имён в шкале нет. Два первых подставляли и раньше (инлайн
            // в шаблоне), остальные нашлись 28.09.2026 при сверке классов с
            // собранным css: они стояли в разметке и не рендерились НИКОГДА —
            // ни сейчас, ни на Play CDN.
            boxShadow: {
                xs: '0 1px 2px 0 rgb(0 0 0 / 0.05)',   // эталон: 45 мест
            },
            ringWidth: {
                3: '3px',                              // эталон: 11 мест
            },
            backdropBlur: {
                xs: '4px',                             // во v3 это blur-sm
            },
            spacing: {
                4.5: '1.125rem',                       // переключатель в prx602
                5.5: '1.375rem',
            },
            maxWidth: {
                '8xl': '88rem',                        // шире 7xl, prx217
            },
        },
    },

    plugins: [],
};
