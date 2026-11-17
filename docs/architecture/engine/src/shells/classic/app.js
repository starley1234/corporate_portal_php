/* =================== КАРКАС СТРАНИЦЫ =================== */
const main=document.getElementById('main'),tabsEl=document.getElementById('tabs'),built={};
let cur=0,lvl='o';
try{const m=location.hash.match(/t=(\d+)/),l=location.hash.match(/l=([od])/);if(m&&TABS[+m[1]])cur=+m[1];if(l)lvl=l[1]}catch(e){}
const plural=(n,w)=>{const a=Math.abs(n)%100,b=a%10;return w[(a>10&&a<20)||b>4||b===0?2:b===1?0:1]};
TABS.forEach((T,i)=>{const b=h('button',null,T.t);b.onclick=()=>{cur=i;show()};tabsEl.appendChild(b);T.btn=b});
let legacyVisible=cur>=TOBE_COUNT;
const legacyCount=TABS.length-TOBE_COUNT;
const legacyWord=plural(legacyCount,['вкладка','вкладки','вкладок']);
const legacyBtn=h('button','legacy-toggle','Показать AS-IS ('+legacyCount+' '+legacyWord+')');tabsEl.appendChild(legacyBtn);
function setLegacyVisible(){TABS.forEach((t,i)=>{if(i>=TOBE_COUNT)t.btn.classList.toggle('legacy-hidden',!legacyVisible)});legacyBtn.textContent=(legacyVisible?'Скрыть ':'Показать ')+'AS-IS ('+legacyCount+' '+legacyWord+')'}
legacyBtn.onclick=()=>{legacyVisible=!legacyVisible;if(!legacyVisible&&cur>=TOBE_COUNT){cur=0;show()}setLegacyVisible()};
setLegacyVisible();
document.querySelectorAll('#lvl button').forEach(b=>b.onclick=()=>{lvl=b.dataset.l;show()});
function build(i,l){const V=TABS[i][l],box=h('section','view');main.appendChild(box);
  if(V.lead)box.appendChild(h('div','lead',V.lead));
  V.blocks.forEach(B=>{const d=h('div','blk');box.appendChild(d);
    if(B.t==='scheme')scheme(B.S,d);else if(B.t==='seq')seq(B.Q,d);else if(B.t==='h'){d.className='';d.appendChild(h('h3',null,B.text))}else if(B.t==='capacity')capacityCalc(d);else d.innerHTML=B.html});
  return box}
function show(){
  TABS.forEach((T,i)=>T.btn.classList.toggle('on',i===cur));
  document.querySelectorAll('#lvl button').forEach(b=>b.classList.toggle('on',b.dataset.l===lvl));
  Object.values(built).forEach(v=>v.classList.add('hide'));
  const key=cur+lvl;if(built[key])built[key].classList.remove('hide');else built[key]=build(cur,lvl);
  try{history.replaceState(null,'','#t='+cur+'&l='+lvl)}catch(e){}
}
show();
