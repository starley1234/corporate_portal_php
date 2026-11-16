// Глубокое сравнение JSON-подобных значений; возвращает список отличий с путями.
export function deepDiff(a, b, p = '') {
  if (a === b) return [];
  const ta = Array.isArray(a) ? 'array' : a === null ? 'null' : typeof a;
  const tb = Array.isArray(b) ? 'array' : b === null ? 'null' : typeof b;
  if (ta !== tb) return [`${p}: тип ${ta} != ${tb}`];
  if (ta === 'array') {
    const out = [];
    if (a.length !== b.length) out.push(`${p}: длина ${a.length} != ${b.length}`);
    for (let i = 0; i < Math.min(a.length, b.length); i++) out.push(...deepDiff(a[i], b[i], `${p}[${i}]`));
    return out;
  }
  if (ta === 'object') {
    const out = [];
    for (const k of new Set([...Object.keys(a), ...Object.keys(b)])) {
      if (!(k in a)) out.push(`${p}.${k}: нет в первом`);
      else if (!(k in b)) out.push(`${p}.${k}: нет во втором`);
      else out.push(...deepDiff(a[k], b[k], `${p}.${k}`));
    }
    return out;
  }
  return [`${p}: ${JSON.stringify(a)?.slice(0, 120)} != ${JSON.stringify(b)?.slice(0, 120)}`];
}
