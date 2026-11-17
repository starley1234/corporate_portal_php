/* =================== КАРКАС СТРАНИЦЫ =================== */
const main=document.getElementById('main'),navEl=document.getElementById('nav'),ptitle=document.getElementById('ptitle'),built={};
const grpEl=document.getElementById('navgroups'),noEl=document.getElementById('navno');
const qEl=document.getElementById('navq'),pagerEl=document.getElementById('pager');
const expandAllEl=document.getElementById('navexpandall'),collapseAllEl=document.getElementById('navcollapseall');
const colsEl=document.getElementById('cols'),navTog=document.getElementById('navtog');
let cur=0,lvl='o',q='';
try{const m=location.hash.match(/t=(\d+)/),l=location.hash.match(/l=([od])/);if(m&&TABS[+m[1]])cur=+m[1];if(l)lvl=l[1]}catch(e){}

// Разделы (вкладки) задаются в meta.sections; SECTIONS = [{label, hidden, from, to, count}]
const SECT=(typeof SECTIONS!=='undefined'&&SECTIONS&&SECTIONS.length)?SECTIONS
  :[{label:'',hidden:false,from:0,to:TOBE_COUNT,count:TOBE_COUNT},
    {label:'AS-IS',hidden:true,from:TOBE_COUNT,to:TABS.length,count:TABS.length-TOBE_COUNT}];
const plural=(n,w)=>{const a=Math.abs(n)%100,b=a%10;return w[(a>10&&a<20)||b>4||b===0?2:b===1?0:1]};
const shown=SECT.map(s=>!s.hidden);          // скрытый раздел раскрыт переключателем
const folded=SECT.map(()=>false);            // раздел свёрнут кликом по заголовку
const secOf=i=>{const k=SECT.findIndex(s=>i>=s.from&&i<s.to);return k<0?0:k};
function syncFoldControls(){
  const allFolded=folded.length>0&&folded.every(Boolean),anyFolded=folded.some(Boolean);
  if(expandAllEl)expandAllEl.disabled=!anyFolded;
  if(collapseAllEl)collapseAllEl.disabled=allFolded;
}
const query=()=>q.trim().toLowerCase();
const hit=i=>{const s=query();return !s||TABS[i].t.toLowerCase().indexOf(s)>=0};
const label=i=>TABS[i].s||TABS[i].t;

// Левое меню: раздел — заголовок группы (свёртывается), внутри список вкладок.
const groups=SECT.map((s,i)=>{
  const g=h('div','ng'),head=h('button','nh'),list=h('div','nn');
  const car=h('span','ncar');car.textContent='▾';
  const nm=h('span','nnm');nm.textContent=s.label||'Раздел';
  const mk=h('span','nx'),cnt=h('span','nc');cnt.textContent=String(s.count);
  head.appendChild(car);head.appendChild(nm);head.appendChild(mk);head.appendChild(cnt);
  head.onclick=()=>{folded[i]=!folded[i];applyView()};
  g.appendChild(head);g.appendChild(list);(grpEl||navEl).appendChild(g);
  return {g,head,list,car,mk,cnt};
});
TABS.forEach((T,i)=>{
  const b=h('button','ni');b.textContent=label(i);b.onclick=()=>{cur=i;show()};
  groups[secOf(i)].list.appendChild(b);T.btn=b;
});
SECT.forEach((s,i)=>{
  if(!s.hidden)return;
  const name=()=>(shown[i]?'Скрыть ':'Показать ')+s.label+' ('+s.count+' '+plural(s.count,['вкладка','вкладки','вкладок'])+')';
  const btn=h('button','nt',name());
  btn.onclick=()=>{shown[i]=!shown[i];if(!shown[i]&&secOf(cur)===i){cur=0;show()}applyView();btn.textContent=name()};
  (grpEl||navEl).appendChild(btn);
});
if(expandAllEl)expandAllEl.onclick=()=>{folded.fill(false);applyView()};
if(collapseAllEl)collapseAllEl.onclick=()=>{folded.fill(true);applyView()};

// Поиск по вкладкам: ищет по всем разделам, по Enter — переход к первому совпадению.
if(qEl){
  qEl.addEventListener('input',()=>{q=qEl.value||'';applyView()});
  qEl.addEventListener('keydown',e=>{
    if(e.key==='Escape'){qEl.value='';q='';applyView();return}
    if(e.key!=='Enter')return;
    const i=TABS.findIndex((_,j)=>hit(j));
    if(i>=0){cur=i;show()}
  });
}
document.addEventListener('keydown',e=>{
  if(e.key!=='/'||!qEl)return;
  const t=e.target&&e.target.tagName;
  if(t==='INPUT'||t==='TEXTAREA')return;
  e.preventDefault();qEl.focus();if(qEl.select)qEl.select();
});

