// Выгрузка раздела в один HTML-файл: для смежников, без Node и интернета.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { tempProject, MINIMAL_META } from './helpers.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const ARCH = path.join(ROOT, 'scripts', 'arch.mjs');

const META = { ...MINIMAL_META, sections: [
  { dir: 'tobe', label: 'TO-BE', role: 'primary' },
  { dir: 'solutions', label: 'Решения', role: 'solution' },
  { dir: 'asis', label: 'AS-IS', role: 'asis', hidden: true },
] };
const tab = (title, id) => ({
  title,
  overview: {
    lead: `вкладка ${title}`,
    blocks: [{ scheme: { size: [800, 300], nodes: [
      { id: `${id}-a`, at: [100, 100, 160], label: 'A', kind: 'ext' },
      { id: `${id}-b`, at: [500, 100, 160], label: 'B', kind: 'core' },
    ], edges: [`${id}-a -> ${id}-b`] } }],
  },
  detail: { lead: 'подробно', blocks: [{ note: `текст ${title}` }] },
});
const FILES = {
  'meta.yaml': META,
  'tobe/01-platform.yaml': tab('Платформа', 'p'),
  'tobe/02-flow.yaml': tab('Поток', 'f'),
  'solutions/01-rag.yaml': tab('RAG', 'r'),
  'asis/01-legacy.yaml': tab('Как было', 'l'),
};

const run = (args, cwd) => spawnSync(process.execPath, [ARCH, ...args], { cwd, encoding: 'utf8' });

test('одна вкладка → один самодостаточный файл со своим заголовком', () => {
  const p = tempProject(FILES);
  try {
    const res = run(['--dir', p.dir, 'export', 'tobe/01-platform'], p.dir);
    assert.equal(res.status, 0, res.stderr);
    const out = path.join(p.dir, 'exports', 'platforma.html');
    assert.ok(fs.existsSync(out), `нет файла ${out}: ${res.stdout}`);
    const html = fs.readFileSync(out, 'utf8');
    assert.match(html, /<title>[^<]*Платформа<\/title>/);
    assert.match(html, /Выдержка из модели архитектуры/);
    assert.match(html, /const TABS=\[/);
    // чужих вкладок в данных страницы быть не должно
    assert.doesNotMatch(html, /вкладка Поток/);
    assert.doesNotMatch(html, /"s":"Текст/);
    // ни одного обращения в сеть: файл открывается без интернета
    assert.doesNotMatch(html, /(?:src|href)="https?:/);
    assert.doesNotMatch(html, /<link[^>]+href="\/\//);
  } finally { p.cleanup(); }
});

test('раздел целиком → все его вкладки, --out задаёт путь', () => {
  const p = tempProject(FILES);
  try {
    const out = path.join(p.dir, 'share', 'tobe.html');
    const res = run(['--dir', p.dir, 'export', 'tobe', '--out', out], p.dir);
    assert.equal(res.status, 0, res.stderr);
    const html = fs.readFileSync(out, 'utf8');
    assert.match(html, /вкладка Платформа/);
    assert.match(html, /вкладка Поток/);
    assert.doesNotMatch(html, /вкладка RAG/);
    assert.match(res.stdout, /вкладок: 2/);
  } finally { p.cleanup(); }
});

test('поиск по названию, кириллица в имени файла переводится в латиницу', () => {
  const p = tempProject(FILES);
  try {
    const res = run(['--dir', p.dir, 'export', 'Решения'], p.dir);
    assert.equal(res.status, 0, res.stderr);
    assert.ok(fs.existsSync(path.join(p.dir, 'exports', 'resheniya.html')), res.stdout);
  } finally { p.cleanup(); }
});

test('неизвестное имя — ошибка, файл не создаётся', () => {
  const p = tempProject(FILES);
  try {
    const res = run(['--dir', p.dir, 'export', 'нет-такого'], p.dir);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /ничего не найдено/);
    assert.ok(!fs.existsSync(path.join(p.dir, 'exports')));
  } finally { p.cleanup(); }
});

test('с errors в модели выгрузка отменяется: смежникам не уходит сломанное', () => {
  const p = tempProject({ ...FILES, 'inventory.yaml': { title: 'Инвентарь', items: [
    { id: 'x', entity: 'нет-такой', name: 'X', owner: 'ИТ' },
  ] } });
  try {
    const res = run(['--dir', p.dir, 'export', 'tobe'], p.dir);
    assert.equal(res.status, 1);
    assert.match(res.stderr, /выгрузка отменена/);
    assert.ok(!fs.existsSync(path.join(p.dir, 'exports')));
  } finally { p.cleanup(); }
});

test('выгрузка реального проекта: только выбранные вкладки, файл открывается', () => {
  const out = path.join(ROOT, 'exports', 'test-guardrails.html');
  const res = run(['export', 'tobe/04-guardrails', '--out', out], ROOT);
  try {
    assert.equal(res.status, 0, res.stderr);
    const html = fs.readFileSync(out, 'utf8');
    assert.match(html, /const TOBE_COUNT=1/);
    assert.match(html, /Guardrails/);
    assert.ok(html.length > 20000, 'страница подозрительно маленькая');
    assert.doesNotMatch(html, /(?:src|href)="https?:/);
  } finally { fs.rmSync(out, { force: true }); }
});
