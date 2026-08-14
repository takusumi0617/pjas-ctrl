// ソース選択に応じて入力欄を切り替える (CSP により外部スクリプト・インライン禁止)
const src = document.getElementById('source');
const up = document.getElementById('f-upload');
const git = document.getElementById('f-git');

function sync() {
  const isGit = src.value === 'git';
  up.hidden = isGit;
  git.hidden = !isGit;
  up.querySelector('input').required = !isGit;
  git.querySelector('input').required = isGit;
}
src.addEventListener('change', sync);
sync();
