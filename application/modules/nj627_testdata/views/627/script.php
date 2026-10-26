<?php defined('SYSPATH') or die('No direct script access.'); ?>
<script>
/** nj627_testdata — генератор тестовых данных (клиентское зеркало Service_Nj627_Testdata). */
(function () {
    'use strict';
    var genBtn = document.getElementById('nj627-gen');
    var list = document.getElementById('nj627-list');
    var countIn = document.getElementById('nj627-count');
    var copyBtn = document.getElementById('nj627-copy');
    var sub = document.getElementById('nj627-sub');

    var subLabels = {
        fio: 'фамилия имя отчество — под тесты форм и документов',
        phone: '+7 9XX … — российский мобильный формат',
        email: 'адрес в зарезервированном домене example.test',
        company: 'название + ИНН, КПП, ОГРН с корректными контрольными цифрами'
    };

    var LAST = ['Иванов', 'Петров', 'Сидоров', 'Кузнецова', 'Смирнов', 'Волков', 'Соколова', 'Козлов', 'Новикова', 'Фёдоров'];
    var FIRST = ['Иван', 'Пётр', 'Сергей', 'Анна', 'Мария', 'Ольга', 'Дмитрий', 'Елена', 'Николай', 'Татьяна'];
    var PATR = ['Иванович', 'Петровна', 'Сергеевич', 'Андреевна', 'Николаевич', 'Павловна', 'Михайлович', 'Алексеевна'];
    var STEMS = ['Строй', 'Техно', 'Софт', 'Пром', 'Агро', 'Логистик', 'Медиа', 'Энерго'];
    var TAILS = ['Сервис', 'Групп', 'Трейд', 'Лаб', 'Инвест', 'Прогресс'];
    var EMAILS = ['ivan.ivanov', 'petr.s', 'test.user', 'qa', 'dev'];

    function ri(min, max) {
        var b = new Uint32Array(1);
        crypto.getRandomValues(b);
        return min + (b[0] % (max - min + 1));
    }
    function pick(a) { return a[ri(0, a.length - 1)]; }
    function pad(n, len) { return String(n).padStart(len, '0'); }

    function checksum(digits, weights) {
        var sum = 0;
        weights.forEach(function (w, i) { sum += parseInt(digits[i], 10) * w; });
        return sum % 11 % 10;
    }

    function inn10() {
        var base = '7700' + pad(ri(0, 99999), 5);
        return base + checksum(base, [2, 4, 10, 3, 5, 9, 4, 6, 8]);
    }
    function kpp() {
        return '7700' + '01' + pad(ri(1, 999), 3);
    }
    function ogrn() {
        var base = '1' + pad(ri(2, 20), 2) + '77' + pad(ri(0, 9999999), 7) + '0';
        return base.slice(0, 12) + String((parseInt(base.slice(0, 12), 10) % 11) % 10);
    }

    function one(type) {
        switch (type) {
            case 'fio': return pick(LAST) + ' ' + pick(FIRST) + ' ' + pick(PATR);
            case 'phone': return '+7 ' + ri(900, 999) + ' ' + pad(ri(0, 999), 3) + '-' + pad(ri(0, 99), 2) + '-' + pad(ri(0, 99), 2);
            case 'email': return pick(EMAILS) + '.' + ri(1, 999) + '@example.test';
            case 'company': return 'ООО «' + pick(STEMS) + pick(TAILS) + '» — ИНН ' + inn10() + ' · КПП ' + kpp() + ' · ОГРН ' + ogrn();
        }
    }

    function typeValue() {
        var el = document.querySelector('input[name="nj627-type"]:checked');
        return el ? el.value : 'fio';
    }

    function generate() {
        var type = typeValue();
        var n = Math.max(1, Math.min(100, parseInt(countIn.value, 10) || 5));
        sub.textContent = subLabels[type];
        var rows = [];
        for (var i = 0; i < n; i++) rows.push(one(type));
        list.innerHTML = rows.map(function (r) {
            return '<li class="group flex items-start gap-2 rounded-lg bg-gray-50 px-3 py-2 dark:bg-gray-950">' +
                '<span class="break-all">' + r.replace(/</g, '&lt;') + '</span>' +
                '<button type="button" class="nj627-one ml-auto shrink-0 rounded px-2 py-1 text-xs text-gray-400 opacity-0 transition group-hover:opacity-100 hover:text-blue-600" data-v="' + r.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"><i class="fas fa-copy"></i></button></li>';
        }).join('');
        copyBtn.disabled = false;
        list.querySelectorAll('.nj627-one').forEach(function (b) {
            b.addEventListener('click', function () { copyText(b.getAttribute('data-v')); });
        });
    }

    function toast(m) { N2J.toast(m); }
    function copyText(t) {
        N2J.copyText(t).then(function () { toast('Скопировано'); }, function () { N2J.toast('Не удалось скопировать'); });
    }

    genBtn.addEventListener('click', generate);
    copyBtn.addEventListener('click', function () {
        var vals = Array.prototype.map.call(list.querySelectorAll('.nj627-one'), function (b) { return b.getAttribute('data-v'); });
        if (vals.length) copyText(vals.join('\n'));
    });
    document.querySelectorAll('input[name="nj627-type"]').forEach(function (r) { r.addEventListener('change', generate); });

    generate();
})();
</script>
