'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs');
const guard=require('./support/http-guard.cjs'),base=guard.installHttpGuard();
const f=JSON.parse(fs.readFileSync(__dirname+'/.runtime-fixtures.json','utf8'));guard.verifyFixtures(f);
let checks=0;
async function request(path,{cookie='',token='',method='GET',body,status=200}={}) {
  const r=await fetch(base+path,{method,headers:{Cookie:cookie,Accept:'application/json',...(body?{'Content-Type':'application/json'}:{}),...(token?{'X-CSRF-Token':token}:{})},body:body?JSON.stringify(body):undefined});
  assert.equal(r.status,status,`${method} ${path}`);checks++;
  const cookies=r.headers.getSetCookie(),sessionCookie=cookies.find(c=>c.startsWith('alternatech_login='))?.split(';')[0];
  const text=await r.text();let data;try{data=JSON.parse(text)}catch{data=text}
  return {data,cookies,cookie:sessionCookie||cookie};
}
(async()=>{
  for(const label of ['candidate','admin']) {
    const guest=await request('/api/auth/csrf');
    await request('/api/auth/login',{cookie:guest.cookie,method:'POST',body:{email:f[label].email,password:'incorrect-password'},status:401});
    const login=await request('/api/auth/login',{cookie:guest.cookie,method:'POST',body:{email:f[label].email,password:f.password}});
    assert.notEqual(login.cookie,guest.cookie,'Login rotates session ID');checks++;
    assert.equal(login.data.redirect,label==='admin'?'/admin.php':'/');checks++;
    const retired=await request('/api/auth/session',{cookie:guest.cookie});
    assert.equal(retired.data.user,null,'Old session cannot authenticate');checks++;
    assert.equal(retired.cookies.length,0,'Late old-session response cannot overwrite login cookie');checks++;
    const active=await request('/api/auth/session',{cookie:login.cookie});
    assert.equal(active.data.user.id,f[label].id);checks++;
    await request('/api/auth/logout',{cookie:login.cookie,method:'POST',token:guest.data.csrf_token,status:403});
    await request('/api/admin',{cookie:login.cookie,status:label==='admin'?200:403});
    await request('/api/auth/logout',{cookie:login.cookie,method:'POST',token:login.data.csrf_token});
    assert.equal((await request('/api/auth/session',{cookie:login.cookie})).data.user,null);checks++;
  }
  console.log(`PASS ${checks} authentication/session checks: invalid login, role redirects, session rotation, retired-cookie race, CSRF, permissions, logout`);
})().catch(e=>{console.error(e);process.exitCode=1});
