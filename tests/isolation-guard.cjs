'use strict';
const assert=require('node:assert/strict');
const {spawnSync}=require('node:child_process');
const {installHttpGuard,verifyFixtures}=require('./support/http-guard.cjs');
const scripts=['ads-http.cjs','ads-integrated-http.cjs','applications-http.cjs','google-http.cjs','integration-http.cjs','migration/migration-http.cjs'];
const env={...process.env};
for(const key of ['TEST_API_URL','GOOGLE_HTTP_TEST_URL','TEST_DB_NAME','TEST_RUN_ID','DB_NAME'])delete env[key];
for(const script of scripts){
  const result=spawnSync(process.execPath,[__dirname+'/'+script],{env,encoding:'utf8'});
  assert.notEqual(result.status,0); assert.match(result.stderr,/required; no default server/);
}
for(const script of ['integration-fixtures.php','google-db.php','charles-authorization-fixtures.php','migration/migration-db.php','maintenance-db.php','import-employers-db.php']){
  const result=spawnSync('php',[__dirname+'/'+script],{env:{...env,DB_NAME:'alternatech',INTEGRATION_TEST_DB:'1',GOOGLE_TEST_DB:'1',MIGRATION_TEST_DB:'1'},encoding:'utf8'});
  assert.notEqual(result.status,0); assert.match(result.stderr,/_test/); assert.doesNotMatch(result.stderr,/PDOException|SQLSTATE/);
}
process.env.TEST_API_URL='http://127.0.0.1:8080';process.env.TEST_DB_NAME='alternatech_safety_test';process.env.TEST_RUN_ID='a'.repeat(32);
assert.throws(()=>installHttpGuard(),/dedicated/);
process.env.TEST_API_URL='http://127.0.0.1:8099';process.env.TEST_DB_NAME='alternatech';assert.throws(()=>installHttpGuard(),/TEST_DB_NAME/);
process.env.TEST_DB_NAME='alternatech_safety_test';
assert.throws(()=>verifyFixtures({database:'alternatech',test_run_id:process.env.TEST_RUN_ID}));
assert.throws(()=>verifyFixtures({database:process.env.TEST_DB_NAME,test_run_id:'b'.repeat(32)}));
(async()=>{
  let writes=0,probes=0;
  global.fetch=async(url)=>{if(url.endsWith('/__isolation/db')){probes++;return {status:200,json:async()=>({select_database:'alternatech',test_run_id:process.env.TEST_RUN_ID})};}writes++;return {};};
  installHttpGuard();
  await assert.rejects(()=>fetch(process.env.TEST_API_URL+'/api/ads',{method:'POST'}),/Wrong server database/);
  assert.equal(writes,0);assert.equal(probes,1);
  await assert.rejects(()=>fetch('http://127.0.0.1:8080/api/ads',{method:'DELETE'}),/outside/);assert.equal(writes,0);
  global.fetch=async(url,options)=>{if(url.endsWith('/__isolation/db'))return {status:200,json:async()=>({select_database:process.env.TEST_DB_NAME,test_run_id:process.env.TEST_RUN_ID})};writes++;assert.equal(options.redirect,'manual');return {status:201};};
  installHttpGuard();assert.equal((await fetch(process.env.TEST_API_URL+'/api/ads',{method:'POST'})).status,201);assert.equal(writes,1);
  console.log('PASS isolated test guards: unsafe configurations fail before DB connection/writes; matching server allowed; redirects blocked');
})().catch(error=>{console.error(error);process.exitCode=1;});
