(() => {
  const mode = document.getElementById('delete-mode');
  const confirm = document.getElementById('writers-stopped');
  const button = document.getElementById('delete-submit');
  if (!mode || !confirm || !button) return;
  const update = () => {
    const deleting = mode.value === 'delete';
    confirm.required = deleting;
    confirm.disabled = !deleting;
    button.textContent = deleting ? '公開を終了し、サイトのデータを削除する' : 'ファイルを残して管理対象から外す';
  };
  mode.addEventListener('change', update);
  update();
})();
