// Вычисляет данные вкладок из исходного одиночного HTML (reference/…html) в песочнице node:vm.
// instrument=true подменяет table/tCard/cards/D маркерами, чтобы получить структуру, а не готовый HTML.
import fs from 'node:fs';
import vm from 'node:vm';

export function evalBaseline(htmlPath, { instrument = false } = {}) {
  const html = fs.readFileSync(htmlPath, 'utf8').replace(/\r\n/g, '\n');
  const scripts = [...html.matchAll(/<script>\n([\s\S]*?)<\/script>/g)].map(m => m[1]);
  if (scripts.length !== 2) throw new Error('ожидалось 2 <script> в ' + htmlPath);
  let [engine, data] = scripts;
  const cut = data.indexOf('/* =================== КАРКАС СТРАНИЦЫ');
  if (cut < 0) throw new Error('не найден маркер КАРКАС СТРАНИЦЫ');
  data = data.slice(0, cut);

  const once = (src, from, to) => {
    if (src.split(from).length !== 2) throw new Error('ожидалось ровно одно вхождение: ' + from.slice(0, 50));
    return src.replace(from, () => to);
  };
  const ctx = vm.createContext({ console, REG: [] });
  if (instrument) {
    engine = once(engine, 'const D={', 'const D0={');
    engine = once(engine, '\n};\n\n/* ---------- движок схем', "\n};\nconst D=new Proxy(D0,{get:(t,k)=>typeof k==='string'?'\\u0002'+k+'\\u0002':t[k]});\n\n/* ---------- движок схем");
    engine = once(engine, 'function table(head,rows,cls){', "function table(head,rows,cls){const id=REG.length;REG.push({kind:'table',head,rows,cls});return '\\u0001T'+id+'\\u0001'}\nfunction table_orig(head,rows,cls){");
    data = once(data, "const tCard=(heading, text, col='#2563eb')=>", "const tCard=(heading, text, col='#2563eb')=>{const id=REG.length;REG.push({kind:'card',heading,text,col});return '\\u0001C'+id+'\\u0001'};\nconst tCard_orig=(heading, text, col='#2563eb')=>");
    data = once(data, 'const cards=a=>', "const cards=a=>{const id=REG.length;REG.push({kind:'chips',a});return '\\u0001K'+id+'\\u0001'};\nconst cards_orig=a=>");
  }
  vm.runInContext(engine, ctx, { filename: 'engine' });
  vm.runInContext(data, ctx, { filename: 'data' });
  const get = expr => vm.runInContext(expr, ctx);
  return {
    TABS: get('TABS'), K: get('K'), KN: get('KN'),
    D: get(instrument ? 'D0' : 'D'), REG: ctx.REG,
    banner: /v\.lead = '([^']*)' \+ \(v\.lead \|\| ''\)/.exec(data)?.[1],
    page: {
      title: /<title>(.*?)<\/title>/.exec(html)[1],
      heading: /<h1>(.*?)<\/h1>/.exec(html)[1],
      subtitle: /<div class="subt">(.*?)<\/div>/.exec(html)[1],
    },
  };
}
