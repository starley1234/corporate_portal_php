(function () {
  'use strict';
  var form = document.querySelector('[data-prx398-submit]');
  if (form) {
    var fileInput = form.querySelector('input[type="file"]');
    var info = document.getElementById('prx398-file-info');
    var maxBytes = parseInt(fileInput.getAttribute('data-max-bytes') || '0', 10);
    var maxLabel = fileInput.getAttribute('data-max-label') || '';
    var idle = form.querySelector('[data-prx-idle]');
    var busy = form.querySelector('[data-prx-busy]');
    fileInput.addEventListener('change', function(){
      var f = fileInput.files && fileInput.files[0];
      if (!f) { info.textContent = 'Файл пока не выбран'; return; }
      info.textContent = f.name + ' · ' + (f.size/(1024*1024)).toFixed(2) + ' МиБ';
      if (maxBytes && f.size > maxBytes) info.textContent += ' — превышает лимит ' + maxLabel;
    });
    form.addEventListener('submit', function(e){
      var f = fileInput.files && fileInput.files[0];
      if (maxBytes && f && f.size > maxBytes) { e.preventDefault(); return; }
      if (idle) idle.classList.add('hidden');
      if (busy) busy.classList.remove('hidden');
      var btn = form.querySelector('button[type="submit"]'); if (btn) btn.disabled = true;
    });
  }
  var pending = document.querySelector('[data-prx398-pending]');
  if (pending) {
    var id = pending.getAttribute('data-prx398-id');
    var tries = 0;
    function poll() {
      tries++; if (tries > 600) return;
      fetch('/prx398_doc_router/' + encodeURIComponent(id) + '?op=status', {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(data){
          if (!data) { setTimeout(poll, 3000); return; }
          if (data.status === 'queued' || data.status === 'running') { setTimeout(poll, 2000); return; }
          window.location.reload();
        }).catch(function(){ setTimeout(poll, 3000); });
    }
    setTimeout(poll, 2000);
  }
})();
