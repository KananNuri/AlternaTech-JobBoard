"use strict";
(() => {
  const resource=document.querySelector('#resource'),notice=document.querySelector('#admin-notice'),form=document.querySelector('#record-form'),editor=document.querySelector('#record-editor');
  let specs={},page=1,pages=1,currentKey=null,csrfToken='';
  const make=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
  const tx=(tag,key,params)=>I18N.text(make(tag),key,params);
  const labelOf=key=>key.replaceAll('_',' ').replace(/^./,c=>c.toUpperCase());
  // Table and column names get a translated label when the dictionary has one, otherwise the readable fallback.
  const named=(el,prefix,key)=>I18N.has(prefix+key)?I18N.text(el,prefix+key):I18N.raw(el,labelOf(key));
  async function api(path,method='GET',body){const r=await fetch(path,{method,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':csrfToken},body:body===undefined?undefined:JSON.stringify(body)});if(r.status===204)return null;const data=await r.json();if(!r.ok)throw data.error?new Error(data.error):I18N.translated('errors.generic');return data;}
  function keyOf(row){return resource.value==='company_members'?`${row.company_id}:${row.person_id}`:resource.value==='google_identities'?row.google_sub:row.id;}
  async function load(){
    I18N.text(notice,'admin.loading');
    try{
      const result=await api(`/api/admin/${resource.value}?page=${page}&per_page=10`);pages=result.pages;
      if(page>pages){page=pages;return load();}
      const head=document.querySelector('#records-head'),body=document.querySelector('#records-body');head.replaceChildren();body.replaceChildren();
      const keys=result.items.length?Object.keys(result.items[0]):Object.keys(specs[resource.value]).filter(k=>k!=='password');
      const tr=make('tr');for(const key of keys){const th=named(make('th'),'col.',key);th.scope='col';tr.append(th);}const actionsHead=tx('th','admin.actions');actionsHead.scope='col';tr.append(actionsHead);head.append(tr);
      for(const row of result.items){const line=make('tr');for(const key of keys)line.append(make('td',row[key]===null?'—':String(row[key])));
        const actions=make('td'),edit=tx('button','admin.edit'),remove=tx('button','admin.delete');remove.className='danger';edit.onclick=()=>editRecord(row);remove.onclick=async()=>{
          // Explicit action-local confirmation explains cascading deletes; no popup is needed.
          actions.replaceChildren(tx('span','admin.deleteQuestion'));const yes=tx('button','admin.confirmDelete'),no=tx('button','common.cancel');actions.append(yes,no);no.onclick=()=>load();yes.onclick=async()=>{yes.disabled=true;try{await api(`/api/admin/${resource.value}/${encodeURIComponent(keyOf(row))}`,'DELETE');editor.hidden=true;await load();I18N.text(notice,'admin.deleted');}catch(error){I18N.error(notice,error);yes.disabled=false;}};
        };actions.append(edit,remove);line.append(actions);body.append(line);
      }
      I18N.text(notice,'admin.count',{count:Number(result.total)});I18N.text(document.querySelector('#admin-page'),'pager.status',{page,pages});document.querySelector('#admin-prev').disabled=page<=1;document.querySelector('#admin-next').disabled=page>=pages;
    }catch(error){I18N.error(notice,error);}
  }
  function editRecord(row=null){
    currentKey=row?keyOf(row):null;form.replaceChildren();editor.hidden=false;I18N.text(document.querySelector('#editor-title'),row?'admin.editTitle':'admin.createTitle');
    for(const [key,[type,limit,required]] of Object.entries(specs[resource.value])){
      // The caption is a keyed child so a language switch never removes the input from its label.
      const label=make('label'),caption=make('span');
      if(required)named(caption,'col.',key);else if(I18N.has('col.'+key))I18N.text(caption,'admin.optional',{label:{k:'col.'+key}});else I18N.raw(caption,labelOf(key));
      label.append(caption);
      let input;
      if(type==='enum'){input=make('select');for(const value of limit){const o=make('option',value);o.value=value;input.append(o);}}
      else{input=make(type==='text'&&limit>500?'textarea':'input');if(input.tagName==='INPUT')input.type=type==='id'?'number':type==='email'?'email':type==='url'?'url':type==='password'?'password':'text';if(type==='id'){input.min=1;input.step=1;}else input.maxLength=limit;if(type==='password'){input.minLength=12;input.autocomplete='new-password';}}
      input.name=key;input.required=required&&!(row&&type==='password');if(row&&type!=='password')input.value=row[key]??'';
      if(row&&resource.value==='people'&&row.user_id!==null&&key==='user_id')input.disabled=true;
      label.append(input);form.append(label);
    }
    const save=tx('button','admin.save'),cancel=tx('button','common.cancel');cancel.type='button';cancel.className='secondary';cancel.onclick=()=>{editor.hidden=true;};form.append(save,cancel);form.querySelector('input,select,textarea')?.focus();
  }
  form.onsubmit=async event=>{
    event.preventDefault();const button=form.querySelector('button');button.disabled=true;
    try{const data={};for(const [key,value] of new FormData(form)){const [type,,required]=specs[resource.value][key];if(type==='password'&&!value&&currentKey!==null)continue;data[key]=type==='id'?(value?Number(value):null):(!required&&!value?null:value);}
      await api(`/api/admin/${resource.value}${currentKey!==null?'/'+encodeURIComponent(currentKey):''}`,currentKey===null?'POST':'PATCH',data);editor.hidden=true;await load();I18N.text(notice,'admin.saved');
    }catch(error){I18N.error(notice,error);}finally{button.disabled=false;}
  };
  resource.onchange=()=>{page=1;editor.hidden=true;load();};document.querySelector('#new-record').onclick=()=>editRecord();
  document.querySelector('#admin-prev').onclick=()=>{page--;load();};document.querySelector('#admin-next').onclick=()=>{page++;load();};
  document.querySelector('#admin-logout').onclick=async()=>{try{await api('/api/auth/logout','POST');location.assign('/');}catch(error){I18N.error(notice,error);}};
  (async()=>{try{const session=await api('/api/auth/session');csrfToken=session.csrf_token;specs=(await api('/api/admin')).resources;for(const key of Object.keys(specs)){const option=named(make('option'),'table.',key);option.value=key;resource.append(option);}await load();}catch(error){I18N.error(notice,error);}})();
})();
