'use strict';
// Runs against the already-started guarded server, never a development database.
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const guard=require('./support/http-guard.cjs');guard.installHttpGuard();
const f=JSON.parse(fs.readFileSync(__dirname+'/.runtime-fixtures.json','utf8'));guard.verifyFixtures(f);
const root=path.resolve(__dirname,'..');
const env={...process.env,TEST_RECRUITER_EMAIL:f.recruiter.email,TEST_RECRUITER_PASSWORD:f.password,GOOGLE_TEST_DB:'1'};
function run(binary,file){console.log(`\n>>> ${file}`);const r=spawnSync(binary,[file],{cwd:root,env,stdio:'inherit'});if(r.error)throw r.error;if(r.status!==0)process.exit(r.status||1);}
function files(dir){return fs.readdirSync(path.join(root,dir),{withFileTypes:true}).flatMap(e=>e.isDirectory()?files(path.join(dir,e.name)):[path.join(dir,e.name)]);}
let php=0,js=0;
for(const file of ['api','public','tools','tests'].flatMap(files)){
 if(file.endsWith('.php')){const r=spawnSync('php',['-l',file],{cwd:root,env,encoding:'utf8'});if(r.status!==0)throw new Error(r.stdout+r.stderr);php++;}
 if(/\.(js|cjs)$/.test(file)){const r=spawnSync(process.execPath,['--check',file],{cwd:root,env,encoding:'utf8'});if(r.status!==0)throw new Error(r.stdout+r.stderr);js++;}
}
console.log(`PASS source syntax: ${php} PHP and ${js} JavaScript files`);
for(const file of ['tests/isolation-guard.cjs','tests/catalogue-race.cjs'])run(process.execPath,file);
for(const file of ['tests/auth-rate-limit.php','tests/ads-validation.php','tests/google-auth.php','tests/migration/schema-migration-unit.php'])run('php',file);
for(const file of ['tests/company-access-http.cjs','tests/company-access-browser.cjs','tests/browser-smoke.cjs','tests/auth-session-http.cjs','tests/ads-integrated-http.cjs'])run(process.execPath,file);
for(const file of ['tests/google-db.php','tests/maintenance-db.php','tests/import-employers-db.php'])run('php',file);
run(process.execPath,'tests/integration-http.cjs');
console.log('\nPASS total review: all configured suites passed on the checked-out commit. Real OAuth/provider exchange and historical database migrations require their own environments.');
