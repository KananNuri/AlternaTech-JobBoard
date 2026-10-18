"use strict";
(() => {
  let session={user:null}, selectedAd=null;
  const retainedLoginStatus=document.querySelector('#login-status');
  const make=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
  const fallbackMessages={400:'The request was not understood.',401:'Your session has expired. Please sign in again.',403:'You are not allowed to perform this action.',404:'The requested item was not found.',409:'This change conflicts with existing data.',413:'The request is too large.',415:'Unsupported request format.',422:'Please check the submitted information.',429:'Too many attempts. Please wait and try again.'};
  // Errors keep the backend message for existing callers and add the status and per-field details.
  function apiError(status,data){
    const error=new Error((typeof data?.error==='string'&&data.error)||fallbackMessages[status]||(status>=500?'Service temporarily unavailable. Please try again.':'Request failed.'));
    error.status=status;error.fields=data?.fields&&typeof data.fields==='object'?data.fields:null;return error;
  }
  async function api(path,method='GET',body){
    const headers={Accept:'application/json'};if(body!==undefined)headers['Content-Type']='application/json';
    if(method!=='GET') {const r=await fetch('/api/auth/csrf',{headers:{Accept:'application/json'}});if(!r.ok)throw apiError(r.status,{error:'Unable to prepare request.'});headers['X-CSRF-Token']=(await r.json()).csrf_token;}
    const r=await fetch(path,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});
    // 204 has no body; unexpected failures may not be JSON.
    if(r.status===204)return null;const text=await r.text();let data=null;try{data=text?JSON.parse(text):null;}catch{}
    if(!r.ok)throw apiError(r.status,data);if(data===null)throw apiError(r.status,{error:'Unexpected server response.'});return data;
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
  // Shared by the board filters and the recruiter advertisement forms.
  let categoriesRequest;
  function loadCategories(){
    return categoriesRequest??=(api('/api/categories').catch(error=>{categoriesRequest=null;throw error;}));
  }
  function fillCategories(categories){select.replaceChildren(all);for(const category of categories){const option=make('option',category.name);option.value=category.id;select.append(option);}}
  const categoryRetry=make('button','Retry categories','secondary');categoryRetry.type='button';categoryRetry.hidden=true;filters.append(categoryRetry);
  async function refreshCategories(){try{fillCategories(await loadCategories());categoryRetry.hidden=true;}catch{categoryRetry.hidden=false;}}
  categoryRetry.onclick=refreshCategories;refreshCategories();
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

  // Recruiter advertisements. Charles's login.js renders the list; this extends it through its
  // openEditForm callback and refreshes it with its global loadRecruiterCompanies function.
  // Limits mirror api/ads/validation.php; the server remains the authority.
  const AD_FIELDS=[['title','Title','text',true,200],['short_description','Summary','textarea',true,500],['description','Description','textarea',true,16000],['location','Location','text',true,150],['working_time','Working time','text',true,100],['salary','Salary (optional)','text',false,100],['contract_type','Contract (optional)','text',false,100]];
  const recruiterArea=document.querySelector('#recruiter-area');
  const recruiterNotice=make('p',undefined,'notice');recruiterNotice.id='recruiter-notice';recruiterNotice.setAttribute('role','status');recruiterNotice.tabIndex=-1;
  const tools=make('section',undefined,'recruiter-tools');tools.hidden=true;tools.setAttribute('aria-label','Publish an advertisement');
  if(recruiterArea){recruiterArea.querySelector('#recruiter-companies')?.before(recruiterNotice);recruiterArea.append(tools);}
  let formCount=0,toolsSignature='',adsReload=null,reloadAgain=false;
  const editRequests=new WeakMap();
  // Focus the outcome message when the element that held focus has been removed.
  function announce(message){recruiterNotice.textContent=message;recruiterNotice.focus();}
  function refreshBoard(){document.dispatchEvent(new CustomEvent('alternatech:filters',{detail:Object.fromEntries(new FormData(filters))}));}
  // login.js clears and rebuilds the list across awaits, so overlapping reloads would duplicate sections.
  function reloadRecruiterAds(){
    refreshBoard();if(typeof window.loadRecruiterCompanies!=='function')return;
    if(adsReload){reloadAgain=true;return;}
    adsReload=(async()=>{try{do{reloadAgain=false;await window.loadRecruiterCompanies();}while(reloadAgain);}finally{adsReload=null;}})();
  }
  function clearErrors(form,summary){
    for(const n of form.querySelectorAll('.field-error'))n.remove();
    for(const n of form.querySelectorAll('[aria-invalid]')){n.removeAttribute('aria-invalid');n.removeAttribute('aria-describedby');}
    summary.classList.remove('error');summary.textContent='';
  }
  function showErrors(form,summary,error){
    clearErrors(form,summary);summary.classList.add('error');
    if(error.status===401){summary.textContent='Your session has expired. ';const link=make('a','Sign in again');link.href='/login.php';summary.append(link);return;}
    summary.textContent=error.message;if(!error.fields)return;
    // 422 responses from the advertisement handler name the invalid fields.
    const unmatched=[];let first=null;
    for(const [key,message] of Object.entries(error.fields)){
      const input=form.elements.namedItem(key);
      if(!(input instanceof HTMLElement)){unmatched.push(`${key}: ${message}`);continue;}
      // Outside the label: the message stays out of the accessible name and is read once, via aria-describedby.
      const note=make('span',String(message),'field-error');note.id=`${form.id}-${key}-error`;
      input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',note.id);(input.closest('label')||input).after(note);first??=input;
    }
    summary.textContent=unmatched.length?`${error.message}: ${unmatched.join('; ')}`:'Please correct the highlighted fields.';
    first?.focus();
  }
  function adForm(ad,categories,companies){
    const form=make('form',undefined,'form-grid ad-form');form.id=`ad-form-${++formCount}`;
    if(companies){
      const wrap=make('label','Company'),choice=make('select');choice.name='company_id';choice.required=true;
      if(companies.length>1){const o=make('option','Choose a company');o.value='';choice.append(o);}
      for(const c of companies){const o=make('option',c.name);o.value=c.id;choice.append(o);}
      wrap.append(choice);form.append(wrap);
    } else form.append(make('p',`Company: ${ad.company}`,'muted'));
    // An imported advertisement may hold NULL in a required column; keep it editable without forcing that field.
    for(const [key,text,type,required,limit] of AD_FIELDS){const input=field(form,key,text,type,required&&!(ad&&ad[key]==null),ad?.[key]);input.maxLength=limit;}
    const wrap=make('label','Category'),choice=make('select');choice.name='category_id';
    const current=ad?.category_id==null?null:Number(ad.category_id);
    if(current===null){const o=make('option',ad?'Uncategorized (choose a category to set one)':'Choose a category');o.value='';choice.append(o);}
    for(const c of categories){const o=make('option',c.name);o.value=c.id;choice.append(o);}
    // When categories failed to load, an edit can still save other fields (category_id is then omitted).
    choice.required=!ad||(current!==null&&categories.length>0);if(current!==null)choice.value=String(current);
    wrap.append(choice);form.append(wrap);
    return form;
  }
  // Legacy validation requires JSON integers for IDs; optional text is sent as null when empty.
  function createPayload(form){
    const data={};for(const [key,,,required] of AD_FIELDS){const v=form.elements.namedItem(key).value;data[key]=!required&&v.trim()===''?null:v;}
    data.company_id=Number(form.elements.namedItem('company_id').value);data.category_id=Number(form.elements.namedItem('category_id').value);return data;
  }
  // PATCH only what changed, so untouched imported NULL values never fail validation and company_id is never sent.
  // Textarea values always use LF; stored text may use CRLF, which must not count as a change.
  const sameLines=value=>typeof value==='string'?value.replace(/\r\n?/g,'\n'):value;
  function changedFields(form,ad){
    const data={};
    for(const [key,,,required] of AD_FIELDS){
      const v=sameLines(form.elements.namedItem(key).value),old=sameLines(ad[key]??null);
      if(required){if(v===(old??'')||(old===null&&v.trim()===''))continue;data[key]=v;}
      else{const next=v.trim()===''?null:v,prev=old===''?null:old;if(next!==prev)data[key]=next;}
    }
    const category=form.elements.namedItem('category_id').value;
    if(category!==''&&Number(category)!==Number(ad.category_id))data.category_id=Number(category);
    return data;
  }
  async function submitWith(form,summary,buttons,work){
    clearErrors(form,summary);for(const b of buttons)b.disabled=true;
    try{await work();}catch(error){showErrors(form,summary,error);}finally{for(const b of buttons)b.disabled=false;}
  }
  function formControls(saveText){
    const summary=make('p',undefined,'notice');summary.setAttribute('role','status');
    const save=make('button',saveText),cancel=make('button','Cancel','secondary');cancel.type='button';
    const actions=make('div',undefined,'actions');actions.append(save,cancel);return {summary,save,cancel,actions};
  }
  function renderCreatePanel(companies){
    tools.hidden=!companies.length;
    const signature=JSON.stringify(companies.map(c=>[c.id,c.name]));if(signature===toolsSignature)return;
    // Rebuild only when the company list changes, so pagination refreshes never discard a draft.
    toolsSignature=signature;tools.replaceChildren();if(!companies.length)return;
    const open=make('button','New advertisement');open.type='button';tools.append(make('h3','Publish an advertisement'),open);
    open.onclick=async()=>{
      open.hidden=true;recruiterNotice.textContent='';
      let categories;try{categories=await loadCategories();fillCategories(categories);}catch{open.hidden=false;announce('Categories could not be loaded. Select New advertisement to retry.');return;}
      const form=adForm(null,categories,companies),{summary,save,cancel,actions}=formControls('Publish advertisement');
      if(!categories.length){summary.classList.add('error');summary.textContent='Categories are unavailable; reload the page before publishing.';}
      form.append(actions,summary);
      cancel.onclick=()=>{form.remove();open.hidden=false;open.focus();};
      form.onsubmit=e=>{e.preventDefault();submitWith(form,summary,[save,cancel],async()=>{
        const created=await api('/api/ads','POST',createPayload(form));
        form.remove();open.hidden=false;announce(`Advertisement “${created.title}” published for ${created.company}.`);reloadRecruiterAds();
      });};
      tools.append(form);form.elements.namedItem(companies.length>1?'company_id':'title').focus();
    };
  }
  function confirmDelete(ad,actions,buttons,opener){
    const form=actions.parentElement;form.querySelector('.confirm-delete')?.remove();
    const box=make('div',undefined,'confirm-delete');box.setAttribute('role','group');box.setAttribute('aria-label',`Confirm deletion of ${ad.title}`);
    const text=make('p',`Delete “${ad.title}”? This permanently removes the advertisement. Applications received for it will also be deleted.`);
    const count=make('p','Checking received applications…','muted');count.setAttribute('aria-live','polite');
    // Screen readers read the warning and the count when focus enters the group.
    text.id=`${form.id}-delete-warning`;count.id=`${form.id}-delete-count`;box.setAttribute('aria-describedby',`${text.id} ${count.id}`);
    const yes=make('button','Confirm delete','danger'),no=make('button','Cancel','secondary');yes.type='button';no.type='button';
    const row=make('div',undefined,'actions');row.append(yes,no);box.append(text,count,row);actions.after(box);no.focus();
    no.onclick=()=>{box.remove();opener.focus();};
    api('/api/companies/mine/applications').then(data=>{
      const n=data.applications.filter(a=>Number(a.advertisement_id)===Number(ad.id)).length;
      count.textContent=`Applications currently received: ${n}. This number is indicative and may change before deletion.`;
    }).catch(()=>{count.textContent='The number of received applications could not be checked.';});
    yes.onclick=async()=>{
      for(const b of [yes,no,...buttons])b.disabled=true;
      try{
        await api(`/api/ads/${ad.id}`,'DELETE');
        announce(`Advertisement “${ad.title}” deleted, together with its applications.`);reloadRecruiterAds();loadRecruiterApplications();loadApplications();
      }catch(error){
        if(error.status===404){announce('This advertisement had already been deleted.');reloadRecruiterAds();return;}
        text.classList.add('error');text.textContent=error.status===401?'Your session has expired. Sign in again to delete this advertisement.':error.message;
        for(const b of [yes,no,...buttons])b.disabled=false;
      }
    };
  }
  // This supplies the edit callback used by Charles's existing recruiter dashboard.
  // Update login.js's card (h4, location, category) in place so other open forms keep their drafts.
  function updateArticle(article,ad){
    const title=article.querySelector('h4'),[place,category]=article.querySelectorAll(':scope > p');
    if(!title||!place||!category)return false;
    title.textContent=ad.title;place.textContent=ad.location;category.textContent=ad.category||'Uncategorized';return true;
  }
  window.openEditForm=async(ad,article)=>{
    // Only the latest request per card may render, so a double click never yields two forms.
    const request={};editRequests.set(article,request);
    article.querySelector('form')?.remove();article.querySelector('.edit-loading')?.remove();recruiterNotice.textContent='';
    const loading=make('p','Loading advertisement…','notice edit-loading');article.append(loading);
    const opener=[...article.children].find(n=>n.tagName==='BUTTON');
    let current,categories;
    // The recruiter list omits contract_type, so edit from the full advertisement.
    try{[current,categories]=await Promise.all([api(`/api/ads/${ad.id}`),loadCategories().catch(()=>[])]);}
    catch(error){if(editRequests.get(article)!==request||!article.isConnected)return;loading.classList.add('error');loading.textContent=error.status===404?'This advertisement no longer exists.':error.message;return;}
    if(editRequests.get(article)!==request)return;
    // login.js may have rebuilt the list meanwhile; never attach a form to a card that is gone.
    if(!article.isConnected){announce('The list was refreshed. Select Edit again to modify this advertisement.');return;}
    loading.remove();article.querySelector('form')?.remove();
    const form=adForm(current,categories,null),{summary,save,cancel,actions}=formControls('Save changes');
    const remove=make('button','Delete advertisement','danger');remove.type='button';actions.append(remove);
    // Kept apart from the summary, which every save attempt clears.
    if(!categories.length)form.append(make('p','Categories are unavailable, so the category cannot be changed until the page is reloaded.','notice error'));
    form.append(actions,summary);
    const close=()=>{form.remove();opener?.focus();};
    // The card's Edit button is disabled too while a request runs, so no second form can open.
    const busy=[save,cancel,remove,opener].filter(Boolean);
    cancel.onclick=close;
    remove.onclick=()=>confirmDelete(current,actions,busy,remove);
    form.onsubmit=e=>{
      e.preventDefault();const changes=changedFields(form,current);
      if(!Object.keys(changes).length){clearErrors(form,summary);summary.textContent='No changes to save.';return;}
      submitWith(form,summary,busy,async()=>{
        try{
          const updated=await api(`/api/ads/${current.id}`,'PATCH',changes);
          if(updateArticle(article,updated)){form.remove();refreshBoard();}else reloadRecruiterAds();
          announce(`Advertisement “${updated.title}” saved.`);
        }
        catch(error){if(error.status===404){announce('This advertisement no longer exists.');reloadRecruiterAds();return;}throw error;}
      });
    };
    article.append(form);form.elements.namedItem('title').focus();
  };
  const received=make('section',undefined,'app-panel');received.hidden=true;received.append(make('h2','Received applications'));const receivedBody=make('div');received.append(receivedBody);activity.after(received);
  let receivedPage=1;
  async function loadRecruiterApplications(){
    if(!session.user){received.hidden=true;tools.hidden=true;return;}
    try{
      const companies=await api('/api/companies/mine');renderCreatePanel(companies);if(!companies.length){received.hidden=true;return;}received.hidden=false;
      const data=await api(`/api/recruiter/applications?page=${receivedPage}`);
      // A deletion can remove the current last page; show the new last page instead of an empty one.
      if(data.page>data.pages){receivedPage=data.pages;return loadRecruiterApplications();}
      receivedBody.replaceChildren();
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
