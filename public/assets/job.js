(() => {
 const meta=document.getElementById('job-meta'),log=document.getElementById('log'),state=document.getElementById('state'),status=document.getElementById('stream-state'),reconnect=document.getElementById('stream-reconnect');
 if(!meta||!log||!state||!status||!reconnect)return;
 let es=null,offset=Number(meta.dataset.offset||0),done=['succeeded','failed'].includes(meta.dataset.state);
 const pause=message=>{es?.close();status.textContent=message;reconnect.hidden=false;};
 const connect=()=>{
  if(done)return;es?.close();reconnect.hidden=true;status.textContent='ログへ接続しています…';
  es=new EventSource('/jobs/'+meta.dataset.id+'/stream?offset='+offset);
  es.onopen=()=>{status.textContent='接続中・受信済みのログを保持しています';};
  es.addEventListener('chunk',event=>{const nearBottom=log.scrollHeight-log.scrollTop-log.clientHeight<80;log.textContent+=JSON.parse(event.data);offset=Number(event.lastEventId)||offset;if(nearBottom)log.scrollTop=log.scrollHeight;});
  es.addEventListener('reset',()=>{offset=0;log.textContent='';});
  es.addEventListener('done',event=>{done=true;es.close();status.textContent='ログ受信完了';state.textContent=event.data==='succeeded'?'成功':'失敗';state.className='tag '+(event.data==='succeeded'?'t-ok':'t-err');if(meta.dataset.type==='prepare')window.location.reload();});
  es.addEventListener('timeout',()=>pause('表示の接続時間が終了しました。作業の終了を意味しません。'));
  es.onerror=()=>pause('ログの接続が切れました。受信済みの内容は保持しています。');
 };
 reconnect.addEventListener('click',connect);
 if(done)status.textContent='ログ受信完了';else connect();
})();
