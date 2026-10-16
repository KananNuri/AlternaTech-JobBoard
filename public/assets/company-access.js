'use strict';
(()=>{
  const notice=document.querySelector('#access-notice');let token='',selected=null,stateVersion=0,searchVersion=0;
  const make=(tag,value)=>{const n=document.createElement(tag);if(value!==undefined)n.textContent=value;return n};
  const tx=(tag,key,params)=>I18N.text(make(tag),key,params);
  const ready=fetch('/api/auth/session').then(async r=>{if(!r.ok)throw I18N.translated('account.checkError');const s=await r.json();if(!s.user)throw I18N.translated('errors.sessionExpired');token=s.csrf_token;});ready.catch(e=>I18N.error(notice,e));
  async function api(path,method='GET',body){await ready;const r=await fetch('/api/company-access'+path,{method,headers:{Accept:'application/json',...(method!=='GET'?{'Content-Type':'application/json','X-CSRF-Token':token}:{})},body:body===undefined?undefined:JSON.stringify(body)});if(r.status===204)return null;const data=await r.json();if(!r.ok)throw new Error(data.error||'Service temporarily unavailable');return data;}
  function empty(container){if(!container.children.length)container.append(tx('p','access.none'));}
  function row(container,title){const n=make('article');n.className='access-row';n.append(make('h3',title));container.append(n);return n;}
  async function act(button,operation,target=notice){button.disabled=true;I18N.text(target,'account.saving');try{await operation();I18N.text(target,'access.saved');await load();}catch(e){I18N.error(target,e);}finally{button.disabled=false;}}
  function decision(n,path){const actions=make('div');actions.className='actions';for(const value of ['approve','reject']){const b=tx('button','access.'+value);b.type='button';b.dataset.decision=value;if(value==='reject')b.className='secondary';b.onclick=()=>{for(const button of actions.querySelectorAll('button'))button.disabled=true;act(b,()=>api(path,'POST',{decision:value})).finally(()=>{for(const button of actions.querySelectorAll('button'))button.disabled=false;});};actions.append(b);}n.append(actions);}
  async function load(){const version=++stateVersion;try{
    const s=await api('');if(version!==stateVersion)return;
    const own=document.querySelector('#company-state');own.replaceChildren();
    for(const c of s.companies){const n=row(own,c.name);n.append(tx('p',Number(c.is_manager)?'access.manager':'access.recruiter'));const a=tx('a','access.openSpace');a.href='/#my-space';n.append(a);}
    for(const p of s.proposals){const n=row(own,p.name);n.append(tx('p','access.companyStatus',{status:{k:'access.status.'+p.status}}));}
    for(const r of s.requests){const n=row(own,r.name);n.append(tx('p','access.joinStatus',{status:{k:'access.status.'+r.status}}));}empty(own);
    const inbox=document.querySelector('#join-inbox');inbox.replaceChildren();
    for(const r of s.inbox){const n=row(inbox,`${r.company} · ${r.firstname} ${r.lastname}`);n.append(make('p',r.email),make('p',r.message));decision(n,`/joins/${r.id}/decision`);}empty(inbox);
    const members=document.querySelector('#company-members');members.replaceChildren();
    for(const m of s.members){const n=row(members,`${m.company} · ${m.firstname} ${m.lastname}`);n.append(make('p',m.email));if(Number(m.is_manager)){n.append(tx('p','access.manager'));continue;}
      const remove=tx('button','access.revoke');remove.className='secondary';remove.type='button';remove.onclick=()=>{remove.hidden=true;const confirm=tx('button','access.confirmRevoke'),cancel=tx('button','common.cancel');confirm.className='danger';confirm.type=cancel.type='button';cancel.className='secondary';cancel.onclick=()=>{confirm.remove();cancel.remove();remove.hidden=false;};confirm.onclick=()=>{cancel.disabled=true;act(confirm,()=>api(`/companies/${m.company_id}/members/${m.person_id}`,'DELETE')).finally(()=>{cancel.disabled=false;});};n.append(confirm,cancel);};n.append(remove);
    }empty(members);
    const proposals=document.querySelector('#proposal-inbox');if(proposals){proposals.replaceChildren();for(const p of s.pending_proposals){const n=row(proposals,p.name);n.append(make('p',`${p.firstname} ${p.lastname} · ${p.email}`),make('p',p.website||''),make('p',p.registration_number||''),make('p',p.evidence));decision(n,`/proposals/${p.id}/decision`);}empty(proposals);}
  }catch(e){if(version===stateVersion)I18N.error(notice,e);}}
  const proposal=document.querySelector('#company-proposal');proposal.onsubmit=e=>{e.preventDefault();act(proposal.querySelector('button'),async()=>{await api('/proposals','POST',Object.fromEntries(new FormData(proposal)));proposal.reset();},proposal.querySelector('.notice'));};
  const search=document.querySelector('#company-search'),results=document.querySelector('#company-results'),searchNotice=document.querySelector('#search-notice'),join=document.querySelector('#company-join'),manager=document.querySelector('#company-manager');
  search.onsubmit=async e=>{e.preventDefault();const version=++searchVersion;I18N.text(searchNotice,'admin.loading');results.replaceChildren();selected=null;join.hidden=true;if(manager)manager.hidden=true;
    try{const data=await api('/search?q='+encodeURIComponent(search.elements.q.value.trim()));if(version!==searchVersion)return;I18N.raw(searchNotice,'');for(const c of data.companies){const n=row(results,c.name);if(!Number(c.has_manager))n.append(tx('p','access.noManager'));const b=tx('button','access.select');b.type='button';b.onclick=()=>{selected=c.id;I18N.raw(document.querySelector('#selected-company'),c.name);join.hidden=false;if(manager)manager.hidden=false;join.elements.message.focus();};n.append(b);}empty(results);}catch(e){if(version===searchVersion)I18N.error(searchNotice,e);}};
  join.onsubmit=e=>{e.preventDefault();if(!selected)return;const id=selected;act(join.querySelector('button'),async()=>{await api(`/companies/${id}/join`,'POST',{message:join.elements.message.value});join.reset();},join.querySelector('.notice'));};
  if(manager)manager.onsubmit=e=>{e.preventDefault();if(!selected)return;const id=selected;act(manager.querySelector('button'),()=>api(`/companies/${id}/manager`,'POST',{email:manager.elements.email.value.trim()}),manager.querySelector('.notice'));};
  load();
})();
