"use strict";
const jobs = document.querySelector('#jobs'), details = document.querySelector('#details'), status = document.querySelector('#status'), retry = document.querySelector('#retry');
let controller;
let catalogueController;
let boardPage=1, boardFilters={}, selectedId=null;
function el(tag, text, className) {
  const node = document.createElement(tag); node.textContent = text ?? ''; if (className) node.className = className; return node;
}
// Interface text goes through I18N (i18n.js); API values are passed to el() untouched.
function tx(tag, key, params, className) { return I18N.text(el(tag, null, className), key, params); }
// The card whose details are shown is highlighted and its Learn More button marked current.
function markSelected() {
  for (const card of jobs.querySelectorAll('.card')) {
    const on = selectedId !== null && card.dataset.adId === String(selectedId), more = card.querySelector('.card-more');
    card.classList.toggle('is-selected', on); if (on) more?.setAttribute('aria-current','true'); else more?.removeAttribute('aria-current');
  }
}
function setState(state, key, params) { status.dataset.state = state; I18N.text(status, key, params); }
// Only values the API actually provides are listed.
function fact(list, key, value) { if (value !== null && value !== undefined && value !== '') list.append(tx('dt', key), el('dd', value)); }
async function get(url, signal) {
  let response;
  // A network failure gets the same readable message as an HTTP error; aborts propagate unchanged.
  try { response = await fetch(url, {signal, headers:{Accept:'application/json'}}); }
  catch (error) { if (error.name === 'AbortError') throw error; throw I18N.translated('board.errorLoad'); }
  if (!response.ok) throw I18N.translated(response.status === 404 ? 'board.errorGone' : 'board.errorLoad');
  return response.json();
}
async function showDetails(id) {
  controller?.abort(); controller = new AbortController(); const current = controller;
  selectedId = id; markSelected();
  details.replaceChildren(tx('p','details.loading',null,'details-loading')); details.setAttribute('aria-busy','true');
  try {
    const ad = await get(`/api/ads/${id}`, current.signal);
    // Shown on narrow screens only, where the details follow the list.
    const back = tx('button','details.back',null,'secondary details-back'); back.type = 'button';
    back.onclick = () => (jobs.querySelector('.card.is-selected .card-more') || jobs.querySelector('.card-more'))?.focus();
    details.replaceChildren(back);
    if(ad.category) details.append(el('p',ad.category,'eyebrow'));
    details.append(el('h2',ad.title),el('p',`${ad.company} · ${ad.location}`,'meta'));
    const facts = el('dl',null,'facts'); fact(facts,'facts.contract',ad.contract_type); fact(facts,'facts.salary',ad.salary); fact(facts,'facts.workingTime',ad.working_time);
    if(facts.children.length) details.append(facts);
    details.append(el('p',ad.description,'description'));
    if(ad.source_url) {
      try { const url = new URL(ad.source_url); if(['https:','http:'].includes(url.protocol)) { const a=tx('a','details.source'); a.href=url.href; a.target='_blank'; a.rel='noopener noreferrer'; details.append(a); } } catch {}
    }
    // Charles can subscribe to this event without coupling Apply to this renderer.
    document.dispatchEvent(new CustomEvent('alternatech:ad-selected',{detail:{advertisement:ad}}));
    details.focus();
    return ad;
  } catch(error) { if(error.name === 'AbortError') return; details.replaceChildren(I18N.error(el('p'),error)); const button=tx('button','details.retry'); button.onclick=()=>showDetails(id); details.append(button); }
  finally { if (controller === current) details.removeAttribute('aria-busy'); }
}
async function load() {
  catalogueController?.abort(); catalogueController=new AbortController(); const activeCatalogue=catalogueController;
  retry.hidden=true; setState('loading','board.loading'); jobs.replaceChildren(); jobs.setAttribute('aria-busy','true');
  try {
    const params=new URLSearchParams({...boardFilters,page:boardPage,per_page:10});
    const result = await get('/api/catalog/ads?'+params,activeCatalogue.signal);
    if(activeCatalogue!==catalogueController) return;
    const ads=result.items;
    if (result.total) setState('ready','board.count',{count:Number(result.total)}); else setState('empty','board.empty');
    for(const ad of ads) {
      const card=el('article',null,'card'); card.dataset.adId=ad.id;
      if(ad.contract_type) card.append(el('p',ad.contract_type,'badge'));
      card.append(el('h2',ad.title),el('p',`${ad.company} · ${ad.location}`,'meta'),el('p',ad.short_description));
      const button=tx('button','card.more',null,'secondary card-more'); I18N.attr(button,'aria-label','card.moreLabel',{title:ad.title}); button.onclick=()=>showDetails(ad.id);
      const apply=tx('button','card.apply');I18N.attr(apply,'aria-label','card.applyLabel',{title:ad.title});apply.onclick=async()=>{const loaded=await showDetails(ad.id);if(!loaded)return;document.dispatchEvent(new CustomEvent('alternatech:apply',{detail:{advertisement:ad}}));};
      const actions=el('div',null,'actions');actions.append(button,apply);card.append(actions); jobs.append(card);
    }
    markSelected(); renderPagination(result);
  } catch(error) {if(error.name==='AbortError'||activeCatalogue!==catalogueController)return; status.dataset.state='error'; I18N.error(status,error); retry.hidden=false; document.querySelector('#board-pagination')?.remove();}
  finally { if(activeCatalogue===catalogueController)jobs.removeAttribute('aria-busy'); }
}
retry.onclick=load; load();
function renderPagination(result){
  document.querySelector('#board-pagination')?.remove();
  if(result.pages<=1) return;
  const nav=el('nav',null,'pagination');nav.id='board-pagination';I18N.attr(nav,'aria-label','pager.label');
  const prev=tx('button','pager.prev'),next=tx('button','pager.next');prev.disabled=result.page<=1;next.disabled=result.page>=result.pages;
  prev.onclick=()=>{boardPage--;load();};next.onclick=()=>{boardPage++;load();};nav.append(prev,tx('span','pager.status',{page:result.page,pages:result.pages}),next);jobs.after(nav);
}
document.addEventListener('alternatech:filters',event=>{boardFilters=event.detail;boardPage=1;load();});
