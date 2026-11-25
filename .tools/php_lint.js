#!/usr/bin/env node
/**
 * PHP-гейт: полный парс всех php-файлов репозитория через glayzzle/php-parser.
 *
 * Ловит три класса проблем, которые пропустили php_balance.py и гейт Д1
 * (оба проверяют только парность кавычек/скобок по тексту):
 *
 *  1. Ошибки синтаксиса PHP (битые конкатенации строк и т.п.).
 *     Реальный кейс: Intake.php:443 `", ":text", "` — SQL-строка закрылась,
 *     плейсхолдер стал голым токеном. Прод упал с ParseError 25.11.2026.
 *
 *  2. Строка-литерал, целиком равная ":name", стоящая ОПЕРАНДОМ КОНКАТЕНАЦИИ
 *     (`.`): плейсхолдер попал в PHP-кавычки вместо того, чтобы лежать внутри
 *     SQL-строки. Легитимные `[':name' => …]`, `->param(':id', …)`,
 *     `[':value', 100]` — не конкатенации, они этим правилом не задеваются.
 *
 *  3. Внутри SQL-литерала: запятая перед `)` или двойная запятая —
 *     для SQL это всегда ошибка (хвостовая запятая в VALUES из Intake.php:445).
 *
 * Запуск: node .tools/php_lint.js [каталог...]
 * Без аргументов: application/, modules/, public_html/, bin/.
 * Выход: 0 — чисто, 1 — есть находки, 2 — нет пакета php-parser.
 *
 * Зависимость: php-parser в package.json (npm install).
 */
'use strict';

const fs = require('fs');
const path = require('path');

let PHPParser;
try {
    PHPParser = require('php-parser');
} catch (e) {
    console.error('php_lint: нет пакета php-parser (npm install) — проверка пропущена');
    process.exit(2);
}

const ROOT = path.resolve(__dirname, '..');
const DEFAULT_DIRS = ['application', 'modules', 'public_html', 'bin'];
const SKIP_PARTS = new Set(['node_modules', 'vendor', '.git']);
// Тесты фреймворка (не наш код, в рантайме не участвуют): парсер не берёт
// валидный `clone($cache)` из Kohana CacheTest — не чиним чужое, не сканируем.
const SKIP_TESTS = /(?:^|\/)modules\/[^/]+\/tests\//;

function* walk(dir) {
    let entries;
    try {
        entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
        return;
    }
    for (const ent of entries) {
        if (SKIP_PARTS.has(ent.name)) continue;
        const full = path.join(dir, ent.name);
        if (ent.isDirectory()) yield* walk(full);
        else if (ent.isFile() && ent.name.endsWith('.php')) yield full;
    }
}

const parser = new (PHPParser)({
    parser: { suppressErrors: false, phpVersion: '8.4', extractDoc: false },
    ast: { withPositions: true },
});

const PH = /^:[a-z_0-9]+$/i;
const SQL_HINT = /\b(INSERT\s+INTO|SELECT\s|UPDATE\s|DELETE\s+FROM)\b/i;
const BAD_SQL = [/\,\s*\)/, /\,\,/];

function* visit(node) {
    if (!node || typeof node !== 'object') return;
    for (const [key, value] of Object.entries(node)) {
        if (key === 'loc' || key === 'leadingComments' || key === 'trailingComments') continue;
        if (Array.isArray(value)) {
            for (const v of value) yield* visit(v);
        } else if (value && typeof value === 'object' && typeof value.kind === 'string') {
            yield value;
            yield* visit(value);
        }
    }
}

const roots = process.argv.length > 2 ? process.argv.slice(2) : DEFAULT_DIRS;
const files = [];
for (const r of roots) {
    const abs = path.isAbsolute(r) ? r : path.join(ROOT, r);
    for (const f of walk(abs)) {
        if (!SKIP_TESTS.test(f.split(path.sep).join('/'))) files.push(f);
    }
}
files.sort();

let bad = 0;
const report = [];
for (const file of files) {
    const rel = path.relative(ROOT, file);
    const code = fs.readFileSync(file, 'utf8');
    if (code.trim() === '') continue;

    let ast;
    try {
        ast = parser.parseCode(code, rel);
    } catch (e) {
        bad++;
        const line = e.line || (e.pos && e.pos.line) || '?';
        report.push(`${rel}:${line}  [синтаксис] ${String(e.message).split('\n')[0]}`);
        continue;
    }

    for (const node of visit(ast)) {
        if (node.kind !== 'string') continue;
        const value = typeof node.value === 'string' ? node.value : '';
        if (value === '') continue;
        const line = node.loc && node.loc.start ? node.loc.start.line : '?';
        // (2) плейсхолдер-литерал как операнд конкатенации
        if (PH.test(value) && node.parent && node.parent.kind === 'bin' && node.parent.type === '.') {
            bad++;
            report.push(`${rel}:${line}  [плейсхолдер-в-кавычках] литерал "${value}" — операнд конкатенации; плейсхолдер должен лежать ВНУТРИ SQL-строки`);
        }
        // (3) ошибки SQL внутри литерала
        if (SQL_HINT.test(value)) {
            for (const pat of BAD_SQL) {
                const m = value.match(pat);
                if (m) {
                    bad++;
                    const at = value.indexOf(m[0]);
                    report.push(`${rel}:${line}  [sql-литерал] ${JSON.stringify(m[0])} на позиции ${at}: …${JSON.stringify(value.slice(Math.max(0, at - 40), at + 20))}…`);
                }
            }
        }
    }
}

for (const e of report) console.log('[FAIL] ' + e);
console.log(`php_lint: проверено файлов: ${files.length}, находок: ${bad}`);
process.exit(bad > 0 ? 1 : 0);
