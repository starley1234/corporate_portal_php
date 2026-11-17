// Замочки разделов: раздел с locked: true фиксируется хешем содержимого вкладок.
// Проверяется в check/build; обновляется осознанно командой `arch lock --force`.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import YAML from 'yaml';

export const LOCK_FILE = 'lock.yaml';

// Хеш нормализованного содержимого (LF, без BOM) — не зависит от CRLF на разных ОС.
export function hashFile(file) {
  const raw = fs.readFileSync(file, 'utf8').replace(/^\uFEFF/, '').replace(/\r\n/g, '\n');
  return 'sha256:' + crypto.createHash('sha256').update(raw, 'utf8').digest('hex');
}

// Все YAML-файлы залоченных разделов (по порядку разделов и имён файлов).
export function lockedFiles(model, dir) {
  const out = [];
  for (const s of model.sections) {
    if (!s.locked) continue;
    const sd = path.join(dir, s.dir);
    if (!fs.existsSync(sd)) continue;
    for (const n of fs.readdirSync(sd).filter(n => n.endsWith('.yaml')).sort())
      out.push({ id: `${s.dir}/${n.replace(/\.yaml$/, '')}`, rel: `${s.dir}/${n}`, file: path.join(sd, n) });
  }
  return out;
}

export function readLock(dir) {
  const p = path.join(dir, LOCK_FILE);
  if (!fs.existsSync(p)) return null;
  return YAML.parse(fs.readFileSync(p, 'utf8')) || {};
}

export function writeLock(dir, entries) {
  const keys = Object.keys(entries).sort();
  const lines = [
    '# Замочки разделов: sha256 содержимого вкладок (нормализованный перевод строки LF).',
    '# Разделы с locked: true (arch/meta.yaml) запрещено менять; проверяется командой check.',
    '# Обновить осознанно: arch lock --force. Состояние: arch lock --list.',
    'tabs:',
    ...keys.map(k => `  ${JSON.stringify(k)}: ${JSON.stringify(entries[k])}`),
    '',
  ];
  fs.writeFileSync(path.join(dir, LOCK_FILE), lines.join('\n'), 'utf8');
}

// Проверка замочков: расхождение — ошибка модели.
export function verifyLock(model, dir, P) {
  const locked = model.sections.filter(s => s.locked).map(s => s.dir);
  const files = lockedFiles(model, dir);
  if (!files.length) return;
  const lock = readLock(dir);
  if (!lock || !lock.tabs) {
    P.err(LOCK_FILE, `разделы ${locked.join(', ')} залочены, но замочки не установлены — выполните: arch lock`);
    return;
  }
  for (const f of files) {
    const want = lock.tabs[f.id];
    if (!want) { P.err(LOCK_FILE, `нет замочка для ${f.id} — обновите: arch lock`); continue; }
    if (hashFile(f.file) !== want)
      P.err(f.rel, `залоченная вкладка изменена, а раздел ${f.id.split('/')[0]} помечен locked — правки запрещены. Откатите изменения или снимите замочек осознанно: arch lock --force`);
  }
  const ids = new Set(files.map(f => f.id));
  for (const k of Object.keys(lock.tabs)) if (!ids.has(k)) P.warn(LOCK_FILE, `замочек для отсутствующей вкладки ${k} — обновите: arch lock`);
}
