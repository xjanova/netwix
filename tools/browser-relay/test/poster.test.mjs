import {test} from 'node:test';
import assert from 'node:assert/strict';
import {ChromeResolver, validPosterUrl, posterPageUrl, validJob} from '../resolver.mjs';

const job={id:1,type:'poster',source:'rongyok',series_id:'100852240',episode:'1',lease_token:'a'.repeat(64)};
test('poster jobs only navigate fixed series pages and accept source poster paths',()=>{
 assert.equal(posterPageUrl(job),'https://rongyok.com/series/100852240/');
 for(const url of ['https://rongyok.com.evil.test/images/poster/a.webp','http://rongyok.com/images/poster/a.webp',
  'https://rongyok.com/images/anti1.png','https://rongyok.com/images/poster/a.webp?redirect=1','https://user@rongyok.com/images/poster/a.webp'])assert.equal(validPosterUrl(url),false);
 assert.equal(validPosterUrl('https://rongyok.com/images/poster/%E0%B8%A3.webp'),true);
 assert.equal(validJob({...job,type:'arbitrary_fetch'}),false);
 assert.throws(()=>posterPageUrl({...job,series_id:'1/../../admin'}));
});

test('poster bytes come from the image response loaded on its own series page',async()=>{
 const url='https://rongyok.com/images/poster/real.webp';
 const bytes=Buffer.alloc(800,1);let onResponse,closed=false,visited;
 const page={route:async()=>{},on:(name,fn)=>{if(name==='response')onResponse=fn;},
  goto:async u=>{visited=u;onResponse({url:()=>url,ok:()=>true,body:async()=>bytes,headers:()=>({'content-type':'image/webp'})});return {status:()=>200};},
  locator:()=>({first:()=>({waitFor:async()=>{},evaluate:async()=>url})}),close:async()=>{closed=true;}};
 const resolver=new ChromeResolver(null);resolver.ready=async()=>{};resolver.context={newPage:async()=>page};
 const result=await resolver.resolve(job);
 assert.equal(visited,posterPageUrl(job));assert.equal(result.image_url,url);
 assert.deepEqual(Buffer.from(result.image.split(',')[1],'base64'),bytes);assert.equal(closed,true);
});
