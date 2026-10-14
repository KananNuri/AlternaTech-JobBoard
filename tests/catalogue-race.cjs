'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm');
class Element {
  children=[];dataset={};classList={toggle(){}};
  append(...items){this.children.push(...items);} replaceChildren(...items){this.children=[...items];}
  setAttribute(){} removeAttribute(){} querySelectorAll(){return [];} querySelector(){return null;} focus(){}
}
const nodes=Object.fromEntries(['#jobs','#details','#status','#retry'].map(id=>[id,new Element()]));
const requests=[],events=[];
const context={URL,URLSearchParams,AbortController,console,CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  document:{querySelector:id=>nodes[id]||null,createElement:()=>new Element(),addEventListener(){},dispatchEvent:e=>events.push(e)},
  I18N:{text(node,key,params){node.key=key;node.params=params;return node;},attr(){},error(node){return node;},translated:key=>new Error(key)},
  fetch:(url,options)=>new Promise(resolve=>requests.push({url,options,resolve}))};
vm.createContext(context);vm.runInContext(fs.readFileSync(__dirname+'/../public/assets/jobboard.js','utf8'),context);
const turn=()=>new Promise(resolve=>setImmediate(resolve));
(async()=>{
  assert.equal(requests.length,1);
  const newer=context.load();assert.equal(requests.length,2);assert.equal(requests[0].options.signal.aborted,true);
  requests[1].resolve({ok:true,json:async()=>({items:[],total:2,page:1,pages:1})});await newer;
  requests[0].resolve({ok:true,json:async()=>({items:[],total:99,page:1,pages:1})});await turn();
  assert.equal(nodes['#status'].params.count,2,'Late catalogue response must not replace current results');
  const detail=context.showDetails(1);requests[2].resolve({ok:false,status:404});assert.equal(await detail,undefined);
  assert.equal(events.length,0,'Failed detail retrieval must not dispatch application events');
  console.log('PASS catalogue stale-response protection, request cancellation, failed-detail event suppression');
})().catch(error=>{console.error(error);process.exitCode=1;});
