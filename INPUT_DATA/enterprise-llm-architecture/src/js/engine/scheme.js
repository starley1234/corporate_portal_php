/* ---------- движок схем ---------- */
const DIR={r:[1,0],l:[-1,0],t:[0,-1],b:[0,1]};
function anc(n,s){return s==='r'?[n.x+n.w/2,n.y]:s==='l'?[n.x-n.w/2,n.y]:s==='t'?[n.x,n.y-n.h/2]:[n.x,n.y+n.h/2]}
let UID=0;
function scheme(S,box){
  const p='s'+(UID++);
  const bar=h('div','bar');box.appendChild(bar);
  const sc=h('div','scroll');box.appendChild(sc);
  const svg=el('svg',{viewBox:'0 0 '+S.vb.join(' '),class:'dg'},sc);
  const info=h('div','info',S.hint||'');box.appendChild(info);
  const lg=h('div','legend');box.appendChild(lg);
  const defs=el('defs',{},svg),MK={};
  const marker=col=>{if(MK[col])return MK[col];const id=p+'m'+Object.keys(MK).length;
    const m=el('marker',{id,viewBox:'0 0 10 10',refX:9,refY:5,markerWidth:7,markerHeight:7,orient:'auto-start-reverse'},defs);
    el('path',{d:'M0,0 L10,5 L0,10 z',style:'fill:'+col},m);return MK[col]=id};
  if(S.host){const o=S.host;el('rect',{x:o.x,y:o.y,width:o.w,height:o.h,rx:14,class:'host'},svg);el('text',{x:o.x+12,y:o.y+17,class:'glb'},svg).textContent=o.label}
  (S.groups||[]).forEach(g=>{el('rect',{x:g.x,y:g.y,width:g.w,height:g.h,rx:10,class:'grp'},svg);el('text',{x:g.x+9,y:g.y+16,class:'glb'},svg).textContent=g.label});
  (S.notes||[]).forEach(t=>tl(svg,t.x,t.y,t.t,'note',t.a||'start',13));
  const N={};S.nodes.forEach(n=>{n.k=n.k||'ext';n.w=n.w||110;const sl=n.sub?String(n.sub).split('\n').length:0;n.sl=sl;n.h=n.h||(sl?34+12*sl:38);N[n.id]=n});
  const LE=el('g',{},svg),LN=el('g',{},svg),LL=el('g',{},svg);
  S.edges.forEach(e=>{
    e.id=e.f+'>'+e.t;const A=N[e.f],B=N[e.t];if(!A||!B){console.warn('edge',e.id);return}let d,mid;
    if(e.pts){d='M'+e.pts.map(q=>q.join(',')).join(' L');const q=e.pts;mid=q.length%2?q[(q.length-1)/2]:[(q[q.length/2-1][0]+q[q.length/2][0])/2,(q[q.length/2-1][1]+q[q.length/2][1])/2]}
    else{let fs=e.fs,ts=e.ts;const dx=B.x-A.x,dy=B.y-A.y;
      if(!fs){if(Math.abs(dx)>=Math.abs(dy)){fs=dx>0?'r':'l'}else{fs=dy>0?'b':'t'}}
      if(!ts){if(fs==='r'||fs==='l'){ts=Math.abs(dx)>=Math.abs(dy)?(dx>0?'l':'r'):(dy>0?'t':'b')}else{ts=Math.abs(dy)>=Math.abs(dx)?(dy>0?'t':'b'):(dx>0?'l':'r')}}
      const P0=anc(A,fs),P3=anc(B,ts),k=Math.max(25,Math.hypot(P3[0]-P0[0],P3[1]-P0[1])*.4);
      const P1=[P0[0]+DIR[fs][0]*k,P0[1]+DIR[fs][1]*k],P2=[P3[0]+DIR[ts][0]*k,P3[1]+DIR[ts][1]*k];
      d=`M${P0} C${P1} ${P2} ${P3}`;mid=[(P0[0]+3*P1[0]+3*P2[0]+P3[0])/8,(P0[1]+3*P1[1]+3*P2[1]+P3[1])/8]}
    e.col=e.k?K[e.k]:'var(--muted)';
    e.g=el('g',{class:'edge'+(e.d?' dash':'')},LE);
    e.p=el('path',{d,class:'l'},e.g);
    if(e.l){const lp=e.lp||mid,g=el('g',{class:'elg'},LL);e.lg=g;const nl=e.l.split('\n').length;
      const t=tl(g,lp[0],lp[1]+3.5-(nl-1)*6,e.l,'elb','middle',12);const bb=t.getBBox();
      g.insertBefore(el('rect',{x:bb.x-5,y:bb.y-2,width:bb.width+10,height:bb.height+4,rx:7,class:'elbg'}),t)}
  });
  S.nodes.forEach(n=>{
    const g=el('g',{class:'node'},LN),c=K[n.k]||K.ext,x=n.x-n.w/2,y=n.y-n.h/2,rx=n.pill?Math.min(n.h/2,20):9;n.g=g;
    if(n.stack)[10,5].forEach(o=>{el('rect',{x:x+o,y:y-o,width:n.w,height:n.h,rx,class:'nbg'},g);
      el('rect',{x:x+o,y:y-o,width:n.w,height:n.h,rx,fill:c,'fill-opacity':.1,stroke:c,'stroke-width':1.2},g)});
    el('rect',{x,y,width:n.w,height:n.h,rx,class:'nbg'},g);
    const r=el('rect',{x,y,width:n.w,height:n.h,rx,fill:c,'fill-opacity':.15,stroke:c,'stroke-width':1.6},g);
    if(n.dash||n.k==='local')r.setAttribute('stroke-dasharray','5 3');
    const top=n.y-(15+n.sl*12)/2;
    el('text',{x:n.x,y:top+11.5,'text-anchor':'middle',class:'lb'},g).textContent=n.label;
    if(n.sl)tl(g,n.x,top+15+9.5,n.sub,'sb','middle',12);
    g.onmouseenter=()=>{st.hover=n.id;apply()};g.onmouseleave=()=>{st.hover=null;apply()};
    g.onclick=()=>{info.innerHTML=n.i||D[n.id]||('<b>'+n.label+'</b>'+(n.sub?' — '+n.sub.replace(/\n/g,' '):''))};
  });
  const st={hover:null,route:null,tog:{}};
  for(const k in (S.toggles||{}))st.tog[k]=S.toggles[k].on!==false;
  const vis=e=>e.g&&!(e.cat&&st.tog[e.cat]===false);
  function apply(){
    let ns=null,es=null,flow=false;
    if(st.hover){es=new Set();ns=new Set([st.hover]);
      S.edges.forEach(e=>{if(vis(e)&&(e.f===st.hover||e.t===st.hover)){es.add(e.id);ns.add(e.f);ns.add(e.t)}})}
    else if(st.route){const r=S.routes[st.route];ns=new Set(r);es=new Set();flow=true;
      for(let i=0;i<r.length-1;i++){const e=S.edges.find(e=>(e.f===r[i]&&e.t===r[i+1])||(e.t===r[i]&&e.f===r[i+1]));if(e)es.add(e.id);else console.warn('route gap',r[i],r[i+1])}}
    S.edges.forEach(e=>{if(!e.g)return;const v=vis(e),on=!!es&&es.has(e.id);
      e.g.style.display=v?'':'none';if(e.lg)e.lg.style.display=v?'':'none';
      e.g.classList.toggle('hi',on);e.g.classList.toggle('flow',on&&flow);
      e.g.classList.toggle('dim',!!es&&!on);if(e.lg)e.lg.classList.toggle('dim',!!es&&!on);
      const col=on?'var(--accent)':e.col;e.p.style.stroke=on?'':(e.k?e.col:'');
      e.p.setAttribute('marker-end','url(#'+marker(col)+')');
      if(e.both)e.p.setAttribute('marker-start','url(#'+marker(col)+')')});
    S.nodes.forEach(n=>n.g.classList.toggle('dim',!!ns&&!ns.has(n.id)));
  }
  if(S.routes){const btns=[];bar.appendChild(h('span','cap','Маршрут:'));
    Object.keys(S.routes).forEach(name=>{const b=h('button',null,name);bar.appendChild(b);btns.push(b);
      b.onclick=()=>{st.route=st.route===name?null:name;btns.forEach(x=>x.classList.toggle('on',x===b&&st.route===name));
        if(st.route&&S.ri&&S.ri[name])info.innerHTML=S.ri[name];apply()}})}
  for(const k in (S.toggles||{})){const l=h('label','tg');l.innerHTML=`<input type="checkbox" ${st.tog[k]?'checked':''}> ${S.toggles[k].l}`;
    l.querySelector('input').onchange=ev=>{st.tog[k]=ev.target.checked;apply()};bar.appendChild(l)}
  dlBtn(bar,svg,()=>{const s0={hover:st.hover,route:st.route};st.hover=st.route=null;apply();return()=>{st.hover=s0.hover;st.route=s0.route;apply()}});
  const used=[...new Set(S.nodes.map(n=>n.k))];
  lg.innerHTML=used.map(k=>`<span><i style="background:${K[k]}"></i>${KN[k]}</span>`).join('')+'<span>── основная связь</span><span>- - - опционально / служебное</span>';
  apply();
}

