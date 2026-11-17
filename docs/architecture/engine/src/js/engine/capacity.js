/* Интерактивный расчёт нагрузочного профиля; не подменяет GPU benchmark. */
function capacityCalc(parent){
  const panel=h('div','lead');parent.appendChild(panel);
  panel.innerHTML='<b>Калькулятор нагрузки</b> — изменяй входные параметры. Показатели токенов вычисляются арифметически; производительность GPU требует замера на выбранных моделях.';
  const box=h('div','cards');parent.appendChild(box);
  const specs=[['rpm','Запросов в минуту',20,1,120,1],['inp','Средний вход, токены',1500,250,12000,250],['out','Средний ответ, токены',750,100,4000,50],['risk','Доля подозрительных, %',15,0,100,1],['approved','Подозрительных, допущенных, % от всех',10,0,100,1]];
  const els={};
  for(const [id,name,initial,min,max,step] of specs){
    const cell=h('div','card');cell.innerHTML='<label><b>'+name+'</b> <span class="calc-val" style="float:right">'+initial+'</span></label><input type="range" min="'+min+'" max="'+max+'" step="'+step+'" value="'+initial+'" style="display:block;width:100%;margin:12px 0 2px">';
    els[id]=cell.querySelector('input');box.appendChild(cell);
    els[id].addEventListener('input',render);
  }
  const result=h('div','info');result.style.marginTop='10px';parent.appendChild(result);
  function render(){
    const a=Object.fromEntries(Object.entries(els).map(([k,v])=>[k,Number(v.value)]));
    for(const [id,,,,,] of specs){els[id].parentElement.querySelector('.calc-val').textContent=els[id].value}
    const deep=a.rpm*a.risk/100,heavy=a.rpm*Math.min(a.risk,a.approved)/100;
    result.innerHTML='<b>Расчёт нагрузки:</b> '+
      '<code>'+ (a.rpm/60).toFixed(2) +' req/s</code> · '+
      '<code>'+ (a.rpm*a.inp).toLocaleString('ru-RU') +' входных tok/min</code> · '+
      '<code>'+ (a.rpm*a.out).toLocaleString('ru-RU') +' выходных tok/min</code> · '+
      '<code>'+ (a.rpm*a.out/60).toFixed(0) +' output tok/s агрегировано</code> · '+
      '<code>'+ a.rpm +' fast guard/min на модель</code> · '+
      '<code>'+ deep.toFixed(1) +' deep input/min</code> · '+
      '<code>'+ heavy.toFixed(1) +' heavy output/min</code>'+
      '<p style="margin:8px 0 0">Если все запросы идут на один локальный GPU, требуемая суммарная скорость генерации ориентировочно '+(a.rpm*a.out/60).toFixed(0)+' output tok/s, <b>но это не гарантия достижимости</b> ни для одной конкретной GPU. Если существенная часть запросов идёт в облако, нагрузка на локальный пул уменьшается.</p>';
  }
  render();
}
