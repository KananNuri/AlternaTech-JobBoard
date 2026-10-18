const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {constructor(){this.children=[];}append(...nodes){this.children.push(...nodes);}replaceChildren(...nodes){this.children=nodes;}setAttribute(){}removeAttribute(){}after(){}remove(){}}
const elements=Object.fromEntries(['#jobs','#details','#status','#retry'].map(key=>[key,new Element()])),handlers={},pending=[];
vm.runInNewContext(fs.readFileSync(__dirname+'/../public/assets/jobboard.js','utf8'),{
  document:{querySelector:key=>elements[key]||null,createElement:()=>new Element(),addEventListener:(key,fn)=>handlers[key]=fn},URLSearchParams,AbortController,
  // Intentionally ignore abort to exercise the stale-response guard as well.
  fetch:url=>new Promise(resolve=>pending.push({url,resolve}))
});
const resolve=(i,title)=>pending[i].resolve({ok:true,json:async()=>({items:[{id:i,title,company:'Demo',location:'Paris'}],page:1,pages:1,total:1})});
(async()=>{
  handlers['alternatech:filters']({detail:{q:'new'}});resolve(1,'New result');await new Promise(setImmediate);resolve(0,'Old result');await new Promise(setImmediate);
  assert.deepEqual(elements['#jobs'].children.map(card=>card.children[1].textContent),['New result']);
  console.log('PASS stale catalog responses cannot mix with current filters');
})().catch(error=>{console.error(error);process.exitCode=1;});
