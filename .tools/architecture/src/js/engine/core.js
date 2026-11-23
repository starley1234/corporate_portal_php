'use strict';
const NS='http://www.w3.org/2000/svg';
function el(t,a,p){const e=document.createElementNS(NS,t);for(const k in a)e.setAttribute(k,a[k]);if(p)p.appendChild(e);return e}
function h(tag,cls,html){const e=document.createElement(tag);if(cls)e.className=cls;if(html!=null)e.innerHTML=html;return e}
function tl(parent,x,y,str,cls,anchor,lh){const t=el('text',{x,y,'text-anchor':anchor||'middle',class:cls},parent);
  String(str).split('\n').forEach((s,i)=>{el('tspan',{x,dy:i?lh:0},t).textContent=s});return t}

