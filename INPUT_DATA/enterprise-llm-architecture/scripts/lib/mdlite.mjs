// Мини-разметка для текстов в YAML  <->  HTML, который ждёт страница.
//   **жирный**  -> <b>жирный</b>
//   `код`       -> <code>код</code>   (внутри кода < > & пишутся как есть; если в коде есть ` — обрамляйте как `` код ``)
//   [текст](url)-> <a href="url" target="_blank" rel="noopener noreferrer">текст</a>
// Любой другой HTML (<br>, <span ...>, &lt; ...) проходит как есть.

const ESC = { '<': '&lt;', '>': '&gt;', '&': '&amp;' };
const encCode = s => s.replace(/[<>&]/g, c => ESC[c]);
const decCode = s => s.replace(/&lt;|&gt;|&amp;/g, e => ({ '&lt;': '<', '&gt;': '>', '&amp;': '&' })[e]);

export function fromMd(md) {
  if (typeof md !== 'string') return md;
  const codes = [];
  const keep = (_, c) => { codes.push(`<code>${encCode(c)}</code>`); return `\u0000${codes.length - 1}\u0000`; };
  let s = md.replace(/`` (.+?) ``/g, keep).replace(/`([^`]+)`/g, keep);
  s = s.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => codes[+i]);
}

export function toMd(html) {
  if (typeof html !== 'string') return html;
  let s = html.replace(/<code>(.*?)<\/code>/g, (_, c) => { const t = decCode(c); return t.includes('`') ? '`` ' + t + ' ``' : '`' + t + '`'; });
  s = s.replace(/<b>(.*?)<\/b>/g, '**$1**');
  s = s.replace(/<a href="(https?:\/\/[^"\s]+)" target="_blank" rel="noopener noreferrer">(.*?)<\/a>/g, '[$2]($1)');
  return s;
}

// true, если текст безопасно переводится туда-обратно без потерь
export const roundTrips = html => typeof html !== 'string' || fromMd(toMd(html)) === html;
