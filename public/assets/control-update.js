(() => {
 const panel=document.getElementById('control-update'),connection=document.getElementById('control-update-connection');
 if(!panel||!connection)return;
 const poll=async()=>{
  try {
   const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),10000);
   let r;try{r=await fetch('/control-update/status',{cache:'no-store',credentials:'same-origin',signal:controller.signal});}finally{clearTimeout(timeout);}
   if(r.redirected){connection.textContent='再ログインして更新結果を確認してください。';return;}
   if(r.status===401||r.status===403){connection.textContent='再ログインして更新結果を確認してください。';return;}
   if(!r.ok)throw new Error('waiting');
   const result=await r.json(),state=result.state||{};
   if(state.interrupted){location.reload();return;}
   if(['succeeded','failed'].includes(state.phase)){location.reload();return;}
   connection.textContent=state.detail||'更新サービスの起動を待っています。';
  }catch{connection.textContent='管理画面への接続を待っています。更新は専用サービスで継続しています。';}
  setTimeout(poll,5000);
 };
 setTimeout(poll,4000);
})();
