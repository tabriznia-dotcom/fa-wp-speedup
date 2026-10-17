import {chromium} from 'playwright-core';
import fs from 'node:fs';
const mode=process.argv[2];
if (!['origin','local'].includes(mode)) throw new Error('Pass origin or local');
const state=process.env.FAWS_DEV_STATE || '/workspace/.faws-dev';
const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium',args:['--no-sandbox']});
try {
  const page=await browser.newPage();
  const errors=[], cdn=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('https://cdn.jsdelivr.net/**',async route=>{
    cdn.push(route.request().url());
    if(mode==='local') return route.abort();
    const type=new URL(route.request().url()).pathname.endsWith('.css')?'css':'js';
    await new Promise(resolve=>setTimeout(resolve,1500));
    await route.fulfill({status:200,contentType:type==='css'?'text/css':'application/javascript',body:fs.readFileSync(`${state}/artifacts/bootstrap.${type}`)});
  });
  await page.goto('http://localhost:8080/',{waitUntil:'networkidle'});
  const result=await page.evaluate(()=>{
    const button=document.createElement('button');button.className='btn btn-primary';document.body.append(button);
    const color=getComputedStyle(button).backgroundColor;button.remove();
    const n=performance.getEntriesByType('navigation')[0];
    return {bootstrapWorks:typeof window.bootstrap?.Modal==='function',cssWorks:color==='rgb(13, 110, 253)',
      src:document.querySelector('#faws-bootstrap-fixture-js').src,
      href:document.querySelector('#faws-bootstrap-fixture-css').href,
      ttfb_ms:n.responseStart-n.requestStart,load_ms:n.loadEventEnd-n.startTime};
  });
  if(!result.bootstrapWorks || !result.cssWorks || errors.length) throw new Error(JSON.stringify({result,errors}));
  if(mode==='local' && (cdn.length || !result.src.includes('/faws-cache/') || !result.href.includes('/faws-cache/'))) throw new Error('Local replacement not effective');
  if(mode==='origin' && cdn.length!==2) throw new Error('Expected both original CDN sources');
  fs.writeFileSync(`${state}/cdn-${mode}.json`,JSON.stringify({mode,...result,errors,external_requests:cdn.length,simulation:'Original CDN delayed 1500ms per asset; local mode CDN offline; fresh anonymous browser; fixture transport installed authenticated/SRI-verified bytes; not real Iran measurements'},null,2));
  console.log(`PASS ${mode}: Bootstrap JS and CSS functional; external CDN requests=${cdn.length}; load=${Math.round(result.load_ms)}ms`);
}finally{await browser.close();}
