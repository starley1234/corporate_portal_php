// Пути: инструмент может лежать отдельно от проекта (например, в node_modules),
// поэтому исходники страницы берутся рядом со скриптом, а arch/ и dist/ — из каталога проекта.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const TOOL_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

// dirArg: каталог проекта (с arch/ внутри) или сразу каталог arch/. По умолчанию — каталог инструмента.
export function resolveProject(dirArg) {
  const raw = dirArg || process.env.ARCH_DIR;
  if (!raw) return { dir: TOOL_ROOT, arch: path.join(TOOL_ROOT, 'arch'), dist: path.join(TOOL_ROOT, 'dist'), src: path.join(TOOL_ROOT, 'src') };
  const p = path.resolve(process.cwd(), raw);
  const asArch = fs.existsSync(path.join(p, 'meta.yaml'));
  const dir = asArch ? path.dirname(p) : p;
  return { dir, arch: path.join(dir, 'arch'), dist: path.join(dir, 'dist'), src: joinSrc(dir) };
}

// Шаблон и движок страницы: сначала в проекте (кастомизация), иначе — из поставки инструмента.
function joinSrc(dir) {
  const local = path.join(dir, 'src', 'index.html');
  return fs.existsSync(local) ? path.join(dir, 'src') : path.join(TOOL_ROOT, 'src');
}

export const relTo = (base, p) => path.relative(base, p) || '.';