// Стрелки вверх/вниз переключают вкладку, не прокручивая страницу.
navEl.addEventListener('keydown',e=>{
  if(e.key!=='ArrowDown'&&e.key!=='ArrowUp')return;
  const vis=TABS.map((_,i)=>i).filter(i=>shown[secOf(i)]&&hit(i));if(!vis.length)return;
  const k=vis.indexOf(cur),step=e.key==='ArrowDown'?1:-1;
  const j=k<0?vis[0]:vis[(k+step+vis.length)%vis.length];
  e.preventDefault();cur=j;show();TABS[j].btn.focus();
});

function applyView(){
  const s=query();
  let hits=0;
  TABS.forEach((T,i)=>{const ok=hit(i);if(ok)hits++;T.btn.classList.toggle('hide',!ok)});
  SECT.forEach((sec,i)=>{
    const G=groups[i],has=[...G.list.children].some(b=>!b.classList.contains('hide'));
    G.g.classList.toggle('hide',s?!has:!shown[i]);       // поиск временно показывает и скрытые разделы
    G.g.classList.toggle('fold',!!folded[i]);
    G.car.textContent=folded[i]?'▸':'▾';
    G.mk.textContent=(folded[i]&&secOf(cur)===i)?label(cur):'';   // какой пункт активен в свёрнутом разделе
  });
  if(noEl)noEl.classList.toggle('hide',!(s&&!hits));
  syncFoldControls();
}

// Кнопка-иконка на границе меню убирает колонку — широким схемам остаётся вся ширина.
if(colsEl&&navTog)navTog.onclick=()=>{
  const off=colsEl.classList.toggle('navoff');
  navTog.textContent=off?'›':'‹';
  const t=off?'Показать меню':'Свернуть меню';
  navTog.title=t;navTog.setAttribute('aria-label',t);navTog.setAttribute('aria-pressed',String(!off));
};

document.querySelectorAll('#lvl button').forEach(b=>b.onclick=()=>{lvl=b.dataset.l;show()});
function build(i,l){const V=TABS[i][l],box=h('section','view');main.appendChild(box);
  if(V.lead)box.appendChild(h('div','lead',V.lead));
  V.blocks.forEach(B=>{const d=h('div','blk');box.appendChild(d);
    if(B.t==='scheme')scheme(B.S,d);else if(B.t==='seq')seq(B.Q,d);else if(B.t==='h'){d.className='';d.appendChild(h('h3',null,B.text))}else if(B.t==='capacity')capacityCalc(d);else d.innerHTML=B.html});
  return box}

// Листание: предыдущая и следующая вкладка внизу страницы.
function renderPager(){
  if(!pagerEl)return;
  while(pagerEl.children.length)pagerEl.removeChild(pagerEl.children[0]);
  const vis=TABS.map((_,i)=>i).filter(i=>shown[secOf(i)]&&hit(i));
  const k=vis.indexOf(cur);if(k<0)return;
  const mk=(i,dir)=>{const b=h('button',dir>0?'pg pgn':'pg pgp');
    b.textContent=(dir<0?'← ':'')+(secOf(i)===secOf(cur)?label(i):TABS[i].t)+(dir>0?' →':'');
    b.onclick=()=>{cur=i;show();if(typeof window!=='undefined'&&window.scrollTo)window.scrollTo(0,0)};
    return b};
  if(k>0)pagerEl.appendChild(mk(vis[k-1],-1));
  if(k<vis.length-1)pagerEl.appendChild(mk(vis[k+1],1));
}

function show(){
  TABS.forEach((T,i)=>T.btn.classList.toggle('on',i===cur));
  document.querySelectorAll('#lvl button').forEach(b=>b.classList.toggle('on',b.dataset.l===lvl));
  if(ptitle){
    while(ptitle.children.length)ptitle.removeChild(ptitle.children[0]);
    const S=SECT[secOf(cur)],hasLabel=!!(S&&S.label);
    if(hasLabel){const a=h('span','psec');a.textContent=S.label;ptitle.appendChild(a);ptitle.appendChild(h('span',null,' · '))}
    ptitle.appendChild(h('span',null,hasLabel?label(cur):TABS[cur].t));
  }
  Object.values(built).forEach(v=>v.classList.add('hide'));
  const key=cur+lvl;if(built[key])built[key].classList.remove('hide');else built[key]=build(cur,lvl);
  renderPager();applyView();
  try{history.replaceState(null,'','#t='+cur+'&l='+lvl)}catch(e){}
}
show();
