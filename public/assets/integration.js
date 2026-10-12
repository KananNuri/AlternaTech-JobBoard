"use strict";
(() => {
  let session={user:null}, selectedAd=null;
  const retainedLoginStatus=document.querySelector('#login-status');
  const make=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
  async function api(path,method='GET',body){
    const headers={Accept:'application/json'};if(body!==undefined)headers['Content-Type']='application/json';
    if(method!=='GET') {const r=await fetch('/api/auth/csrf');if(!r.ok)throw new Error('Unable to prepare request.');headers['X-CSRF-Token']=(await r.json()).csrf_token;}
    const r=await fetch(path,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});
    if(r.status===204)return null;const data=await r.json();if(!r.ok)throw new Error(data.error || 'Request failed.');return data;
  }
  function field(form,name,label,type='text',required=false,value=''){
    const wrap=make('label',label),input=make(type==='textarea'?'textarea':'input');input.name=name;if(type!=='textarea')input.type=type;input.required=required;input.value=value||'';
    input.maxLength=type==='textarea'?20000:(name==='email'?150:name==='phone'?30:100);wrap.append(input);form.append(wrap);return input;
  }
  function applicationForm(ad){
    selectedAd=ad;document.querySelector('#apply-form-section')?.remove();
    const panel=make('section');panel.id='apply-form-section';panel.append(make('h3',`Apply for ${ad.title}`));
    const form=make('form',undefined,'form-grid');
    if(session.user)panel.append(make('p',`Applying as ${session.user.firstname} ${session.user.lastname} · ${session.user.email}`));
    else{field(form,'firstname','First name','text',true);field(form,'lastname','Last name','text',true);field(form,'email','Email','email',true);field(form,'phone','Phone (optional)','tel');}
    field(form,'message','Message to the employer (optional)','textarea');
    const submit=make('button','Send application'),notice=make('p',undefined,'notice');notice.setAttribute('role','status');form.append(submit,notice);
    form.addEventListener('submit',async e=>{e.preventDefault();submit.disabled=true;notice.textContent='Sending…';
      try{await api(`/api/ads/${ad.id}/applications`,'POST',Object.fromEntries(new FormData(form)));notice.textContent='Application saved and available to the recruiter.';await loadApplications();}
      catch(error){notice.textContent=error.message;submit.disabled=false;}
    });panel.append(form);document.querySelector('#details').append(panel);
  }
  document.addEventListener('alternatech:ad-selected',e=>{
    selectedAd=e.detail.advertisement;const button=make('button','Apply');button.addEventListener('click',()=>applicationForm(selectedAd));document.querySelector('#details').append(button);
  });
  document.addEventListener('alternatech:apply',e=>{applicationForm(e.detail.advertisement);document.querySelector('#apply-form-section input,#apply-form-section textarea')?.focus();});
  const filters=make('form',undefined,'filters');filters.setAttribute('aria-label','Find opportunities');
  field(filters,'q','Keywords');field(filters,'location','Location');
  const label=make('label','Category'),select=make('select');select.name='category_id';const all=make('option','All categories');all.value='';select.append(all);label.append(select);filters.append(label);
  const search=make('button','Search');filters.append(search);document.querySelector('#status').before(filters);
  api('/api/categories').then(categories=>{for(const category of categories){const option=make('option',category.name);option.value=category.id;select.append(option);}}).catch(()=>{});
  filters.addEventListener('submit',e=>{e.preventDefault();document.dispatchEvent(new CustomEvent('alternatech:filters',{detail:Object.fromEntries(new FormData(filters))}));});
  const activity=make('section',undefined,'app-panel');activity.hidden=true;activity.append(make('h2','My applications'));const activityBody=make('div');activity.append(activityBody);document.querySelector('main').after(activity);
  async function loadApplications(){
    if(!session.user){activity.hidden=true;return;}activity.hidden=false;
    try{const data=await api('/api/applications/mine');activityBody.replaceChildren();if(!data.applications.length)activityBody.append(make('p','You have not applied yet.'));
      for(const a of data.applications)activityBody.append(make('p',`${a.advertisement_title} · ${a.company} · ${a.status}`));
    }catch(error){activityBody.textContent=error.message;}
  }
  let signedInNow=false;
  document.querySelector('#login-form')?.addEventListener('submit',()=>{signedInNow=true;},true);
  async function refresh(){
    try{session=await api('/api/auth/session');document.querySelector('#admin-link').hidden=session.user?.role!=='admin';
      document.querySelector('#google-account').hidden=!!session.user;
      if(session.user && retainedLoginStatus)retainedLoginStatus.textContent='';
      if(signedInNow && session.user?.role==='admin'){location.assign('/admin.php');return;}
      if(selectedAd && document.querySelector('#apply-form-section'))applicationForm(selectedAd);
      await loadApplications();await loadRecruiterApplications();
    }catch(error){activity.hidden=false;activityBody.textContent=error.message;}
  }
  let refreshTimer;
  new MutationObserver(()=>{if(retainedLoginStatus&&!retainedLoginStatus.isConnected)document.querySelector('#password-account').append(retainedLoginStatus);clearTimeout(refreshTimer);refreshTimer=setTimeout(refresh,50);}).observe(document.querySelector('#password-account'),{childList:true});
  refresh();

  // This supplies the missing edit callback used by Charles's existing recruiter dashboard.
  window.openEditForm=async(ad,article)=>{
    try{Object.assign(ad,await api(`/api/ads/${ad.id}`));}catch(error){article.append(make('p',error.message));return;}
    article.querySelector('form')?.remove();const form=make('form',undefined,'form-grid');
    for(const [key,label,type,required] of [['title','Title','text',true],['short_description','Summary','textarea',true],['description','Description','textarea',true],['location','Location','text',true],['salary','Salary','text',false],['working_time','Working time','text',true],['contract_type','Contract','text',false]]){
      const input=field(form,key,label,type,required,ad[key]);input.maxLength={title:200,short_description:500,description:16000,location:150,salary:100,working_time:100,contract_type:100}[key];
    }
    const save=make('button','Save advertisement'),cancel=make('button','Cancel','secondary'),notice=make('p',undefined,'notice');cancel.type='button';cancel.onclick=()=>form.remove();notice.setAttribute('role','status');form.append(save,cancel,notice);
    form.onsubmit=async e=>{e.preventDefault();save.disabled=true;try{const updated=await api(`/api/ads/${ad.id}`,'PATCH',Object.fromEntries(new FormData(form)));Object.assign(ad,updated);article.querySelector('h4').textContent=ad.title;notice.textContent='Saved.';document.dispatchEvent(new CustomEvent('alternatech:filters',{detail:Object.fromEntries(new FormData(filters))}));}catch(error){notice.textContent=error.message;}finally{save.disabled=false;}};article.append(form);
  };
  const received=make('section',undefined,'app-panel');received.hidden=true;received.append(make('h2','Received applications'));const receivedBody=make('div');received.append(receivedBody);activity.after(received);
  let receivedPage=1;
  async function loadRecruiterApplications(){
    if(!session.user){received.hidden=true;return;}
    try{
      const companies=await api('/api/companies/mine');if(!companies.length){received.hidden=true;return;}received.hidden=false;
      const data=await api(`/api/recruiter/applications?page=${receivedPage}`);receivedBody.replaceChildren();
      if(!data.applications.length)receivedBody.append(make('p','No applications received yet.'));
      for(const a of data.applications){const row=make('article',undefined,'recruiter-ad');row.append(make('h3',`${a.advertisement_title} · ${a.company}`),make('p',`${a.firstname} ${a.lastname} · ${a.email}${a.phone?' · '+a.phone:''}`),make('p',a.message||'No message'));
        const status=make('select');status.setAttribute('aria-label',`Status for application ${a.application_id}`);for(const v of ['pending','reviewed','accepted','rejected']){const o=make('option',v);o.value=v;status.append(o);}status.value=a.status;
        const save=make('button','Update status'),notice=make('p');notice.setAttribute('role','status');save.onclick=async()=>{save.disabled=true;try{await api('/api/companies/mine/applications','PATCH',{application_id:Number(a.application_id),status:status.value});notice.textContent='Status saved.';}catch(error){notice.textContent=error.message;}finally{save.disabled=false;}};row.append(status,save,notice);receivedBody.append(row);
      }
      const pagination=make('div',undefined,'pagination'),prev=make('button','Previous'),next=make('button','Next');prev.disabled=data.page<=1;next.disabled=data.page>=data.pages;
      prev.onclick=()=>{receivedPage--;loadRecruiterApplications();};next.onclick=()=>{receivedPage++;loadRecruiterApplications();};pagination.append(prev,make('span',`Page ${data.page} of ${data.pages}`),next);receivedBody.append(pagination);
    }catch(error){receivedBody.textContent=error.message;}
  }
})();
