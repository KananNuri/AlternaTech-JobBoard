const assert=require('node:assert/strict');
const base=process.env.GOOGLE_HTTP_TEST_URL || 'http://127.0.0.1:8080';let cookie='',n=0;
async function request(path,status,options={}){const r=await fetch(base+path,{redirect:'manual',...options,headers:{Cookie:cookie,...options.headers}});assert.equal(r.status,status,`${path}: ${await (r.status===status?Promise.resolve(''):r.text())}`);const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];n++;return r;}
async function start(){const r=await request('/api/auth/google/start',302);const u=new URL(r.headers.get('location'));assert.equal(u.origin,'https://accounts.google.com');assert.equal(u.searchParams.get('response_type'),'code');assert.equal(u.searchParams.get('code_challenge_method'),'S256');assert.equal(u.searchParams.get('scope'),'openid email profile');for(const field of ['state','nonce','code_challenge'])assert.ok(u.searchParams.get(field).length>=32);return u.searchParams.get('state');}
(async()=>{
 let r=await request('/api/auth/google/session',200);let data=await r.json();assert.equal(data.enabled,true);assert.equal(data.user,null);
 const state=await start();await request('/api/auth/google/callback?state=wrong&code=unused',400);await request(`/api/auth/google/callback?state=${state}&error=access_denied`,400);
 const next=await start();r=await request(`/api/auth/google/callback?state=${next}&error=access_denied`,303);assert.equal(r.headers.get('location'),'/?google_login=cancelled');
 await request(`/api/auth/google/callback?state=${next}&error=access_denied`,400);
 const third=await start();await request(`/api/auth/google/callback?state=${third}`,400);
 await request('/api/auth/google/logout',405);await request('/api/auth/google/logout',403,{method:'POST'});
 await request('/api/auth/google/callback',405,{method:'POST',body:'caller-supplied-token'});
 await request('/api/auth/google/unknown',404);await request('/api/login',404);
 r=await request('/api/ads',200);assert.equal((await r.json()).length,20);
 const cookiePolicy=(await fetch(base+'/api/auth/google/session')).headers.get('set-cookie');assert.match(cookiePolicy,/HttpOnly/i);assert.match(cookiePolicy,/SameSite=Lax/i);
 console.log(`PASS ${n} Google HTTP checks; provider redirect, PKCE, state/cancel/replay, method/CSRF rejection, separate routes; ads unaffected`);
})().catch(e=>{console.error(e);process.exitCode=1;});
