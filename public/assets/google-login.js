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
      const a=I18N.text(document.createElement('a'),'google.continue');
      if(data.enabled){a.href='/api/auth/google/start';googleAccount.append(a);}else{googleAccount.append(I18N.text(document.createElement('span'),'google.notConfigured'));}
    }
    const result=new URLSearchParams(location.search).get('google_login');
    if(result==='cancelled'){const message=I18N.text(document.createElement('span'),'google.cancelled');googleAccount.append(message);}
    if(result){const url=new URL(location.href);url.searchParams.delete('google_login');history.replaceState(null,'',url);}
  }catch{googleAccount.replaceChildren(I18N.text(document.createElement('span'),'google.unavailable'));}
}
refreshGoogleAccount();
