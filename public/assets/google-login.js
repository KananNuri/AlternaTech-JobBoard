"use strict";
const googleAccount=document.querySelector('#google-account');
async function refreshGoogleAccount() {
  try {
    const response=await fetch('/api/auth/google/session',{headers:{Accept:'application/json'}});
    if(!response.ok) throw new Error('Account unavailable');
    const data=await response.json();googleAccount.replaceChildren();
    if(data.user) {
      // Both login methods now share the primary account panel and logout.
      googleAccount.hidden=true;
    } else {
      googleAccount.hidden=false;
      const a=document.createElement('a');a.textContent='Continue with Google';
      if(data.enabled){a.href='/api/auth/google/start';googleAccount.append(a);}else{googleAccount.textContent='Google sign-in is not configured yet.';}
    }
    const result=new URLSearchParams(location.search).get('google_login');
    if(result==='cancelled'){const message=document.createElement('span');message.textContent='Google sign-in was cancelled.';googleAccount.append(message);}
    if(result){const url=new URL(location.href);url.searchParams.delete('google_login');history.replaceState(null,'',url);}
  }catch{googleAccount.textContent='Google sign-in is temporarily unavailable.';}
}
refreshGoogleAccount();
