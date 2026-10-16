'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const guard=require('./support/http-guard.cjs'),base=guard.installHttpGuard();
const f=JSON.parse(fs.readFileSync(__dirname+'/.runtime-fixtures.json','utf8'));guard.verifyFixtures(f);
let checks=0;const check=(v,m)=>{assert.ok(v,m);checks++};
class Client {
 cookie='';token='';user=null;
 async req(path,status=200,method='GET',body,headers={}){
  const r=await fetch(base+path,{method,headers:{Cookie:this.cookie,Accept:'application/json',...(this.token?{'X-CSRF-Token':this.token}:{}),...(body!==undefined?{'Content-Type':'application/json'}:{}),...headers},body:body===undefined?undefined:JSON.stringify(body)});
  for(const c of r.headers.getSetCookie())if(c.startsWith('alternatech_login='))this.cookie=c.split(';')[0];
  const text=await r.text();assert.equal(r.status,status,`${method} ${path}: ${text}`);checks++;
  let data;try{data=JSON.parse(text)}catch{data=text}if(data?.csrf_token)this.token=data.csrf_token;if(data?.user)this.user=data.user;return data;
 }
 async register(label){await this.req('/api/auth/register',201,'POST',{firstname:'Onboarding',lastname:label,email:f.prefix+'-'+label+'@example.invalid',password:f.password});}
 async state(){return this.req('/api/company-access');}
}
(async()=>{
 const anon=new Client(),admin=new Client(),founder=new Client(),joiner=new Client(),other=new Client();
 const users=[],companies=[],profileIds=[];
 await admin.req('/api/auth/login',200,'POST',{email:f.admin.email,password:f.password});
 try{
  await anon.req('/api/company-access',401);await anon.req('/recruit.php',303);
  for(const [c,label]of [[founder,'founder'],[joiner,'joiner'],[other,'other-founder']]){await c.register(label);users.push(c.user.id);profileIds.push(c.user.person_id);}
  await founder.req('/recruit.php');
  const payload={name:f.prefix+' Company One',email:f.prefix+'-company@example.invalid',registration_number:'SYNTHETIC-ONLY',website:'https://example.invalid',evidence:'Synthetic owner verification evidence'};
  await founder.req('/api/company-access/proposals',403,'POST',payload,{'X-CSRF-Token':'wrong'});
  await founder.req('/api/company-access/proposals',422,'POST',{...payload,role:'admin'});
  await founder.req('/api/company-access/proposals',422,'POST',{...payload,website:'javascript:alert(1)'});
  const proposal=await founder.req('/api/company-access/proposals',201,'POST',payload);
  await founder.req('/api/company-access/proposals',409,'POST',payload);
  check((await founder.state()).companies.length===0,'Pending creation grants no recruiter access');
  await founder.req(`/api/company-access/proposals/${proposal.id}/decision`,403,'POST',{decision:'approve'});
  await other.req(`/api/company-access/proposals/${proposal.id}/decision`,403,'POST',{decision:'reject'});
  check((await admin.state()).pending_proposals.some(x=>x.id===proposal.id),'Admin can review evidence');
  const approved=await admin.req(`/api/company-access/proposals/${proposal.id}/decision`,200,'POST',{decision:'approve'});companies.push(approved.company_id);
  const company=approved.company_id;
  await admin.req(`/api/company-access/proposals/${proposal.id}/decision`,409,'POST',{decision:'approve'});
  check((await founder.state()).companies.some(c=>c.id===company&&Number(c.is_manager)===1),'Approved founder becomes manager');
  check((await founder.req('/api/companies/mine')).some(c=>Number(c.id)===company),'Existing recruiter handlers recognize manager membership');
  const second=await other.req('/api/company-access/proposals',201,'POST',{...payload,name:f.prefix+' Company Two'});
  const secondApproved=await admin.req(`/api/company-access/proposals/${second.id}/decision`,200,'POST',{decision:'approve'});companies.push(secondApproved.company_id);
  await founder.req('/api/company-access/search?q=%25');await founder.req('/api/company-access/search?q=a',422);
  check((await joiner.req('/api/company-access/search?q='+encodeURIComponent(f.prefix))).companies.some(c=>c.id===company),'Company is searchable');
  await joiner.req(`/api/company-access/companies/${company}/join`,422,'POST',{message:'',role:'manager'});
  await joiner.req(`/api/company-access/companies/${company}/join`,200,'POST',{message:'Synthetic colleague'});
  await joiner.req(`/api/company-access/companies/${company}/join`,409,'POST',{message:'Duplicate'});
  const pending=(await joiner.state()).requests.find(r=>r.company_id===company);
  check((await joiner.state()).companies.length===0,'Pending join grants no recruiter access');
  check((await other.state()).inbox.every(r=>r.id!==pending.id),'Other company manager cannot see private join request');
  await other.req(`/api/company-access/joins/${pending.id}/decision`,403,'POST',{decision:'approve'});
  await joiner.req(`/api/company-access/joins/${pending.id}/decision`,403,'POST',{decision:'approve'});
  await founder.req(`/api/company-access/joins/${pending.id}/decision`,200,'POST',{decision:'reject'});
  check((await joiner.state()).companies.length===0,'Rejection grants no membership');
  await joiner.req(`/api/company-access/companies/${company}/join`,200,'POST',{message:'Updated supporting information'});
  await founder.req(`/api/company-access/joins/${pending.id}/decision`,200,'POST',{decision:'approve'});
  await founder.req(`/api/company-access/joins/${pending.id}/decision`,409,'POST',{decision:'approve'});
  check((await joiner.req('/api/companies/mine')).some(c=>Number(c.id)===company),'Approved colleague can use recruiter APIs');
  check((await joiner.state()).inbox.length===0,'Ordinary recruiter cannot manage requests');
  await joiner.req(`/api/company-access/companies/${company}/manager`,403,'POST',{email:joiner.user.email});
  await founder.req(`/api/company-access/companies/${company}/manager`,403,'POST',{email:joiner.user.email});
  await other.req(`/api/company-access/companies/${company}/members/${joiner.user.person_id}`,403,'DELETE');
  await founder.req(`/api/company-access/companies/${company}/members/${founder.user.person_id}`,409,'DELETE');
  const ad={title:'New recruiter opportunity',short_description:'Synthetic summary',description:'Synthetic description',location:'Paris',working_time:'35 hours',company_id:company,category_id:1};
  const created=await joiner.req('/api/ads',201,'POST',ad);
  await founder.req(`/api/company-access/companies/${company}/members/${joiner.user.person_id}`,204,'DELETE');
  await joiner.req(`/api/ads/${created.id}`,403,'PATCH',{title:'Revoked edit'});
  await joiner.req('/api/ads',403,'POST',ad);
  await founder.req(`/api/ads/${created.id}`,204,'DELETE');
  await admin.req(`/api/company-access/companies/${company}/manager`,404,'POST',{email:'missing@example.invalid'});
  await admin.req(`/api/company-access/companies/${company}/manager`,200,'POST',{email:joiner.user.email});
  check((await founder.state()).members.length===0,'Former manager immediately loses management view');
  await founder.req(`/api/company-access/companies/${company}/members/${joiner.user.person_id}`,403,'DELETE');
  check((await joiner.state()).companies.some(c=>c.id===company&&Number(c.is_manager)===1),'Admin can transfer company responsibility');
  // Existing company bootstrap, without claiming it through a public matching email.
  const legacy=await admin.req('/api/admin/companies',201,'POST',{name:f.prefix+' Existing Company'});companies.push(Number(legacy.key));
  await other.req(`/api/company-access/companies/${legacy.key}/join`,200,'POST',{message:'Existing company colleague'});
  check((await other.state()).companies.every(c=>c.id!==Number(legacy.key)),'An unclaimed company does not auto-approve access');
  await admin.req(`/api/company-access/companies/${legacy.key}/manager`,200,'POST',{email:founder.user.email});
  const legacyJoin=(await other.state()).requests.find(r=>r.company_id===Number(legacy.key));
  await founder.req(`/api/company-access/joins/${legacyJoin.id}/decision`,200,'POST',{decision:'approve'});
  await joiner.req('/api/auth/logout',200,'POST');await joiner.req('/api/company-access',401);
 }finally{
  for(const id of companies.reverse())await admin.req('/api/admin/companies/'+id,204,'DELETE');
  for(const id of users)await admin.req('/api/admin/users/'+id,204,'DELETE');
  for(const id of profileIds)await admin.req('/api/admin/people/'+id,204,'DELETE');
 }
 console.log(`PASS ${checks} company onboarding/authorization checks: approval, rejection, reapply, responsible bootstrap/transfer, cross-company privacy, role escalation, CSRF, revoked ad writes and cleanup`);
})().catch(e=>{console.error(e);process.exitCode=1});
