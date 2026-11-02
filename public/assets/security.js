(() => {const el=document.getElementById('totp-qr');if(el&&typeof QRCode!=='undefined')new QRCode(el,{text:el.dataset.uri,width:180,height:180,correctLevel:QRCode.CorrectLevel.M});})();
