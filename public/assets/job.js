// ジョブログを SSE で追従する。完了済みジョブでは接続しない。
const meta = document.getElementById('job-meta');
const log = document.getElementById('log');
const stateEl = document.getElementById('state');
const streamState = document.getElementById('stream-state');
const id = meta.dataset.id;

if (meta.dataset.state === 'succeeded' || meta.dataset.state === 'failed') {
  streamState.textContent = '完了';
} else {
  log.textContent = '';                       // SSE が先頭から再送するので一旦クリア
  const es = new EventSource('/jobs/' + id + '/stream');
  streamState.textContent = '追従中…';

  es.onmessage = (e) => {
    log.textContent += e.data + '\n';
    log.scrollTop = log.scrollHeight;
  };
  es.addEventListener('done', (e) => {
    es.close();
    streamState.textContent = '完了';
    stateEl.innerHTML = e.data === 'succeeded'
      ? '<span class="tag t-ok">成功</span>'
      : '<span class="tag t-err">失敗</span>';
  });
  es.addEventListener('timeout', () => {
    es.close();
    streamState.textContent = 'タイムアウト (再読み込みしてください)';
  });
  es.onerror = () => {
    streamState.textContent = '切断されました';
    es.close();
  };
}
