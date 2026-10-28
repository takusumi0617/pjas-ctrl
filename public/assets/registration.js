(() => {
  const source = document.getElementById('source');
  const upload = document.getElementById('f-upload');
  const git = document.getElementById('f-git');
  const read = document.getElementById('read-manifest');
  const load = document.getElementById('manifest-load');
  const settings = document.getElementById('manifest-settings');
  const repo = git.querySelector('[name="github_repo"]');
  function sync() {
    const isGit = source.value === 'git';
    upload.hidden = upload.disabled = isGit;
    git.hidden = git.disabled = !isGit;
    repo.required = isGit;
    load.hidden = load.disabled = !isGit || !read.checked;
    if (settings) settings.hidden = settings.disabled = !isGit || !read.checked;
  }
  source.addEventListener('change', sync);
  read.addEventListener('change', sync);
  sync();
})();
