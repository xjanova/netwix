import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const helper = await readFile(new URL('../../resources/js/rongyok-client.js', import.meta.url), 'utf8');
const source = (await readFile(new URL('../../resources/js/admin-preview.js', import.meta.url), 'utf8'))
    .replace("import { resolveEpisodeResponse } from './rongyok-client';", helper.replaceAll('export ', ''));
const {adminPreview} = await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
const response=(status,data)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
test('admin preview polls a pending relay then returns the playable response',async()=>{
 let calls=0;
 const result=await adminPreview('/admin/preview/source/1',{
  fetcher:async()=>++calls===1?response(202,{ready:false,relay:{state:'pending'}}):response(200,{ready:true,url:'/signed-proxy',kind:'mp4'}),
  sleep:async()=>{},
 });
 assert.equal(calls,2);assert.equal(result.url,'/signed-proxy');
});
test('closing the modal discards a late response and stops polling',async()=>{
 let active=true,calls=0;
 const result=await adminPreview('/preview',{isCurrent:()=>active,fetcher:async()=>{
  calls++;active=false;return response(200,{ready:true,url:'/late'});
 }});
 assert.equal(result,null);assert.equal(calls,1);
});
test('relay cooldown and a bounded timeout finish without an endless spinner',async()=>{
 const result=await adminPreview('/preview',{fetcher:async()=>response(202,{ready:false,relay:{state:'retry_later'}})});
 assert.equal(result.ready,false);
 let time=0,calls=0;
 assert.equal(await adminPreview('/preview',{now:()=>time,fetcher:async()=>{calls++;return response(202,{relay:{state:'pending'}});},sleep:async()=>{time+=60000;}}),null);
 assert.equal(calls,2);
});
