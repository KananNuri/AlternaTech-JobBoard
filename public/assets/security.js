"use strict";
(() => {
  const params=new URLSearchParams(location.hash.slice(1)),token=params.get('token'),action=params.get('action');
  history.replaceState(null,'',location.pathname);
  const form=document.querySelector('#security-form'),button=document.querySelector('#security-submit'),notice=document.querySelector('#security-notice');
  const confirm=token&&(action==='reset'||action==='verify');
  if(confirm){document.querySelector('#security-email').hidden=true;form.elements.email.required=false;button.textContent=action==='reset'?'Save new password':'Verify email';if(action==='reset'){document.querySelector('#security-password').hidden=false;form.elements.password.required=true;}}
  form.onsubmit=async event=>{
    event.preventDefault();button.disabled=true;notice.textContent='Working…';
    try{
      const csrf=await fetch('/api/auth/csrf');if(!csrf.ok)throw Error('Unable to prepare request.');const {csrf_token}=await csrf.json();
      const path=confirm?(action==='reset'?'/api/auth/password/reset':'/api/auth/email/verify'):'/api/auth/password/request';
      const body=confirm?{token,...(action==='reset'?{password:form.elements.password.value}:{})}:{email:form.elements.email.value};
      const response=await fetch(path,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf_token},body:JSON.stringify(body)}),data=await response.json();
      if(!response.ok)throw Error(data.error||'Request failed.');notice.textContent=data.message;form.elements.password.value='';if(!confirm)button.disabled=false;
    }catch(error){notice.textContent=error.message;button.disabled=false;}
  };
})();
