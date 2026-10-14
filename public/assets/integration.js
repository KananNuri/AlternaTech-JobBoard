"use strict";
(() => {
  let session={user:null}, selectedAd=null;
  const retainedLoginStatus=document.querySelector('#login-status');
  const make=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
  // Interface text goes through I18N (i18n.js); make() is kept for API values, which are never translated.
  const tx=(tag,key,params,cls)=>I18N.text(make(tag,undefined,cls),key,params);
  const FALLBACK_STATUSES=[400,401,403,404,409,413,415,422,429];
  // Errors keep the backend message (error.message) and add the status and per-field details;
  // messages produced here carry error.i18n so they follow the active language.
  function apiError(status,data){
    const backend=typeof data?.error==='string'&&data.error;
    const error=backend?new Error(backend):I18N.translated(FALLBACK_STATUSES.includes(status)?`errors.${status}`:status>=500?'errors.unavailable':'errors.generic');
    error.status=status;error.fields=data?.fields&&typeof data.fields==='object'?data.fields:null;return error;
  }
  function localError(key,status){const error=I18N.translated(key);error.status=status;error.fields=null;return error;}
  // A network failure (no HTTP response) gets a readable message instead of the browser's "Failed to fetch".
  async function request(path,options){try{return await fetch(path,options);}catch{throw localError('errors.network');}}
  async function api(path,method='GET',body){
    const headers={Accept:'application/json'};if(body!==undefined)headers['Content-Type']='application/json';
    if(method!=='GET') {const r=await request('/api/auth/csrf',{headers:{Accept:'application/json'}});if(!r.ok)throw localError('errors.prepare',r.status);headers['X-CSRF-Token']=(await r.json()).csrf_token;}
    const r=await request(path,{method,headers,body:body===undefined?undefined:JSON.stringify(body)});
    // 204 has no body; unexpected failures may not be JSON.
    if(r.status===204)return null;const text=await r.text();let data=null;try{data=text?JSON.parse(text):null;}catch{}
    if(!r.ok)throw apiError(r.status,data);if(data===null)throw localError('errors.unexpected',r.status);return data;
  }
  // The caption is a keyed child of the label, so re-translating it never removes the input.
  function field(form,name,labelKey,type='text',required=false,value=''){
    const wrap=make('label'),input=make(type==='textarea'?'textarea':'input');input.name=name;if(type!=='textarea')input.type=type;input.required=required;input.value=value||'';
    input.maxLength=type==='textarea'?20000:(name==='email'?150:name==='phone'?30:100);wrap.append(tx('span',labelKey),input);form.append(wrap);return input;
  }
  function applicationForm(ad){
    selectedAd=ad;document.querySelector('#apply-form-section')?.remove();
    // The form replaces the detail panel's Apply button while it is open.
    const detailsApply=document.querySelector('#details .details-apply');if(detailsApply)detailsApply.hidden=true;
    const panel=make('section');panel.id='apply-form-section';panel.append(tx('h3','apply.title',{title:ad.title}));
    const form=make('form',undefined,'form-grid');
    if(session.user)panel.append(tx('p','apply.as',{name:`${session.user.firstname} ${session.user.lastname}`,email:session.user.email}));
    else{field(form,'firstname','field.firstname','text',true);field(form,'lastname','field.lastname','text',true);field(form,'email','field.email','email',true);field(form,'phone','field.phoneOptional','tel');}
    field(form,'message','apply.message','textarea');
    const submit=tx('button','apply.send'),notice=make('p',undefined,'notice');notice.setAttribute('role','status');form.append(submit,notice);
    form.addEventListener('submit',async e=>{e.preventDefault();submit.disabled=true;I18N.text(notice,'apply.sending');
      try{await api(`/api/ads/${ad.id}/applications`,'POST',Object.fromEntries(new FormData(form)));I18N.text(notice,'apply.saved');await loadApplications();}
      catch(error){I18N.error(notice,error);submit.disabled=false;}
    });panel.append(form);document.querySelector('#details').append(panel);
  }
  document.addEventListener('alternatech:ad-selected',e=>{
    selectedAd=e.detail.advertisement;const button=tx('button','card.apply',undefined,'details-apply');button.addEventListener('click',()=>applicationForm(selectedAd));document.querySelector('#details').append(button);
  });
  document.addEventListener('alternatech:apply',e=>{applicationForm(e.detail.advertisement);document.querySelector('#apply-form-section input,#apply-form-section textarea')?.focus();});
  const filters=make('form',undefined,'filters');I18N.attr(filters,'aria-label','filters.label');
  field(filters,'q','filters.keywords');field(filters,'location','filters.location');
  const label=make('label'),select=make('select');select.name='category_id';const all=tx('option','filters.all');all.value='';select.append(all);label.append(tx('span','filters.category'),select);filters.append(label);
  const search=tx('button','filters.search');filters.append(search);document.querySelector('#status').before(filters);
  // Shared by the board filters and the recruiter advertisement forms.
  const categoriesReady=api('/api/categories').catch(()=>[]);
  categoriesReady.then(categories=>{for(const category of categories){const option=make('option',category.name);option.value=category.id;select.append(option);}});
  filters.addEventListener('submit',e=>{e.preventDefault();document.dispatchEvent(new CustomEvent('alternatech:filters',{detail:Object.fromEntries(new FormData(filters))}));});
  const activity=make('section',undefined,'app-panel');activity.hidden=true;activity.append(tx('h2','mine.title'));const activityBody=make('div');activity.append(activityBody);
  // Applicant and recruiter panels live together in the "My space" section, before the recruiter area.
  const mySpace=document.querySelector('#my-space'),spaceGrid=mySpace?.querySelector('.my-space-grid');
  if(spaceGrid)spaceGrid.prepend(activity);else document.querySelector('main').after(activity);
  // google-login.js reports a cancelled Google sign-in inside the account menu: open it so the message is seen.
  if(new URLSearchParams(location.search).get('google_login')==='cancelled')window.alternatechAccountMenu?.open();
  const statusLabel=value=>I18N.has(`status.${value}`)?{k:`status.${value}`}:value;
  async function loadApplications(){
    if(!session.user){activity.hidden=true;return;}activity.hidden=false;
    try{const data=await api('/api/applications/mine');activityBody.replaceChildren();if(!data.applications.length)activityBody.append(tx('p','mine.none'));
      for(const a of data.applications)activityBody.append(tx('p','mine.row',{title:a.advertisement_title,company:a.company,status:statusLabel(a.status)}));
    }catch(error){activityBody.replaceChildren(I18N.error(make('p'),error));}
  }
  let signedInNow=false;
  document.querySelector('#login-form')?.addEventListener('submit',()=>{signedInNow=true;},true);
  async function refresh(){
    try{session=await api('/api/auth/session');
      // Header display state after an in-page sign-in (page.php renders the same state server-side on load).
      for(const link of document.querySelectorAll('.app-nav [data-auth]')){const need=link.dataset.auth;link.hidden=need==='guest'?!!session.user:need==='admin'?session.user?.role!=='admin':!session.user;}
      const accountToggle=document.querySelector('#account-toggle');if(accountToggle){if(session.user)I18N.raw(accountToggle,`${session.user.firstname} ${session.user.lastname}`);else I18N.text(accountToggle,'account.signIn');}
      if(mySpace){const wasHidden=mySpace.hidden;mySpace.hidden=!session.user;if(wasHidden&&session.user&&location.hash==='#my-space')mySpace.scrollIntoView();}
      document.querySelector('#google-account').hidden=!!session.user;
      if(session.user && retainedLoginStatus)I18N.raw(retainedLoginStatus,'');
      if(signedInNow && session.user?.role==='admin'){location.assign('/admin.php');return;}
      if(selectedAd && document.querySelector('#apply-form-section'))applicationForm(selectedAd);
      await loadApplications();await loadRecruiterApplications();
    }catch(error){activity.hidden=false;activityBody.replaceChildren(I18N.error(make('p'),error));}
  }
  let refreshTimer;
  new MutationObserver(()=>{if(retainedLoginStatus&&!retainedLoginStatus.isConnected)document.querySelector('#password-account').append(retainedLoginStatus);clearTimeout(refreshTimer);refreshTimer=setTimeout(refresh,50);}).observe(document.querySelector('#password-account'),{childList:true});
  refresh();

  // Recruiter advertisements. Charles's login.js renders the list; this extends it through its
  // openEditForm callback and refreshes it with its global loadRecruiterCompanies function.
  // Limits mirror api/ads/validation.php; the server remains the authority.
  const AD_FIELDS=[['title','ad.title','text',true,200],['short_description','ad.summary','textarea',true,500],['description','ad.description','textarea',true,16000],['location','ad.location','text',true,150],['working_time','ad.workingTime','text',true,100],['salary','ad.salaryOptional','text',false,100],['contract_type','ad.contractOptional','text',false,100]];
  const recruiterArea=document.querySelector('#recruiter-area');
  const recruiterNotice=make('p',undefined,'notice');recruiterNotice.id='recruiter-notice';recruiterNotice.setAttribute('role','status');recruiterNotice.tabIndex=-1;
  const tools=make('section',undefined,'recruiter-tools');tools.hidden=true;I18N.attr(tools,'aria-label','tools.title');
  if(recruiterArea){recruiterArea.querySelector('#recruiter-companies')?.before(recruiterNotice);recruiterArea.append(tools);}
  let formCount=0,toolsSignature='',adsReload=null,reloadAgain=false;
  const editRequests=new WeakMap();
  // Focus the outcome message when the element that held focus has been removed.
  function announce(key,params){I18N.text(recruiterNotice,key,params);recruiterNotice.focus();}
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
    summary.classList.remove('error');I18N.raw(summary,'');
  }
  const fieldName=key=>I18N.has(`col.${key}`)?tx('span',`col.${key}`):make('span',key);
  function showErrors(form,summary,error){
    clearErrors(form,summary);summary.classList.add('error');
    if(error.status===401){const link=tx('a','errors.signInAgain');link.href='/login.php';summary.replaceChildren(tx('span','errors.sessionExpired'),link);return;}
    I18N.error(summary,error);if(!error.fields)return;
    // 422 responses from the advertisement handler name the invalid fields.
    const unmatched=[];let first=null;
    for(const [key,message] of Object.entries(error.fields)){
      const input=form.elements.namedItem(key);
      if(!(input instanceof HTMLElement)){unmatched.push([key,String(message)]);continue;}
      // Outside the label: the message stays out of the accessible name and is read once, via aria-describedby.
      const note=I18N.message(make('span',undefined,'field-error'),String(message));note.id=`${form.id}-${key}-error`;
      input.setAttribute('aria-invalid','true');input.setAttribute('aria-describedby',note.id);(input.closest('label')||input).after(note);first??=input;
    }
    if(unmatched.length){
      const parts=[I18N.error(make('span'),error),make('span',': ')];
      unmatched.forEach(([key,message],i)=>{if(i)parts.push(make('span','; '));parts.push(fieldName(key),make('span',': '),I18N.message(make('span'),message));});
      I18N.raw(summary,'');summary.replaceChildren(...parts);
    } else I18N.text(summary,'errors.fixFields');
    first?.focus();
  }
  function adForm(ad,categories,companies){
    const form=make('form',undefined,'form-grid ad-form');form.id=`ad-form-${++formCount}`;
    if(companies){
      const wrap=make('label'),choice=make('select');choice.name='company_id';choice.required=true;
      if(companies.length>1){const o=tx('option','ad.chooseCompany');o.value='';choice.append(o);}
      for(const c of companies){const o=make('option',c.name);o.value=c.id;choice.append(o);}
      wrap.append(tx('span','ad.company'),choice);form.append(wrap);
    } else form.append(tx('p','ad.companyValue',{name:ad.company},'muted'));
    // An imported advertisement may hold NULL in a required column; keep it editable without forcing that field.
    for(const [key,labelKey,type,required,limit] of AD_FIELDS){const input=field(form,key,labelKey,type,required&&!(ad&&ad[key]==null),ad?.[key]);input.maxLength=limit;}
    const wrap=make('label'),choice=make('select');choice.name='category_id';
    const current=ad?.category_id==null?null:Number(ad.category_id);
    if(current===null){const o=tx('option',ad?'ad.uncategorizedChoose':'ad.chooseCategory');o.value='';choice.append(o);}
    for(const c of categories){const o=make('option',c.name);o.value=c.id;choice.append(o);}
    // When categories failed to load, an edit can still save other fields (category_id is then omitted).
    choice.required=!ad||(current!==null&&categories.length>0);if(current!==null)choice.value=String(current);
    wrap.append(tx('span','ad.category'),choice);form.append(wrap);
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
  function formControls(saveKey){
    const summary=make('p',undefined,'notice');summary.setAttribute('role','status');
    const save=tx('button',saveKey),cancel=tx('button','common.cancel',undefined,'secondary');cancel.type='button';
    const actions=make('div',undefined,'actions');actions.append(save,cancel);return {summary,save,cancel,actions};
  }
  function renderCreatePanel(companies){
    tools.hidden=!companies.length;
    const signature=JSON.stringify(companies.map(c=>[c.id,c.name]));if(signature===toolsSignature)return;
    // Rebuild only when the company list changes, so pagination refreshes never discard a draft.
    toolsSignature=signature;tools.replaceChildren();if(!companies.length)return;
    const open=tx('button','tools.new');open.type='button';tools.append(tx('h3','tools.title'),open);
    open.onclick=async()=>{
      open.hidden=true;I18N.raw(recruiterNotice,'');
      const categories=await categoriesReady,form=adForm(null,categories,companies),{summary,save,cancel,actions}=formControls('tools.publish');
      if(!categories.length){summary.classList.add('error');I18N.text(summary,'tools.categoriesUnavailable');}
      form.append(actions,summary);
      cancel.onclick=()=>{form.remove();open.hidden=false;open.focus();};
      form.onsubmit=e=>{e.preventDefault();submitWith(form,summary,[save,cancel],async()=>{
        const created=await api('/api/ads','POST',createPayload(form));
        form.remove();open.hidden=false;announce('notice.published',{title:created.title,company:created.company});reloadRecruiterAds();
      });};
      tools.append(form);form.elements.namedItem(companies.length>1?'company_id':'title').focus();
    };
  }
  function confirmDelete(ad,actions,buttons,opener){
    const form=actions.parentElement;form.querySelector('.confirm-delete')?.remove();
    const box=make('div',undefined,'confirm-delete');box.setAttribute('role','group');I18N.attr(box,'aria-label','delete.groupLabel',{title:ad.title});
    const text=tx('p','delete.warning',{title:ad.title});
    const count=tx('p','delete.checking',undefined,'muted');count.setAttribute('aria-live','polite');
    // Screen readers read the warning and the count when focus enters the group.
    text.id=`${form.id}-delete-warning`;count.id=`${form.id}-delete-count`;box.setAttribute('aria-describedby',`${text.id} ${count.id}`);
    const yes=tx('button','delete.confirm',undefined,'danger'),no=tx('button','common.cancel',undefined,'secondary');yes.type='button';no.type='button';
    const row=make('div',undefined,'actions');row.append(yes,no);box.append(text,count,row);actions.after(box);no.focus();
    no.onclick=()=>{box.remove();opener.focus();};
    api('/api/companies/mine/applications').then(data=>{
      const n=data.applications.filter(a=>Number(a.advertisement_id)===Number(ad.id)).length;
      I18N.text(count,'delete.count',{count:n});
    }).catch(()=>{I18N.text(count,'delete.countUnavailable');});
    yes.onclick=async()=>{
      for(const b of [yes,no,...buttons])b.disabled=true;
      try{
        await api(`/api/ads/${ad.id}`,'DELETE');
        announce('notice.deleted',{title:ad.title});reloadRecruiterAds();loadRecruiterApplications();loadApplications();
      }catch(error){
        if(error.status===404){announce('notice.alreadyDeleted');reloadRecruiterAds();return;}
        text.classList.add('error');if(error.status===401)I18N.text(text,'delete.sessionExpired');else I18N.error(text,error);
        for(const b of [yes,no,...buttons])b.disabled=false;
      }
    };
  }
  // This supplies the edit callback used by Charles's existing recruiter dashboard.
  // Update login.js's card (h4, location, category) in place so other open forms keep their drafts.
  function updateArticle(article,ad){
    const title=article.querySelector('h4'),[place,category]=article.querySelectorAll(':scope > p');
    if(!title||!place||!category)return false;
    title.textContent=ad.title;place.textContent=ad.location;if(ad.category)I18N.raw(category,ad.category);else I18N.text(category,'recruiter.uncategorized');return true;
  }
  window.openEditForm=async(ad,article)=>{
    // Only the latest request per card may render, so a double click never yields two forms.
    const request={};editRequests.set(article,request);
    article.querySelector('form')?.remove();article.querySelector('.edit-loading')?.remove();I18N.raw(recruiterNotice,'');
    const loading=tx('p','edit.loading',undefined,'notice edit-loading');article.append(loading);
    const opener=[...article.children].find(n=>n.tagName==='BUTTON');
    let current,categories;
    // The recruiter list omits contract_type, so edit from the full advertisement.
    try{[current,categories]=await Promise.all([api(`/api/ads/${ad.id}`),categoriesReady]);}
    catch(error){if(editRequests.get(article)!==request||!article.isConnected)return;loading.classList.add('error');if(error.status===404)I18N.text(loading,'edit.notFound');else I18N.error(loading,error);return;}
    if(editRequests.get(article)!==request)return;
    // login.js may have rebuilt the list meanwhile; never attach a form to a card that is gone.
    if(!article.isConnected){announce('edit.refreshed');return;}
    loading.remove();article.querySelector('form')?.remove();
    const form=adForm(current,categories,null),{summary,save,cancel,actions}=formControls('edit.save');
    const remove=tx('button','edit.delete',undefined,'danger');remove.type='button';actions.append(remove);
    // Kept apart from the summary, which every save attempt clears.
    if(!categories.length)form.append(tx('p','edit.categoriesUnavailable',undefined,'notice error'));
    form.append(actions,summary);
    const close=()=>{form.remove();opener?.focus();};
    // The card's Edit button is disabled too while a request runs, so no second form can open.
    const busy=[save,cancel,remove,opener].filter(Boolean);
    cancel.onclick=close;
    remove.onclick=()=>confirmDelete(current,actions,busy,remove);
    form.onsubmit=e=>{
      e.preventDefault();const changes=changedFields(form,current);
      if(!Object.keys(changes).length){clearErrors(form,summary);I18N.text(summary,'edit.noChanges');return;}
      submitWith(form,summary,busy,async()=>{
        try{
          const updated=await api(`/api/ads/${current.id}`,'PATCH',changes);
          if(updateArticle(article,updated)){form.remove();refreshBoard();}else reloadRecruiterAds();
          announce('notice.saved',{title:updated.title});
        }
        catch(error){if(error.status===404){announce('edit.notFound');reloadRecruiterAds();return;}throw error;}
      });
    };
    article.append(form);form.elements.namedItem('title').focus();
  };
  const received=make('section',undefined,'app-panel');received.hidden=true;received.append(tx('h2','received.title'));const receivedBody=make('div');received.append(receivedBody);activity.after(received);
  let receivedPage=1;
  async function loadRecruiterApplications(){
    if(!session.user){received.hidden=true;tools.hidden=true;mySpace?.classList.remove('is-recruiter');return;}
    try{
      // Recruiter-only panels are shown when /api/companies/mine lists a company; every write is still authorised server-side.
      const companies=await api('/api/companies/mine');renderCreatePanel(companies);mySpace?.classList.toggle('is-recruiter',companies.length>0);if(!companies.length){received.hidden=true;return;}received.hidden=false;
      const data=await api(`/api/recruiter/applications?page=${receivedPage}`);
      // A deletion can remove the current last page; show the new last page instead of an empty one.
      if(data.page>data.pages){receivedPage=data.pages;return loadRecruiterApplications();}
      receivedBody.replaceChildren();
      if(!data.applications.length)receivedBody.append(tx('p','received.none'));
      for(const a of data.applications){const row=make('article',undefined,'recruiter-ad');row.append(make('h3',`${a.advertisement_title} · ${a.company}`),make('p',`${a.firstname} ${a.lastname} · ${a.email}${a.phone?' · '+a.phone:''}`),a.message?make('p',a.message):tx('p','received.noMessage'));
        const status=make('select');I18N.attr(status,'aria-label','received.statusLabel',{id:a.application_id});for(const v of ['pending','reviewed','accepted','rejected']){const o=tx('option',`status.${v}`);o.value=v;status.append(o);}status.value=a.status;
        const save=tx('button','received.update'),notice=make('p');notice.setAttribute('role','status');save.onclick=async()=>{save.disabled=true;try{await api('/api/companies/mine/applications','PATCH',{application_id:Number(a.application_id),status:status.value});I18N.text(notice,'received.saved');}catch(error){I18N.error(notice,error);}finally{save.disabled=false;}};row.append(status,save,notice);receivedBody.append(row);
      }
      // Like the board, a single page needs no pagination controls.
      if(data.pages<=1)return;
      const pagination=make('div',undefined,'pagination'),prev=tx('button','pager.prev'),next=tx('button','pager.next');prev.disabled=data.page<=1;next.disabled=data.page>=data.pages;
      prev.onclick=()=>{receivedPage--;loadRecruiterApplications();};next.onclick=()=>{receivedPage++;loadRecruiterApplications();};pagination.append(prev,tx('span','pager.status',{page:data.page,pages:data.pages}),next);receivedBody.append(pagination);
    }catch(error){receivedBody.replaceChildren(I18N.error(make('p'),error));}
  }
})();
