#!/usr/bin/env node
// Сборка: arch/*.yaml + src/ (шаблон и движок страницы) -> dist/architecture.html и dist/architecture.json
//   node scripts/build.mjs                        собрать (ошибки модели -> код возврата 1)
//   node scripts/build.mjs --strict               предупреждения тоже считать ошибками
//   node scripts/build.mjs --quiet                не печатать предупреждения
//   node scripts/build.mjs --dir <каталог проекта> собрать чужой проект (либо сразу каталог arch/)
//   node scripts/build.mjs --out <каталог>        куда положить результат (по умолчанию <проект>/dist)

import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import { loadModel, compile } from './lib/model.mjs';
import { toAgentModel, stringifyCompact } from './lib/agent.mjs';
import { resolveProject } from './lib/paths.mjs';

const read = f => fs.readFileSync(f, 'utf8').replace(/^\uFEFF/, '').replace(/\r\n/g, '\n');
const fail = msg => { console.error('ERROR: ' + msg); process.exit(1); };

// JSON, безопасный для вставки в <script>
const J = v => JSON.stringify(v).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');

function dataScript(L) {
  return [
    `const K=${J(L.K)};`,
    `const KN=${J(L.KN)};`,
    `const D=${J(L.D)};`,
    `const TOBE_COUNT=${L.TOBE_COUNT};`,
    `const SECTIONS=${J(L.SECTIONS)};`,
    `const TABS=[`,
    L.TABS.map(J).join(',\n'),
    `];`,
  ].join('\n') + '\n';
}

// Оболочки страницы: src/shells/<имя>/{index.html, styles.css, app.js}.
// Движок схем (src/js/engine/) у них общий. Какую оболочку брать — в arch/meta.yaml: ui.shell.
export const DEFAULT_SHELL = 'panel';

export function shellList(src) {
  const base = path.join(src, 'shells');
  if (!fs.existsSync(base)) return [];
  return fs.readdirSync(base).filter(n => fs.existsSync(path.join(base, n, 'index.html'))).sort();
}

function resolveShell(src, want) {
  const name = String(want || DEFAULT_SHELL);
  const tpl = path.join(src, 'shells', name, 'index.html');
  if (fs.existsSync(tpl)) return { name, tpl };
  const custom = path.join(src, 'index.html');       // свой шаблон проекта (старый способ)
  if (fs.existsSync(custom)) return { name: 'свой шаблон проекта', tpl: custom };
  const have = shellList(src);
  fail(`оболочка «${name}» не найдена в ${path.join(src, 'shells')}` + (have.length ? ` (есть: ${have.join(', ')})` : ''));
}

export function renderHtml(project, model, legacy, shell) {
  const INC = /^<!-- @include (\S+) -->$/;
  const tpl = resolveShell(project.src, shell).tpl;
  const body = read(tpl).split('\n').map(line => {
    const m = INC.exec(line);
    if (m) { const f = path.join(project.src, m[1]); if (!fs.existsSync(f)) fail(`include не найден: ${m[1]}`); return read(f).replace(/\n$/, ''); }
    return line === '<!-- @data -->' ? dataScript(legacy).replace(/\n$/, '') : line;
  }).join('\n');
  return body.replace(/\{\{(title|heading|subtitle)\}\}/g, (_, k) => model.meta[k]);
}

function checkSyntax(html) {
  const re = /<script>\n([\s\S]*?)<\/script>/g;
  let m, i = 0;
  while ((m = re.exec(html))) {
    i++;
    try { new vm.Script(m[1], { filename: `<script #${i}>` }); } catch (e) { fail(`синтаксическая ошибка в <script #${i}>: ${e.message}`); }
  }
  if (!i) fail('в результате нет ни одного <script>');
}

export function buildAll(opts = {}) {
  const project = resolveProject(opts.dir);
  const DIST = opts.out ? path.resolve(process.cwd(), opts.out) : project.dist;
  let model;
  try { model = loadModel(project.arch); } catch (e) { fail(e.message); }
  const { legacy, problems } = compile(model);
  if (!opts.quiet && problems.warnings.length) {
    console.warn(`предупреждений: ${problems.warnings.length}`);
    problems.warnings.slice(0, 30).forEach(w => console.warn('  WARN ' + w));
    if (problems.warnings.length > 30) console.warn(`  … и ещё ${problems.warnings.length - 30}`);
  }
  if (problems.errors.length) {
    console.error(`ошибок: ${problems.errors.length}`);
    problems.errors.forEach(e => console.error('  ERR  ' + e));
    process.exit(1);
  }
  if (opts.strict && problems.warnings.length) fail('--strict: есть предупреждения');
  const shell = resolveShell(project.src, model.meta?.ui?.shell);
  const html = renderHtml(project, model, legacy, model.meta?.ui?.shell);
  checkSyntax(html);
  fs.mkdirSync(DIST, { recursive: true });
  fs.writeFileSync(path.join(DIST, 'architecture.html'), html, 'utf8');
  const json = stringifyCompact(toAgentModel(model), 5) + '\n';
  fs.writeFileSync(path.join(DIST, 'architecture.json'), json, 'utf8');
  if (!opts.noLog) {
    const rel = f => path.relative(project.dir, path.join(DIST, f));
    console.log(`OK  ${rel('architecture.html')}  ${(Buffer.byteLength(html) / 1024).toFixed(0)} КБ   ${rel('architecture.json')}  ${(Buffer.byteLength(json) / 1024).toFixed(0)} КБ   вкладок: ${model.tabs.length}   оболочка: ${shell.name}`);
  }
  return { model, legacy, html, json, dir: DIST, project };
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const args = process.argv.slice(2);
  const flag = name => { const i = args.indexOf(name); return i >= 0 && !args[i + 1]?.startsWith('--') ? args[i + 1] : (args.includes(name) ? true : undefined); };
  buildAll({
    quiet: args.includes('--quiet'),
    strict: args.includes('--strict'),
    dir: flag('--dir') || flag('--project'),
    out: flag('--out'),
  });
}
