"use strict";
const jobs = document.querySelector('#jobs'), details = document.querySelector('#details'), status = document.querySelector('#status'), retry = document.querySelector('#retry');
let controller;
function el(tag, text, className) {
  const node = document.createElement(tag); node.textContent = text ?? ''; if (className) node.className = className; return node;
}
async function get(url, signal) {
  const response = await fetch(url, {signal, headers:{Accept:'application/json'}});
  if (!response.ok) throw new Error(response.status === 404 ? 'This opportunity is no longer available.' : 'Unable to load opportunities. Please try again.');
  return response.json();
}
async function showDetails(id) {
  controller?.abort(); controller = new AbortController(); const current = controller;
  details.replaceChildren(el('p','Loading details…')); details.setAttribute('aria-busy','true');
  try {
    const ad = await get(`/api/ads/${id}`, current.signal);
    details.replaceChildren(el('p',ad.category || 'Technology','eyebrow'),el('h2',ad.title),el('p',`${ad.company} · ${ad.location}`),el('p',ad.contract_type || 'Contract to be confirmed','badge'),el('p',ad.description,'description'));
    if(ad.salary) details.append(el('p',`Salary: ${ad.salary}`));
    if(ad.working_time) details.append(el('p',`Working time: ${ad.working_time}`));
    if(ad.source_url) {
      try { const url = new URL(ad.source_url); if(['https:','http:'].includes(url.protocol)) { const a=el('a','View original listing'); a.href=url.href; a.target='_blank'; a.rel='noopener noreferrer'; details.append(a); } } catch {}
    }
    // Charles can subscribe to this event without coupling Apply to this renderer.
    document.dispatchEvent(new CustomEvent('alternatech:ad-selected',{detail:{advertisement:ad}}));
    details.focus();
  } catch(error) { if(error.name === 'AbortError') return; details.replaceChildren(el('p',error.message)); const button=el('button','Retry details'); button.onclick=()=>showDetails(id); details.append(button); }
  finally { if (controller === current) details.removeAttribute('aria-busy'); }
}
async function load() {
  retry.hidden=true; status.textContent='Loading opportunities…'; jobs.replaceChildren();
  try {
    const ads = await get('/api/ads');
    status.textContent=ads.length ? `${ads.length} opportunities to explore` : 'No opportunities available yet.';
    for(const ad of ads) {
      const card=el('article',null,'card'); card.append(el('p',ad.contract_type || 'Opportunity','badge'),el('h2',ad.title),el('p',`${ad.company} · ${ad.location}`,'meta'),el('p',ad.short_description));
      const button=el('button','Learn More'); button.setAttribute('aria-label',`Learn More: ${ad.title}`); button.onclick=()=>showDetails(ad.id); card.append(button); jobs.append(card);
    }
  } catch(error) {status.textContent=error.message; retry.hidden=false;}
}
retry.onclick=load; load();
