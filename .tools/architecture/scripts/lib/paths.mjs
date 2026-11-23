// Пути: инструмент может лежать отдельно от проекта (например, в node_modules),
// поэтому исходники страницы берутся рядом со скриптом, а model/ и dist/ — из
// каталога проекта. Модель портала называется `model`; имя `arch` оставлено
// для совместимости с внешними проектами и старыми тестами.
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const TOOL_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');

const MODEL_DIRS = ['model', 'arch'];

function modelPath(dir) {
  // Сначала проект с готовой моделью; затем — просто существующий каталог
  // (нужно для `arch init`, который создаёт пустой arch/ до заполнения).
  for (const name of MODEL_DIRS) {
    const candidate = path.join(dir, name);
    if (fs.existsSync(path.join(candidate, 'meta.yaml'))) return candidate;
  }
  for (const name of MODEL_DIRS) {
    const candidate = path.join(dir, name);
    if (fs.existsSync(candidate)) return candidate;
  }
  return path.join(dir, 'arch');
}

function projectAt(dir) {
  return { dir, arch: modelPath(dir), dist: path.join(dir, 'dist'), src: joinSrc(dir) };
}

// dirArg: каталог проекта (с model/ или arch/ внутри) или сразу каталог модели.
// Без аргумента используется проект портала docs/architecture независимо от cwd.
export function resolveProject(dirArg) {
  const raw = dirArg || process.env.ARCH_DIR;
  if (!raw) {
    return projectAt(path.resolve(TOOL_ROOT, '../../docs/architecture'));
  }
  const p = path.resolve(process.cwd(), raw);
  const asModel = fs.existsSync(path.join(p, 'meta.yaml'));
  const dir = asModel ? path.dirname(p) : p;
  return projectAt(dir);
}

// Шаблон и движок страницы: сначала в проекте (кастомизация), иначе — из поставки инструмента.
function joinSrc(dir) {
  const local = path.join(dir, 'src', 'index.html');
  return fs.existsSync(local) ? path.join(dir, 'src') : path.join(TOOL_ROOT, 'src');
}

export const relTo = (base, p) => path.relative(base, p) || '.';
