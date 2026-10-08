const assert=require('node:assert/strict');
const base=process.env.TEST_API_URL || 'http://127.0.0.1:8080';
const token=process.env.ADS_WRITE_TOKEN;
let checks=0;
async function req(path,status,method='GET',body,headers={}){
 const r=await fetch(base+path,{method,headers:{Authorization:`Bearer ${token}`,...headers,...(body!==undefined?{'Content-Type':'application/json'}:{})},body:body===undefined?undefined:JSON.stringify(body)});
 const text=await r.text(); assert.equal(r.status,status,`${method} ${path}: ${text}`); checks++; return text?JSON.parse(text):null;
}
(async()=>{
 const ads=await req('/api/ads',200); assert.equal(ads.length,20);
 const detail=await req('/api/ads/1',200); assert.ok(detail.company&&detail.description&&detail.contract_type);
 await req('/api/ads/999999',404); await req('/api/ads/abc',404); await req('/api/ads',405,'DELETE');
 await req('/api/ads',401,'POST',{}, {Authorization:'Bearer incorrect'});
 await req('/api/ads',422,'POST',{});
 await req('/api/ads',422,'POST',{title:'x',short_description:'x',description:'x',location:'Paris',company_id:99999});
 const valid={title:'Smoke test',short_description:'Test',description:'Test description',location:'Nantes',company_id:1,category_id:1};
 const ad=await req('/api/ads',201,'POST',valid);
 try {
  await req(`/api/ads/${ad.id}`,200);
  const updated=await req(`/api/ads/${ad.id}`,200,'PATCH',{salary:'€35,000/year',category_id:null}); assert.equal(updated.salary,'€35,000/year'); assert.equal(updated.category,null);
  const replaced=await req(`/api/ads/${ad.id}`,200,'PUT',{...valid,title:'Replacement'}); assert.equal(replaced.salary,null);
  await req(`/api/ads/${ad.id}`,422,'PATCH',{source:'LOCAL'});
 } finally {await req(`/api/ads/${ad.id}`,204,'DELETE');}
 await req(`/api/ads/${ad.id}`,404);
 const bad=await fetch(base+'/api/ads',{method:'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:'{'}); assert.equal(bad.status,400);checks++;
 const media=await fetch(base+'/api/ads',{method:'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'text/plain'},body:'hello'});assert.equal(media.status,415);checks++;
 const huge=await fetch(base+'/api/ads',{method:'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:'x'.repeat(65537)});assert.equal(huge.status,413);checks++;
 for(const path of ['/.env','/database/schema.sql','/api/config/database.php']) {const r=await fetch(base+path); assert.equal(r.status,404);checks++;}
 const index=await fetch(base+'/');assert.equal(index.status,200); assert.ok((await index.text()).includes('AlternaTech'));checks++;
 console.log(`PASS ${checks} HTTP checks; 20 seed offers; full CRUD; validation; protected writes; private files inaccessible`);
})().catch(e=>{console.error(e);process.exitCode=1});
