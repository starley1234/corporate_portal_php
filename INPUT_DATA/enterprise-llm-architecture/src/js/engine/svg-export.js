/* ---------- экспорт SVG ---------- */
const LIGHT={fg:'#1f2328',muted:'#6b7280',line:'#d0d7de',accent:'#2563eb',bg:'#ffffff',soft:'#f6f8fa',band:'rgba(37,99,235,.045)'};
const SVG_CSS=`text{font-family:system-ui,-apple-system,"Segoe UI",Arial,sans-serif}
.node .lb{font-size:13px;font-weight:600;fill:var(--fg)}.node .sb{font-size:10.5px;fill:var(--muted)}.nbg{fill:var(--bg)}
.edge path.l{fill:none;stroke:var(--muted);stroke-width:1.6}.edge.dash path.l{stroke-dasharray:5 4}
.elb{font-size:10.5px;fill:var(--fg)}.elbg{fill:var(--bg);stroke:var(--line)}
.grp{fill:none;stroke:var(--line);stroke-dasharray:6 4}.host{fill:none;stroke:var(--line);stroke-width:1.6}
.glb{font-size:11.5px;fill:var(--muted);font-weight:600}.note{font-size:11px;fill:var(--muted)}
.life{stroke:var(--line);stroke-dasharray:4 4}.msg path.l{fill:none;stroke:var(--muted);stroke-width:1.5}.msg.ret path.l{stroke-dasharray:5 4}
.badge{fill:var(--accent)}.badget{font-size:10px;fill:#fff;font-weight:700}.band{fill:var(--band);stroke:none}.bandl{font-size:11px;fill:var(--muted);font-weight:600}`;
let DLN=0;
function dlBtn(bar,svg,reset){
  const n=++DLN,b=h('button','dl','⤓ Скачать SVG');bar.appendChild(b);
  b.onclick=()=>{
    const restore=reset();
    const c=svg.cloneNode(true),vb=svg.getAttribute('viewBox').split(' ');
    c.setAttribute('xmlns',NS);c.setAttribute('width',vb[2]);c.setAttribute('height',vb[3]);c.removeAttribute('class');
    c.querySelectorAll('.dim,.hi,.flow').forEach(e=>e.classList.remove('dim','hi','flow'));
    const st=document.createElementNS(NS,'style');st.textContent=SVG_CSS;c.insertBefore(st,c.firstChild);
    const bg=document.createElementNS(NS,'rect');['x','y'].forEach((k,i)=>bg.setAttribute(k,vb[i]));
    bg.setAttribute('width',vb[2]);bg.setAttribute('height',vb[3]);bg.setAttribute('fill','#ffffff');c.insertBefore(bg,st.nextSibling);
    restore();
    const txt='<?xml version="1.0" encoding="UTF-8"?>\n'+new XMLSerializer().serializeToString(c).replace(/var\(--([a-z]+)\)/g,(m,k)=>LIGHT[k]||m);
    const view=b.closest('section'),all=view?[...view.querySelectorAll('button.dl')]:[b],idx=all.indexOf(b)+1;
    const T=TABS[cur],name=(T.t+(lvl==='d'?' подробно':'')).replace(/[^\p{L}\p{N}]+/gu,'-').replace(/^-|-$/g,'');
    const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([txt],{type:'image/svg+xml'}));
    a.download='architecture-'+name+(all.length>1?'-'+idx:'')+'.svg';document.body.appendChild(a);a.click();a.remove();
    setTimeout(()=>URL.revokeObjectURL(a.href),1000)};
}

