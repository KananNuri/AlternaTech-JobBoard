const assert=require('node:assert/strict'),{spawnSync,spawn}=require('node:child_process'),net=require('node:net'),crypto=require('node:crypto');
if(process.env.INTEGRATION_TEST_DB!=='1')throw Error('Disposable test DB required');
const base=process.env.TEST_API_URL||'http://127.0.0.1:8098',prefix='security-'+crypto.randomBytes(5).toString('hex'),password='Test-only-long-password',emails=[];
let checks=0;
function fixture(...args){const r=spawnSync('php',[__dirname+'/security-fixtures.php',...args],{encoding:'utf8'});assert.equal(r.status,0,r.stderr);return JSON.parse(r.stdout);}
class Client {
  cookie='';token='';
  async request(path,status=200,body,headers={},method=body===undefined?'GET':'POST'){const r=await fetch(base+path,{redirect:'manual',method,headers:{Cookie:this.cookie,...(body===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':this.token}),...headers},body:body===undefined?undefined:JSON.stringify(body)});for(const c of r.headers.getSetCookie())if(c.startsWith('alternatech_login='))this.cookie=c.split(';')[0];const text=await r.text();assert.equal(r.status,status,`${path}: ${text}`);checks++;let data;try{data=JSON.parse(text);}catch{data=text;}if(data?.csrf_token)this.token=data.csrf_token;return data;}
  async register(label){const email=prefix+'-'+label+'@example.invalid';emails.push(email);await this.request('/api/auth/register',201,{firstname:'Synthetic',lastname:'Test',email,password});return email;}
}
async function delivery(){
  let delivered=0;const connections=new Set();
  const server=net.createServer(socket=>{connections.add(socket);socket.on('close',()=>connections.delete(socket));socket.write('220 localhost synthetic SMTP\r\n');let buffer='',data=false;
    socket.on('data',chunk=>{buffer+=chunk.toString();for(;;){const i=buffer.indexOf('\r\n');if(i<0)break;const line=buffer.slice(0,i);buffer=buffer.slice(i+2);if(data){if(line==='.'){data=false;delivered++;socket.write('250 queued\r\n');}continue;}if(/^EHLO|^HELO/.test(line))socket.write('250 localhost\r\n');else if(/^DATA/.test(line)){data=true;socket.write('354 send data\r\n');}else if(/^QUIT/.test(line)){socket.end('221 bye\r\n');}else socket.write('250 ok\r\n');}});
  });
  await new Promise((resolve,reject)=>{server.once('error',reject);server.listen(Number(process.env.SMTP_PORT||25218),'127.0.0.1',resolve);});
  try{await new Promise((resolve,reject)=>{const child=spawn('php',[__dirname+'/../tools/send-mail.php'],{stdio:['ignore','pipe','pipe']});let output='';child.stdout.on('data',x=>output+=x);child.stderr.on('data',()=>{});child.on('error',reject);child.on('exit',code=>code===0?resolve():reject(Error('Mail worker failed')));});assert.ok(delivered>0);checks++;}finally{for(const s of connections)s.destroy();await new Promise(resolve=>server.close(resolve));}
}
(async()=>{
  fixture('clear-limits');
  const guest=new Client(),candidate=new Client(),other=new Client(),recruiter=new Client();
  await guest.request('/index.html',308);await guest.request('/index.php',308);await guest.request('/api/auth/csrf');
  const email=await candidate.register('candidate');const verify=fixture('token',email,'verify').token;assert.ok(verify);
  await guest.request('/api/auth/email/verify',403,{token:verify},{'X-CSRF-Token':'wrong'});
  await guest.request('/api/auth/password/reset',422,{token:verify,password});
  await guest.request('/api/auth/email/verify',200,{token:verify});await guest.request('/api/auth/email/verify',422,{token:verify});
  assert.ok((await candidate.request('/api/auth/session')).user.email_verified_at);
  await other.request('/api/auth/login',200,{email,password});
  await guest.request('/api/auth/password/request',200,{email});const expired=fixture('token',email,'reset').token;fixture('expire',email);
  await guest.request('/api/auth/password/reset',422,{token:expired,password});
  await guest.request('/api/auth/password/request',200,{email});const reset=fixture('token',email,'reset').token;
  await guest.request('/api/auth/password/reset',422,{token:expired,password});
  await guest.request('/api/auth/password/reset',422,{token:reset,password:'short'});
  const nextPassword=password+'-new';await guest.request('/api/auth/password/reset',200,{token:reset,password:nextPassword});
  await guest.request('/api/auth/password/reset',422,{token:reset,password:nextPassword});
  assert.equal((await candidate.request('/api/auth/session')).user,null);assert.equal((await other.request('/api/auth/session')).user,null);
  await candidate.request('/api/auth/login',401,{email,password});await candidate.request('/api/auth/login',200,{email,password:nextPassword});
  const missing=await guest.request('/api/auth/password/request',200,{email:prefix+'-missing@example.invalid'});assert.match(missing.message,/If this account exists/);
  const recruiterEmail=await recruiter.register('recruiter');fixture('recruiter',recruiterEmail);
  const listing=await recruiter.request('/api/recruiter/ads?company_id=1&per_page=1');assert.equal(listing.items.length,1);assert.ok(listing.total>1);
  assert.equal((await candidate.request('/api/recruiter/ads')).total,0);await recruiter.request('/api/recruiter/ads?per_page=101',422);
  await candidate.request('/api/ads/1/applications',201,{message:'Synthetic application'});
  assert.equal(fixture('mail-count',recruiterEmail,'New AlternaTech application').count,1);
  const apps=await recruiter.request('/api/recruiter/applications');const app=apps.applications.find(a=>a.email===email);assert.ok(app);
  const response=await fetch(base+'/api/companies/mine/applications',{method:'PATCH',headers:{Cookie:recruiter.cookie,'Content-Type':'application/json','X-CSRF-Token':recruiter.token},body:JSON.stringify({application_id:Number(app.application_id),status:'reviewed'})});assert.equal(response.status,200);checks++;
  assert.equal(fixture('mail-count',email,'AlternaTech application update').count,1);
  await candidate.request('/api/auth/google/link',403,{current_password:'wrong'});
  const link=await candidate.request('/api/auth/google/link',200,{current_password:nextPassword});assert.equal(new URL(link.url).hostname,'accounts.google.com');
  checks+=fixture('google-link',email,recruiterEmail).checks;
  await guest.request('/api/auth/password/request',200,{email});const abandonedReset=fixture('token',email,'reset').token;
  await other.request('/api/auth/login',200,{email,password:nextPassword});
  const changedPassword=nextPassword+'-changed';
  await candidate.request('/api/account',200,{password:changedPassword,current_password:nextPassword},{},'PATCH');
  assert.ok((await candidate.request('/api/auth/session')).user);assert.equal((await other.request('/api/auth/session')).user,null);
  await guest.request('/api/auth/password/reset',422,{token:abandonedReset,password});
  const changedEmail=prefix+'-changed@example.invalid';emails.push(changedEmail);
  await candidate.request('/api/account',200,{email:changedEmail,current_password:changedPassword},{},'PATCH');
  assert.equal((await candidate.request('/api/auth/session')).user.email_verified_at,null);
  const changedVerification=fixture('token',changedEmail,'verify').token;assert.ok(changedVerification);
  fixture('expire',changedEmail);await guest.request('/api/auth/email/verify',422,{token:changedVerification});
  await candidate.request('/api/auth/email/request',200,{});
  const replacementVerification=fixture('token',changedEmail,'verify').token;
  await guest.request('/api/auth/email/verify',422,{token:changedVerification});await guest.request('/api/auth/email/verify',200,{token:replacementVerification});
  fixture('clear-limits');
  for(let i=0;i<10;i++)await new Client().request('/api/auth/login',401,{email:prefix+'-unknown@example.invalid',password});
  await new Client().request('/api/auth/login',429,{email:prefix+'-unknown@example.invalid',password});
  await delivery();
  console.log(`PASS ${checks} security/HTTP/mail checks: redirects, single-use/expired tokens, CSRF, password reset and session revocation, Google link isolation, cookie-independent rate limits, recruiter pagination, notifications and local SMTP delivery`);
})().catch(error=>{console.error(error);process.exitCode=1;}).finally(()=>{for(const email of emails)fixture('cleanup',email);fixture('clear-limits');});
