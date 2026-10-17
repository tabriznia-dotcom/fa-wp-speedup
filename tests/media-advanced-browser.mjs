import {chromium} from 'playwright-core';
import fs from 'node:fs';
const state=process.env.FAWS_DEV_STATE||'/workspace/.faws-dev';
const fixture=JSON.parse(fs.readFileSync(`${state}/woo-fixtures.json`,'utf8').trim().split('\n').at(-1));
const credentials=Object.fromEntries(fs.readFileSync(`${state}/credentials.env`,'utf8').trim().split('\n').map(l=>l.split('=')));
const base='http://localhost:8080';
if(!fixture.variable_url.startsWith(base))throw new Error('Disposable site required');
const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
const results=[];
function assert(value,label){if(!value)throw new Error(label);results.push(label);console.log('PASS',label);}
const rules=[
 {type:'script',handle:'sample-optional',context:'home',action:'unload'},
 {type:'script',handle:'sample-deferred',context:'home',action:'defer'},
 {type:'style',handle:'sample-style',context:'home',action:'unload'},
 {type:'script',handle:'sample-dependency',context:'home',action:'unload'},
 {type:'script',handle:'sample-localized',context:'home',action:'unload'},
 {type:'script',handle:'mobile-menu-example',context:'home',action:'unload'}
];
const errors=[];
try{
 const admin=await browser.newContext();
 await admin.route('**/*',route=>['localhost','127.0.0.1'].includes(new URL(route.request().url()).hostname)?route.continue():route.abort());
 const manager=await admin.newPage();manager.on('pageerror',e=>errors.push(e.message));
 await manager.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(base+'/wp-admin/options-general.php?page=faws'));await manager.locator('#user_login').fill('faws-admin');await manager.locator('#user_pass').fill(credentials.FAWS_ADMIN_PASSWORD);
 await Promise.all([manager.waitForNavigation({waitUntil:'domcontentloaded'}),manager.locator('#wp-submit').click()]);
 async function advanced(mode,extra={}){
  await manager.goto(base+'/wp-admin/options-general.php?page=faws',{waitUntil:'networkidle'});
  const nonce=await manager.locator('form:has(input[name="action"][value="faws_advanced"]) input[name="_wpnonce"]').first().inputValue();
  const rows=Object.fromEntries(rules.flatMap((rule,i)=>Object.entries(rule).map(([key,value])=>[`rule_rows[${i}][${key}]`,value])));
  const response=await admin.request.post(base+'/wp-admin/admin-post.php',{form:{action:'faws_advanced',op:'save',_wpnonce:nonce,mode,...rows,...extra}});
  assert(response.status()===200,`advanced ${mode} form accepted`);
 }
 async function media(webp){
  await manager.goto(base+'/wp-admin/options-general.php?page=faws',{waitUntil:'networkidle'});
  const nonce=await manager.locator('form:has(input[name="action"][value="faws_media"]) input[name="_wpnonce"]').first().inputValue();
  await admin.request.post(base+'/wp-admin/admin-post.php',{form:{action:'faws_media',op:'settings',_wpnonce:nonce,priority:'1',dimensions:'1',audit:'1',...(webp?{webp:'1'}:{})}});
 }
 async function guest(){const context=await browser.newContext();await context.route('**/*',route=>['localhost','127.0.0.1'].includes(new URL(route.request().url()).hostname)?route.continue():route.abort());const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));return {context,page};}
 async function assetValues(page,url=base+'/'){
  const response=await page.goto(url,{waitUntil:'networkidle'});
  return {response, values:await page.evaluate(()=>({test:window.advancedTest,defer:document.querySelector('#sample-deferred-js')?.defer,css:getComputedStyle(document.documentElement).getPropertyValue('--faws-test').trim()}))};
 }
 await advanced('off');
 let g=await guest();let data=await assetValues(g.page);
 assert(data.values.test.optional&&data.values.css==='73'&&!data.values.defer,'off mode leaves resources intact');await g.context.close();
 await advanced('preview');
 g=await guest();data=await assetValues(g.page,base+'/?faws_preview=forged');
 assert(data.values.test.optional&&data.values.css==='73','guest cannot activate forged preview');await g.context.close();
 await manager.goto(base+'/wp-admin/options-general.php?page=faws',{waitUntil:'networkidle'});
 const preview=await manager.getByRole('link',{name:'پیش‌نمایش صفحهٔ اصلی',exact:true}).getAttribute('href');
 data=await assetValues(manager,preview);
 assert(!data.values.test.optional&&data.values.test.deferred&&data.values.defer&&data.values.css!=='73','manager preview unload and native defer applied');
 assert(data.values.test.dependency&&data.values.test.consumer&&data.values.test.localized&&data.values.test.mobile,'dependencies, localization and critical menu preserved');
 assert((data.response.headers()['cache-control']||'').includes('no-cache'),'preview response forbids page caching');
 const dashboardPreview=base+'/wp-admin/index.php?faws_preview='+new URL(preview).searchParams.get('faws_preview');
 await advanced('preview',{dashboard_news:'1',heartbeat:'1'});
 await manager.goto(dashboardPreview,{waitUntil:'networkidle'});
 assert(await manager.locator('#dashboard_primary').count()===0,'only selected dashboard news widget removed');
 const heartbeat=await manager.evaluate(()=>window.heartbeatSettings?.interval);
 assert(Number(heartbeat)===60,'dashboard Heartbeat remains enabled at 60 seconds');
 await manager.goto(base+'/wp-admin/post.php?post='+fixture.simple+'&action=edit&faws_preview='+new URL(preview).searchParams.get('faws_preview'),{waitUntil:'networkidle'});
 assert(Number(await manager.evaluate(()=>window.heartbeatSettings?.interval))!==60,'editor Heartbeat was not changed');
 await advanced('live',{baseline:'1',confirmed:'1'});
 g=await guest();data=await assetValues(g.page);
 assert(!data.values.test.optional&&data.values.defer,'confirmed live rules apply to anonymous public page');await g.context.close();
 // Images before and after: identical viewport and native responsive dimensions.
 const measurements=[];
 for(const enabled of [false,true]){
  await media(enabled);g=await guest();await g.page.goto(fixture.variable_url,{waitUntil:'networkidle'});
  const image=g.page.locator('.woocommerce-product-gallery img.wp-post-image').first();await image.waitFor();
  const details=await image.evaluate(img=>({src:img.getAttribute('src'),srcset:img.getAttribute('srcset'),sizes:img.getAttribute('sizes'),width:img.getAttribute('width'),height:img.getAttribute('height'),loading:img.loading,priority:img.fetchPriority,complete:img.complete,naturalWidth:img.naturalWidth,bytes:performance.getEntriesByName(img.currentSrc).at(-1)?.encodedBodySize||0}));
  assert(details.complete&&details.naturalWidth>0,`image decodes ${enabled?'WebP':'original'}`);
  assert(details.src.includes('/faws-media/')===enabled,'image serving follows independent global switch');
  assert(details.srcset&&details.sizes&&details.width&&details.height,'responsive attributes preserved');
  if(enabled){
   assert(details.loading==='eager'&&details.priority==='high','primary product image eager with high priority');
   const variationImageId=await g.page.evaluate(()=>jQuery('.variations_form').data('product_variations')[0].image_id);
   await g.page.locator('select[name="attribute_size"]').selectOption('Small');
   await g.page.waitForFunction(()=>Number(document.querySelector('input.variation_id')?.value)>0&&!document.querySelector('.single_add_to_cart_button').classList.contains('disabled'));
   await g.page.waitForFunction(id=>document.querySelector('.woocommerce-product-gallery img.wp-post-image')?.src.includes('/faws-media/'+id+'-'),variationImageId);
   assert(await image.evaluate(img=>img.complete&&img.naturalWidth>0&&img.srcset.includes('/faws-media/')),'variation switches to its own valid responsive WebP');
   // Actual cart session must protect public resource rules.
   await Promise.all([g.page.waitForNavigation({waitUntil:'networkidle'}),g.page.locator('.single_add_to_cart_button').click()]);
   data=await assetValues(g.page);
   assert(data.values.test.optional,'Woo session bypasses advanced public rules');
  }
  measurements.push({webp:enabled,...details});await g.context.close();
 }
 // Manager audit sends only identifiers and dimensions back to this site.
 await manager.goto(fixture.variable_url,{waitUntil:'networkidle'});
 await manager.waitForFunction(()=>typeof fawsMediaAudit==='object');
 const auditNonce=await manager.evaluate(()=>fawsMediaAudit.nonce);
 for(const action of ['faws_media','faws_advanced']){
  const denied=await admin.request.post(base+'/wp-admin/admin-post.php',{form:{action,op:'settings',_wpnonce:'invalid'}});
  assert(denied.status()===403,`${action} rejects invalid settings nonce`);
 }
 const bad=await admin.request.post(base+'/wp-admin/admin-ajax.php',{form:{action:'faws_media_audit',nonce:'invalid',measurements:'[]'}});
 assert(bad.status()===403,'image audit rejects invalid nonce');
 const tooMany=await admin.request.post(base+'/wp-admin/admin-ajax.php',{form:{action:'faws_media_audit',nonce:auditNonce,measurements:JSON.stringify(Array(51).fill({id:28}))}});
 assert([400,429].includes(tooMany.status()),'image audit enforces size or rate limit');
 await manager.goto(base+'/wp-admin/options-general.php?page=faws',{waitUntil:'networkidle'});
 const audit=JSON.parse(await manager.locator('h3:has-text("آخرین اندازه‌گیری مرورگر مدیر") + pre').textContent());
 assert(Array.isArray(audit.images)&&audit.images.length>0&&audit.images.every(row=>!('url' in row)&&'naturalWidth' in row),'same-site image measurements stored without URLs');
 assert(errors.length===0,`no uncaught JavaScript errors: ${errors.join(',')}`);
 fs.writeFileSync(`${state}/media-advanced-results.json`,JSON.stringify({results,measurements,note:'Synthetic fixture images on the local default theme; no general Iran speed claim'},null,2));
 console.log(`PASS ${results.length} browser assertions`);
 await advanced('off');await media(false);
}finally{await browser.close();}
