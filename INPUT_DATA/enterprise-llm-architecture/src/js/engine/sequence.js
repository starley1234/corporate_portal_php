/* ---------- движок диаграмм последовательности ---------- */
function seq(Q,box){
  const bar=h('div','bar');box.appendChild(bar);
  const sc=h('div','scroll');box.appendChild(sc);
  const A=Q.actors,n=A.length,W=Q.w||1240,px=Q.px||80,cw=(W-2*px)/(n-1),X=i=>px+i*cw,I={};A.forEach((a,i)=>I[a.id]=i);
  let y=96;const rows=Q.msgs.map(m=>{const nl=m.l.split('\n').length,self=m.f===m.t,r={m,nl,self,y0:y};
    if(self){r.ay=y+6;y=Math.max(r.ay+24,y+12*nl)+18}else{r.ay=y+12*nl+11;y=r.ay+14}r.y1=y;return r});
  const H=y+16;
  const svg=el('svg',{viewBox:`0 0 ${W} ${H}`,class:'dg'},sc);
  const info=h('div','info',Q.hint||'');box.appendChild(info);
  const defs=el('defs',{},svg);
  [['a','var(--muted)'],['b','var(--accent)']].forEach(([id,c])=>{const m=el('marker',{id:'q'+UID+id,viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:7,markerHeight:7,orient:'auto'},defs);el('path',{d:'M0,0 L10,5 L0,10 z',style:'fill:'+c},m)});
  const mid='q'+(UID++);
  (Q.ph||[]).forEach(ph=>{const a=rows[ph.a],b=rows[ph.b];el('rect',{x:4,y:a.y0-6,width:W-8,height:b.y1-a.y0+2,rx:8,class:'band'},svg);
    el('text',{x:W-10,y:a.y0+8,'text-anchor':'end',class:'bandl'},svg).textContent=ph.l});
  A.forEach((a,i)=>{const x=X(i),w=Math.min(cw-12,138);el('line',{x1:x,y1:66,x2:x,y2:H-6,class:'life'},svg);
    const g=el('g',{class:'node'},svg),c=K[a.k]||K.ext;el('rect',{x:x-w/2,y:20,width:w,height:46,rx:9,class:'nbg'},g);
    el('rect',{x:x-w/2,y:20,width:w,height:46,rx:9,fill:c,'fill-opacity':.15,stroke:c,'stroke-width':1.6},g);
    el('text',{x,y:a.sub?40:47,'text-anchor':'middle',class:'lb'},g).textContent=a.label;
    if(a.sub)el('text',{x,y:55,'text-anchor':'middle',class:'sb'},g).textContent=a.sub;
    g.onclick=()=>{info.innerHTML=D[a.id]||a.label}});
  rows.forEach((r,i)=>{const m=r.m,g=el('g',{class:'msg'+(m.ret?' ret':'')},svg);r.g=g;const xf=X(I[m.f]),xt=X(I[m.t]);let d;
    if(r.self){d=`M${xf},${r.ay} h36 v22 h-34`;tl(g,xf+46,r.ay+6,m.l,'elb','start',12)}
    else{d=`M${xf},${r.ay} L${xt+(xt>xf?-2:2)},${r.ay}`;const lx=(xf+xt)/2;
      const t=tl(g,lx,r.ay-6-(r.nl-1)*12,m.l,'elb','middle',12);const bb=t.getBBox();
      g.insertBefore(el('rect',{x:bb.x-4,y:bb.y-1,width:bb.width+8,height:bb.height+2,rx:5,class:'elbg',style:'stroke:none'}),t)}
    r.p=el('path',{d,class:'l','marker-end':`url(#${mid}a)`},g);g.insertBefore(r.p,g.firstChild);
    el('circle',{cx:xf,cy:r.ay,r:8.5,class:'badge'},g);el('text',{x:xf,y:r.ay+3.5,'text-anchor':'middle',class:'badget'},g).textContent=i+1;
    el('rect',{x:Math.min(xf,xt)-10,y:r.y0-4,width:Math.abs(xt-xf)+(r.self?260:20),height:r.y1-r.y0,fill:'transparent'},g);
    g.onclick=()=>{cur=i;upd()}});
  let cur=-1,timer=null;
  function upd(){rows.forEach((r,i)=>{r.g.classList.toggle('hi',i===cur);r.g.classList.toggle('dim',cur>=0&&i>cur);
      r.p.setAttribute('marker-end',`url(#${mid}${i===cur?'b':'a'})`)});
    cnt.textContent=cur<0?`шагов: ${rows.length}`:`шаг ${cur+1} из ${rows.length}`;
    if(cur>=0)info.innerHTML=`<b>Шаг ${cur+1}.</b> ${rows[cur].m.d||rows[cur].m.l.replace(/\n/g,' ')}`}
  const stop=()=>{if(timer){clearInterval(timer);timer=null;play.textContent='▶ Проиграть'}};
  const mkb=(t,f)=>{const b=h('button',null,t);b.onclick=f;bar.appendChild(b);return b};
  mkb('⏮ Сначала',()=>{stop();cur=-1;upd();info.innerHTML=Q.hint||''});
  mkb('◀ Назад',()=>{stop();cur=Math.max(0,cur-1);upd()});
  mkb('Вперёд ▶',()=>{stop();cur=Math.min(rows.length-1,cur+1);upd()});
  const play=mkb('▶ Проиграть',()=>{if(timer){stop();return}play.textContent='⏸ Пауза';if(cur>=rows.length-1)cur=-1;
    timer=setInterval(()=>{if(cur>=rows.length-1){stop();return}cur++;upd()},1700);cur++;upd()});
  const cnt=h('span','cap');cnt.style.marginLeft='8px';bar.appendChild(cnt);upd();
  dlBtn(bar,svg,()=>{stop();const c0=cur;cur=-1;upd();return()=>{cur=c0;upd()}});
}

