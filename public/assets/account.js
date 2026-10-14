"use strict";
(() => {
  let session = null;
  const notice = document.querySelector('#account-notice');
  async function send(path, method, data) {
    const response = await fetch(path, {method, headers:{'Content-Type':'application/json', ...(session?.csrf_token?{'X-CSRF-Token':session.csrf_token}:{})},body:JSON.stringify(data)});
    const result = await response.json(); if(!response.ok) throw result.error ? new Error(result.error) : I18N.translated('account.saveError'); return result;
  }
  const signin = document.querySelector('#signin'), form=document.querySelector('#account-form');
  if(signin) signin.addEventListener('submit',async event=>{
    event.preventDefault(); const button=signin.querySelector('button'); button.disabled=true; I18N.text(notice,'login.signingIn');
    try { const result=await send('/api/auth/login','POST',Object.fromEntries(new FormData(signin))); location.assign(result.redirect); }
    catch(error){I18N.error(notice,error);button.disabled=false;}
  });
  if(form) {
    const ready=fetch('/api/auth/session').then(async r=>{if(!r.ok)throw I18N.translated('account.checkError');session=await r.json(); if(session.user){
      for(const key of ['firstname','lastname','email','phone']) form.elements[key].value=session.user[key] || '';
      form.elements.password.required=false; document.querySelector('#current-password-label').hidden=false;
      I18N.text(document.querySelector('#account-submit'),'account.save');I18N.text(document.querySelector('#account-intro'),'account.introEdit');
    }}).catch(error=>{I18N.error(notice,error);throw error;});
    // Attach a rejection handler immediately; a submit still receives the same failure.
    ready.catch(()=>{});
    form.addEventListener('submit',async event=>{
      event.preventDefault(); const button=form.querySelector('button');button.disabled=true;I18N.text(notice,'account.saving');
      try {
        await ready; const data=Object.fromEntries(new FormData(form));
        if(session.user){if(!data.password)delete data.password;if(data.email===session.user.email)delete data.email;if(!data.current_password)delete data.current_password;}
        else delete data.current_password;
        const result=await send(session.user?'/api/account':'/api/auth/register',session.user?'PATCH':'POST',data);
        if(!session.user){location.assign(result.redirect);return;}
        session.user=result.user;form.elements.password.value='';form.elements.current_password.value='';I18N.text(notice,'account.saved');
      } catch(error){I18N.error(notice,error);} finally{button.disabled=false;}
    });
  }
})();
