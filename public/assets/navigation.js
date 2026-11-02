(() => {
  const button = document.querySelector('.side-toggle');
  const side = document.querySelector('.side');
  if (!button || !side) return;
  button.addEventListener('click', () => {
    const open = button.getAttribute('aria-expanded') !== 'true';
    button.setAttribute('aria-expanded', String(open));
    button.textContent = open ? 'メニューを閉じる' : 'メニューを開く';
    side.classList.toggle('is-open', open);
  });
})();
