<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/**
 * nj615_markdown — клиентское зеркало Service_Nj615_Markdown (Markdown-lite → HTML).
 */
(function () {
    'use strict';

    var input = document.getElementById('nj615-input');
    var preview = document.getElementById('nj615-preview');
    var statsWrap = document.getElementById('nj615-stats-wrap');
    var statsEl = document.getElementById('nj615-stats');
    var sampleBtn = document.getElementById('nj615-sample');
    var clearBtn = document.getElementById('nj615-clear');
    var copyHtml = document.getElementById('nj615-copy-html');
    var copyMd = document.getElementById('nj615-copy-md');

    var SAMPLE = "# Заголовок\n\n**Жирный** текст, *курсив* и ~~зачёркнутый~~. Вот `код`, а вот [ссылка](https://n2j.ru).\n\n## Список\n\n- пункт один\n- пункт два\n\n1. первый\n2. второй\n\n> Цитата с мыслью.\n\n```\ncode block\nline two\n```\n";

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function inline(s) {
        s = esc(s);
        // сначала код, чтобы не трогать разметку внутри
        s = s.replace(/`([^`]+)`/g, '<code class="rounded-md bg-gray-100 px-1.5 py-0.5 font-mono text-[0.85em] text-blue-700 dark:bg-gray-900 dark:text-blue-300">$1</code>');
        s = s.replace(/(\*\*|__)(.+?)\1/g, '<strong class="font-semibold">$2</strong>');
        s = s.replace(/(~~)(.+?)\1/g, '<del class="opacity-60">$2</del>');
        s = s.replace(/(?<![\w*])\*([^*\n]+)\*(?![\w*])/g, '<em>$1</em>');
        s = s.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, '<img src="$2" alt="$1" class="mt-2 max-w-full rounded-lg" loading="lazy">');
        s = links(s);
        return s;
    }

    function links(s) {
        return s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (_, t, u) {
            if (/^(https?:\/\/|mailto:|#)/i.test(u)) {
                return '<a href="' + u + '" class="font-medium text-blue-600 underline decoration-blue-300 underline-offset-2 hover:text-blue-700 dark:text-blue-400" rel="noopener noreferrer">' + t + '</a>';
            }
            return t;
        });
    }

    function toHtml(md) {
        var lines = md.split(/\r?\n/);
        var out = [], para = [], code = null, quote = [], list = null;
        var flushP = function () {
            if (para.length) { out.push('<p class="mt-4 break-words">' + inline(para.join('\n')) + '</p>'); para = []; }
        };
        var closeList = function () { if (list) { out.push('</' + list + '>'); list = null; } };
        var flushCode = function () { if (code) { out.push('<pre class="mt-4 overflow-auto rounded-lg bg-gray-100 p-3 font-mono text-sm dark:bg-gray-900"><code>' + esc(code.join('\n')) + '</code></pre>'); code = null; } };
        var flushQuote = function () { if (quote.length) { out.push('<blockquote class="mt-4 border-l-4 border-gray-300 pl-4 italic text-gray-600 opacity-90 dark:border-gray-600 dark:text-gray-400"><p>' + inline(quote.join('\n')) + '</p></blockquote>'); quote = []; } };

        lines.forEach(function (ln) {
            var t = ln;

            if (/^```/.test(t)) { if (code) { flushCode(); } else { flushP(); closeList(); flushQuote(); code = []; } return; }
            if (code) { code.push(t); return; }

            var m;
            if ((m = t.match(/^(#{1,6})\s+(.+)$/))) {
                flushP(); closeList(); flushQuote();
                var lvl = m[1].length;
                var sizes = {1: 'text-2xl font-bold', 2: 'text-xl font-bold', 3: 'text-lg font-bold', 4: 'text-base font-bold', 5: 'text-sm font-bold', 6: 'text-xs font-bold'};
                out.push('<h' + lvl + ' class="mt-6 tracking-tight text-gray-900 ' + sizes[lvl] + ' dark:text-white">' + inline(m[2]) + '</h' + lvl + '>');
                return;
            }
            if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(t)) { flushP(); closeList(); flushQuote(); out.push('<hr class="my-6 border-gray-200 dark:border-gray-700">'); return; }
            if ((m = t.match(/^>\s?(.*)$/))) { flushP(); closeList(); quote.push(m[1]); return; }
            if ((m = t.match(/^\s*([-*+])\s+(.+)$/))) {
                flushP(); flushQuote();
                if (list !== 'ul') { closeList(); out.push('<ul class="mt-4 list-disc pl-6">'); list = 'ul'; }
                out.push('<li class="mt-1">' + inline(m[2]) + '</li>');
                return;
            }
            if ((m = t.match(/^\s*(\d+)[.)]\s+(.+)$/))) {
                flushP(); flushQuote();
                if (list !== 'ol') { closeList(); out.push('<ol class="mt-4 list-decimal pl-6">'); list = 'ol'; }
                out.push('<li class="mt-1">' + inline(m[2]) + '</li>');
                return;
            }
            closeList(); flushQuote();
            if (!t.trim()) { flushP(); return; }
            para.push(t);
        });

        flushCode(); flushP(); closeList(); flushQuote();
        return out.join('\n') || '<p class="text-gray-400">Предпросмотр появится здесь.</p>';
    }

    function stats(md) {
        var plain = md.replace(/```[\s\S]*?```/g, ' ');
        plain = plain.replace(/`[^`]*`/g, ' ');
        plain = plain.replace(/[#>*_~\-\[\]()!`]/g, ' ');
        var words = (plain.match(/\b[\p{L}\p{N}]+\b/gu) || []).length;
        return { words: words, lines: md.split(/\r?\n/).length };
    }

    function run() {
        var md = input.value;
        if (!md.trim()) {
            preview.innerHTML = '<p class="text-gray-400">Предпросмотр появится здесь.</p>';
            statsWrap.classList.add('hidden');
            return;
        }
        preview.innerHTML = toHtml(md);
        var st = stats(md);
        statsWrap.classList.remove('hidden');
        statsEl.innerHTML =
            '<div class="flex items-baseline justify-between border-b border-gray-100 pb-1 dark:border-gray-700/60"><dt class="text-xs text-gray-500 dark:text-gray-400">Слов</dt><dd class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100">' + st.words + '</dd></div>' +
            '<div class="flex items-baseline justify-between border-b border-gray-100 pb-1 dark:border-gray-700/60"><dt class="text-xs text-gray-500 dark:text-gray-400">Строк</dt><dd class="font-mono text-sm font-bold text-gray-800 dark:text-gray-100">' + st.lines + '</dd></div>';
        updateUrl(md);
    }

    function updateUrl(md) {
        try {
            if (!md.trim()) { history.replaceState(null, '', location.pathname); return; }
            var p = new URLSearchParams(location.search);
            if (md.length < 2000) p.set('q', md); else p.delete('q');
            var qs = p.toString();
            history.replaceState(null, '', location.pathname + (qs ? '?' + qs : ''));
        } catch (e) { }
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    var deb = null;
    input.addEventListener('input', function () { clearTimeout(deb); deb = setTimeout(run, 200); });
    sampleBtn.addEventListener('click', function () { input.value = SAMPLE; run(); input.focus(); });
    clearBtn.addEventListener('click', function () { input.value = ''; run(); input.focus(); });
    copyHtml.addEventListener('click', function () { copyText(toHtml(input.value)); });
    copyMd.addEventListener('click', function () { copyText(input.value); });

    if (input.value.trim()) run();
})();
</script>
