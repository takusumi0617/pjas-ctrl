(() => {
  const button = document.querySelector('.side-toggle');
  const side = document.querySelector('.side');
  if (!button || !side) return;
  const label = button.querySelector('[data-menu-label]');
  const update = (open) => {
    button.setAttribute('aria-expanded', String(open));
    if (label) label.textContent = open ? 'メニューを閉じる' : 'メニューを開く';
    const menuIcon = button.querySelector('[data-menu-icon]');
    const closeIcon = button.querySelector('[data-close-icon]');
    if (menuIcon) menuIcon.hidden = open;
    if (closeIcon) closeIcon.hidden = !open;
    side.classList.toggle('is-open', open);
  };
  button.addEventListener('click', () => update(button.getAttribute('aria-expanded') !== 'true'));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && button.getAttribute('aria-expanded') === 'true') { update(false); button.focus(); }
  });
})();
