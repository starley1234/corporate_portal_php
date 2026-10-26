<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Markdown-инструмент (модуль NJ-615) — Markdown-lite → HTML без внешних библиотек.
 *
 * Одна точка истины для страницы (/markdown), API (/nj615/api/*) и агентов:
 * компактный, безопасный рендер подмножества Markdown (заголовки, параграфы,
 * списки, цитаты, код, ссылки, изображения, жирный/курсив/зачёркнутый, hr).
 * JS-зеркало живёт во views/615/script.php — структура HTML одинаковая.
 *
 * Безопасность: на входе всё экранируется, ссылки — только http(s)/mailto/#;
 * никакой сырой HTML из пользовательского текста не проходит.
 */
class Service_Nj615_Markdown {

    const MAX_INPUT = 2 * 1024 * 1024;

    /**
     * Markdown → HTML-фрагмент (для страницы и API).
     *
     * @param  string $md
     * @return array  ['ok','html','error','stats']
     */
    public static function render($md)
    {
        $md = (string) $md;

        if ($md === '')
        {
            return ['ok' => FALSE, 'html' => '', 'error' => 'Вставьте Markdown — поле пустое.', 'stats' => NULL];
        }

        if (mb_strlen($md) > self::MAX_INPUT)
        {
            return ['ok' => FALSE, 'html' => '', 'error' => 'Слишком большой текст (лимит '.self::MAX_INPUT.' символов).', 'stats' => NULL];
        }

        $html = self::to_html($md);

        return [
            'ok'    => TRUE,
            'html'  => $html,
            'error' => '',
            'stats' => self::stats($md),
        ];
    }

    /** Статистика текста: слова в прозе (без разметки), строки, код-блоки, ссылки. */
    public static function stats($md)
    {
        $md    = (string) $md;
        $plain = preg_replace('/```.*?```/s', ' ', $md);
        $plain = preg_replace('/`[^`]*`/', ' ', (string) $plain);
        $plain = preg_replace('/[#>*_~\-\[\]()!`]/', ' ', (string) $plain);

        preg_match_all('/\b[\p{L}\p{N}]+\b/u', (string) $plain, $words);

        return [
            'words'     => count($words[0]),
            'lines'     => substr_count($md, "\n") + 1,
            'code_fence'=> preg_match_all('/^```/m', $md),
            'links'     => preg_match_all('/\[[^\]]*\]\([^)]+\)/', $md),
        ];
    }

    /**
     * Markdown-lite → HTML. Подмножество покрывает 95% практических заметок.
     */
    public static function to_html($md)
    {
        $lines = preg_split('~\r?\n~', (string) $md);
        $out   = [];
        $list  = NULL;   // 'ul' | 'ol' | NULL
        $para  = [];
        $code  = [];
        $quote = [];

        $flush_para = function () use (&$para, &$out) {
            if ($para)
            {
                $out[] = '<p class="mt-4 break-words">'.self::inline(implode("\n", $para)).'</p>';
                $para = [];
            }
        };
        $close_list = function () use (&$list, &$out) {
            if ($list !== NULL)
            {
                $out[] = '</'.$list.'>';
                $list = NULL;
            }
        };
        $flush_code = function () use (&$code, &$out) {
            if ($code)
            {
                $out[] = '<pre class="mt-4 overflow-auto rounded-lg bg-gray-100 p-3 font-mono text-sm dark:bg-gray-900"><code>'.HTML::chars(implode("\n", $code)).'</code></pre>';
                $code = [];
            }
        };
        $flush_quote = function () use (&$quote, &$out) {
            if ($quote)
            {
                $out[] = '<blockquote class="mt-4 border-l-4 border-gray-300 pl-4 italic text-gray-600 opacity-90 dark:border-gray-600 dark:text-gray-400"><p>'.self::inline(implode("\n", $quote)).'</p></blockquote>';
                $quote = [];
            }
        };

        foreach ($lines as $ln)
        {
            $t = $ln;

            // код-блок
            if (preg_match('~^```~', $t))
            {
                if ($code)
                {
                    $flush_code();
                }
                else
                {
                    $flush_para();
                    $close_list();
                    $flush_quote();
                    $code = [];
                }
                continue;
            }

            if ($code)
            {
                $code[] = $t;
                continue;
            }

            // заголовки
            if (preg_match('~^(#{1,6})\s+(.+)$~', $t, $m))
            {
                $flush_para();
                $close_list();
                $flush_quote();
                $level = strlen($m[1]);
                $sizes = [1 => 'text-2xl font-bold', 2 => 'text-xl font-bold', 3 => 'text-lg font-bold', 4 => 'text-base font-bold', 5 => 'text-sm font-bold', 6 => 'text-xs font-bold'];
                $out[] = '<h'.$level.' class="mt-6 tracking-tight text-gray-900 '.$sizes[$level].' dark:text-white">'.self::inline($m[2]).'</h'.$level.'>';
                continue;
            }

            // hr
            if (preg_match('~^\s*([-*_])(\s*\1){2,}\s*$~', $t))
            {
                $flush_para();
                $close_list();
                $flush_quote();
                $out[] = '<hr class="my-6 border-gray-200 dark:border-gray-700">';
                continue;
            }

            // цитата
            if (preg_match('~^>\s?(.*)$~', $t, $m))
            {
                $flush_para();
                $close_list();
                $quote[] = $m[1];
                continue;
            }

            // списки
            if (preg_match('~^\s*([-*+])\s+(.+)$~', $t, $m))
            {
                $flush_para();
                $flush_quote();
                if ($list !== 'ul')
                {
                    $close_list();
                    $out[] = '<ul class="mt-4 list-disc pl-6">';
                    $list = 'ul';
                }
                $out[] = '<li class="mt-1">'.self::inline($m[2]).'</li>';
                continue;
            }

            if (preg_match('~^\s*(\d+)[.)]\s+(.+)$~', $t, $m))
            {
                $flush_para();
                $flush_quote();
                if ($list !== 'ol')
                {
                    $close_list();
                    $out[] = '<ol class="mt-4 list-decimal pl-6">';
                    $list = 'ol';
                }
                $out[] = '<li class="mt-1">'.self::inline($m[2]).'</li>';
                continue;
            }

            $close_list();
            $flush_quote();

            if (trim($t) === '')
            {
                $flush_para();
                continue;
            }

            $para[] = $t;
        }

        $flush_code();
        $flush_para();
        $close_list();
        $flush_quote();

        if ( ! $out)
        {
            $out[] = '<p class="text-gray-400">Пусто.</p>';
        }

        return implode("\n", $out);
    }

    /** Инлайн-разметка внутри строки (полностью экранируем перед вставкой). */
    public static function inline($s)
    {
        $s = HTML::chars((string) $s);

        // код `...`
        $s = preg_replace('~`([^`]+)`~', '<code class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[0.85em] text-blue-700 dark:bg-gray-900 dark:text-blue-300">$1</code>', $s);
        // жирный **...**
        $s = preg_replace('~\*\*(.+?)\*\*~s', '<strong class="font-semibold">$1</strong>', $s);
        // зачёркнутый ~~...~~
        $s = preg_replace('#~~(.+?)~~#s', '<del class="opacity-60">$1</del>', $s);
        // курсив *...* (после жирного, чтобы не ломал **)
        $s = preg_replace('~(?<![\w*])\*([^*\n]+)\*(?![\w*])~', '<em>$1</em>', $s);
        // изображения ![alt](url)
        $s = preg_replace('~!\[([^\]]*)\]\(([^)\s]+)\)~', '<img src="$2" alt="$1" class="mt-2 max-w-full rounded-lg" loading="lazy">', $s);
        // ссылки [text](url)
        $s = self::links($s);

        return $s;
    }

    /** Ссылки — только безопасные схемы; javascript: и прочее отсекается. */
    protected static function links($s)
    {
        return preg_replace_callback('~\[([^\]]+)\]\(([^)\s]+)\)~', function ($m) {
            $url = $m[2];

            if (preg_match('~^(https?://|mailto:|#)~i', $url))
            {
                return '<a href="'.$url.'" class="font-medium text-blue-600 underline decoration-blue-300 underline-offset-2 hover:text-blue-700 dark:text-blue-400" rel="noopener noreferrer">'.$m[1].'</a>';
            }

            return $m[1]; // опасная схема — оставляем текст
        }, $s);
    }
}
