(function () {
  'use strict';
  var form = document.querySelector('[data-prx397-submit]');
  if (form) {
    var fileInput = form.querySelector('input[type="file"]');
    var info = document.getElementById('prx397-file-info');
    var maxBytes = parseInt(fileInput.getAttribute('data-max-bytes') || '0', 10);
    var maxLabel = fileInput.getAttribute('data-max-label') || '';
    var idle = form.querySelector('[data-prx-idle]');
    var busy = form.querySelector('[data-prx-busy]');
    var clock = form.querySelector('[data-prx-clock]');
    var timer = null, sec = 0;
    function fmt(s){ return Math.floor(s/60)+':' + String(s%60).padStart(2,'0'); }
    function updateInfo() {
      var f = fileInput.files && fileInput.files[0];
      if (!f) { info.textContent = 'Файл пока не выбран · до ' + maxLabel; return; }
      var mb = (f.size / (1024*1024)).toFixed(2);
      info.textContent = f.name + ' · ' + mb + ' МиБ';
      if (maxBytes && f.size > maxBytes) info.textContent += ' — превышает лимит ' + maxLabel;
    }
    fileInput.addEventListener('change', updateInfo);
    form.addEventListener('submit', function (e) {
      var f = fileInput.files && fileInput.files[0];
      if (maxBytes && f && f.size > maxBytes) { e.preventDefault(); info.textContent = f.name + ' — превышает лимит ' + maxLabel; return; }
      if (idle) idle.classList.add('hidden');
      if (busy) busy.classList.remove('hidden');
      sec = 0; if (clock) clock.textContent = '0:00';
      timer = setInterval(function(){ sec++; if(clock) clock.textContent = fmt(sec); }, 1000);
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
    });
  }

  var pending = document.querySelector('[data-prx397-pending]');
  if (pending) {
    var id = pending.getAttribute('data-prx397-id');
    var tries = 0;
    function poll() {
      tries++;
      if (tries > 600) return; // ~20 min max
      fetch('/prx397_invoice/' + encodeURIComponent(id) + '?op=status', {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){
          if (!data) { setTimeout(poll, 3000); return; }
          var st = data.status;
          if (st === 'queued' || st === 'running') { setTimeout(poll, 2000); return; }
          window.location.reload();
        })
        .catch(function(){ setTimeout(poll, 3000); });
    }
    setTimeout(poll, 2000);
  }
})();
