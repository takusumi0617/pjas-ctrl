(() => {
  const dialog = document.getElementById('pause-dialog');
  const trigger = document.getElementById('pause-toggle');
  if (!dialog || !trigger) return;
  trigger.addEventListener('click', () => dialog.showModal());
  dialog.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => dialog.close()));
  dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); } });
  const mode = document.getElementById('pause-page-mode');
  function sync() {
    const custom = mode.value === 'custom';
    document.getElementById('pause-custom').hidden = !custom;
    document.getElementById('pause-custom').querySelector('textarea').disabled = !custom;
    document.getElementById('pause-default-note').hidden = custom;
  }
  mode.addEventListener('change', sync);
  sync();
})();
