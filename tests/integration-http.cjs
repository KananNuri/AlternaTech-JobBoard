const assert=require('node:assert/strict');
const fs=require('node:fs');
const base=process.env.TEST_API_URL||'http://127.0.0.1:8086';
const f=JSON.parse(fs.readFileSync(__dirname+'/.runtime-fixtures.json','utf8'));
let checks=0;
class Client {
  cookie=''; token='';
  async req(path,status=200,method='GET',body,extra={}){
    const r=await fetch(base+path,{method,redirect:'manual',headers:{Cookie:this.cookie,Accept:'application/json',...(body!==undefined?{'Content-Type':'application/json'}:{}),...(this.token?{'X-CSRF-Token':this.token}:{}),...extra},body:body===undefined?undefined:JSON.stringify(body)});
    for(const cookie of r.headers.getSetCookie()){const entry=cookie.split(';')[0];if(entry.startsWith('alternatech_login='))this.cookie=entry;}
    const text=await r.text();assert.equal(r.status,status,`${method} ${path}: ${text}`);checks++;
    let data;try{data=text?JSON.parse(text):null;}catch{data=text;}
    if(data?.csrf_token)this.token=data.csrf_token;return data;
  }
  async login(label){return this.req('/api/auth/login',200,'POST',{email:f[label].email,password:f.password});}
}
(async()=>{
  const anonymous=new Client(),admin=new Client(),recruiter=new Client(),outsider=new Client(),candidate=new Client();
  for(const path of ['/.env','/database/schema.sql','/tools/migrate.php','/api/config/database.php','/api/integration/common.php'])await anonymous.req(path,404);
  const home=await anonymous.req('/');assert.match(home,/integration.js/);
  await anonymous.req('/admin.php',303);await anonymous.req('/api/admin',401);
  const catalog=await anonymous.req('/api/catalog/ads?per_page=3&page=2');assert.equal(catalog.items.length,3);assert.equal(catalog.total,20);
  await anonymous.req('/api/catalog/ads?per_page=101',422);
  const filtered=await anonymous.req('/api/catalog/ads?q=PHP&location=Nantes');assert.ok(filtered.items.every(x=>x.title.includes('PHP')&&x.location==='Nantes'));
  await anonymous.req('/api/companies/999999/ads',404);const company=await anonymous.req('/api/companies/1/ads');assert.ok(company.items.every(x=>Number(x.company_id)===1));
  await anonymous.req('/api/auth/register',422,'POST',{firstname:'Bad',lastname:'Role',email:f.prefix+'-role@example.invalid',password:f.password,role:'admin'});
  const registration=await anonymous.req('/api/auth/register',201,'POST',{firstname:'New',lastname:'Candidate',email:f.prefix+'-new@example.invalid',phone:'0102030405',password:f.password});
  assert.equal(registration.user.role,'user');f.registered=registration.user.id;
  await anonymous.req('/api/account',200,'PATCH',{firstname:'Updated',phone:'0600000000'});
  await anonymous.req('/api/account',403,'PATCH',{email:f.prefix+'-changed@example.invalid'});
  await anonymous.req('/api/account',200,'PATCH',{email:f.prefix+'-changed@example.invalid',current_password:f.password});
  await anonymous.req('/api/auth/logout',200,'POST');await anonymous.req('/api/auth/session');
  const guest=new Client();await guest.req('/api/auth/csrf');
  await guest.req('/api/ads/1/applications',422,'POST',{advertisement_id:2,firstname:'Test',lastname:'Guest',email:f.prefix+'-guest@example.invalid'});
  const application=await guest.req('/api/ads/1/applications',201,'POST',{firstname:'Test',lastname:'Guest',email:f.prefix+'-guest@example.invalid',phone:'0101010101',message:'Guest application'});f.guestPerson=application.person_id;
  await candidate.login('candidate');const session=await candidate.req('/api/auth/session');assert.equal(session.user.person_id,f.candidate.person_id);assert.notEqual(session.user.id,session.user.person_id);
  const googleSession=await candidate.req('/api/auth/google/session');assert.equal(googleSession.user.id,session.user.id);
  const own=await candidate.req('/api/ads/1/applications',201,'POST',{message:'Account application'});
  assert.equal(own.person_id,f.candidate.person_id);await candidate.req('/api/ads/1/applications',409,'POST',{message:'Duplicate'});
  const mine=await candidate.req('/api/applications/mine');assert.ok(mine.applications.some(x=>Number(x.id)===own.id));
  await candidate.req('/api/ads/1/applications',403,'POST',{message:'Bad CSRF'},{'X-CSRF-Token':'wrong'});
  await candidate.req('/api/admin',403);await candidate.req('/admin.php',403);
  await recruiter.login('recruiter');await outsider.login('outsider');
  const companies=await recruiter.req('/api/companies/mine');assert.equal(Number(companies[0].id),1);
  const received=await recruiter.req('/api/companies/mine/applications');assert.ok(received.applications.some(x=>Number(x.application_id)===own.id));
  const contacts=await recruiter.req('/api/recruiter/applications?per_page=1');assert.equal(contacts.applications.length,1);assert.ok(contacts.applications[0].email);assert.ok(contacts.total>=2);
  const outsiderContacts=await outsider.req('/api/recruiter/applications');assert.equal(outsiderContacts.total,0);
  await outsider.req('/api/companies/mine/applications',404,'PATCH',{application_id:own.id,status:'accepted'});
  await recruiter.req('/api/companies/mine/applications',200,'PATCH',{application_id:own.id,status:'reviewed'});
  assert.equal((await candidate.req('/api/applications/mine')).applications.find(x=>Number(x.id)===own.id).status,'reviewed');
  const valid={title:'Integration ad',short_description:'Test summary',description:'Test description',location:'Nantes',working_time:'35 hours',company_id:1,category_id:1};
  await outsider.req('/api/ads',403,'POST',valid);await recruiter.req('/api/ads',403,'POST',{...valid,company_id:2});
  const ad=await recruiter.req('/api/ads',201,'POST',valid);await outsider.req(`/api/ads/${ad.id}`,403,'PATCH',{title:'Intrusion'});
  await recruiter.req(`/api/ads/${ad.id}`,200,'PATCH',{title:'Recruiter edit'});await recruiter.req(`/api/ads/${ad.id}`,403,'PATCH',{company_id:2});
  await recruiter.req(`/api/ads/${ad.id}`,200,'PUT',{...valid,title:'Replacement'});await recruiter.req(`/api/ads/${ad.id}`,204,'DELETE');
  await admin.login('admin');await admin.req('/admin.php');const resources=(await admin.req('/api/admin')).resources;assert.equal(Object.keys(resources).length,8);
  for(const table of Object.keys(resources)){const data=await admin.req(`/api/admin/${table}?per_page=2`);assert.ok(data.items.length<=2);assert.ok(!JSON.stringify(data).includes('password_hash'));}
  await admin.req('/api/admin/users?per_page=1000',422);await admin.req('/api/admin/unknown',404);
  const records={
    companies:{name:'CRUD Company'},categories:{name:f.prefix+'-category'},
    users:{firstname:'CRUD',lastname:'User',email:f.prefix+'-crud@example.invalid',password:f.password,role:'user'},
    people:{firstname:'CRUD',lastname:'Guest',email:f.prefix+'-crud-guest@example.invalid'},
    advertisements:{company_id:1,title:'Admin ad',short_description:'Short',description:'Detail',location:'Paris'},
    applications:{advertisement_id:2,person_id:f.candidate.person_id,message:'Admin fixture',status:'pending'},
    company_members:{company_id:2,person_id:f.outsider.person_id,role:'recruiter'},
    google_identities:{google_sub:f.prefix+'-synthetic-google',user_id:f.outsider.id}
  };
  for(const [table,body] of Object.entries(records)){
    const created=await admin.req(`/api/admin/${table}`,201,'POST',body),path=`/api/admin/${table}/${encodeURIComponent(created.key)}`;
    const read=await admin.req(path);assert.ok(read);assert.ok(!JSON.stringify(read).includes('password_hash'));
    const update=table==='users'?{firstname:'Updated'}:table==='company_members'?{role:'recruiter'}:table==='google_identities'?{user_id:f.outsider.id}:table==='applications'?{status:'accepted'}:table==='people'?{firstname:'Updated'}:table==='advertisements'?{title:'Updated'}:{name:'Updated '+f.prefix};
    await admin.req(path,200,'PATCH',update);await admin.req(path,200,'PUT',{...body,...update});await admin.req(path,204,'DELETE');await admin.req(path,404);
  }
  await admin.req(`/api/admin/users/${f.admin.id}`,409,'DELETE');await admin.req(`/api/admin/users/${f.admin.id}`,409,'PATCH',{role:'user'});
  await admin.req('/api/admin/companies',403,'POST',{name:'Missing CSRF'},{'X-CSRF-Token':'wrong'});
  await candidate.req('/api/auth/google/logout',200,'POST');assert.equal((await candidate.req('/api/auth/session')).user,null);
  // Cleanup only records created by these tests; fixtures never target a live database.
  for(const label of ['recruiter','outsider','candidate'])await admin.req(`/api/admin/users/${f[label].id}`,204,'DELETE');
  await admin.req(`/api/admin/users/${f.registered}`,204,'DELETE');
  // ON DELETE SET NULL retains applicant history; remove the now-unlinked profiles explicitly.
  for(const label of ['recruiter','outsider','candidate'])await admin.req(`/api/admin/people/${f[label].person_id}`,204,'DELETE');
  const people=(await admin.req('/api/admin/people?per_page=100')).items;
  for(const p of people)if(p.email.startsWith(f.prefix)&&Number(p.id)!==f.admin.person_id)await admin.req(`/api/admin/people/${p.id}`,204,'DELETE');
  console.log(`PASS ${checks} integration HTTP checks: canonical IDs, registration/account, guest/account Apply, duplicate/CSRF/tenant checks, shared Google session, recruiter CRUD, eight-table admin CRUD and pagination`);
})().catch(error=>{console.error(error);process.exitCode=1;});
