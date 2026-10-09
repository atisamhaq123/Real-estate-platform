import {chromium} from '../tools/node_modules/playwright/index.mjs';
import fs from 'node:fs';
import path from 'node:path';
const base = process.env.TEST_URL || 'http://127.0.0.1:8082';
const executablePath = process.env.CHROME_PATH || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const browser = await chromium.launch({executablePath, headless:true});
const errors = [];
let checks = 0;
const check = (condition, name) => {if(!condition) throw new Error(name); checks++; console.log(`PASS: ${name}`);};
fs.mkdirSync('tests/results',{recursive:true});
const visitor = await browser.newContext({viewport:{width:1440,height:1000}});
const page = await visitor.newPage();
async function loadPhotos(p){await p.locator('img').evaluateAll(images=>images.forEach(img=>img.loading='eager'));await p.waitForFunction(()=>[...document.images].every(img=>img.complete),null,{timeout:10000});}
page.on('pageerror',e=>errors.push(e.message));
page.on('console',msg=>{if(msg.type()==='error' && !/net::|WebSocket|Failed to load resource/.test(msg.text())) errors.push(msg.text());});
await page.goto(base); await page.waitForTimeout(1300);
check(await page.locator('h1').textContent().then(t=>t.includes('feel like')),'Luxury homepage renders');
await loadPhotos(page);
await page.screenshot({path:'tests/results/home-desktop.png',fullPage:true});
for(const route of ['/properties','/buy','/rent','/property?id=1','/agents','/agent?id=4','/neighborhoods','/sell','/services','/journal','/about','/contact','/faqs','/login','/register','/forgot','/compare?ids=1,2']){
  const response=await page.goto(base+route); check(response.status()===200,`Public route ${route}`);
  check(!(await page.locator('body').textContent()).includes('Fatal error'),`No PHP error on ${route}`);
}
await page.goto(base+'/properties?purpose=rent'); check(await page.locator('.property-card').count()===2,'Rental filter returns two homes');
await page.getByRole('button',{name:'Map & list'}).click(); check(await page.locator('.map-view').isVisible(),'Schematic map toggles alongside filtered list');
await page.locator('[data-compare]').first().check(); await page.locator('[data-compare]').nth(1).check(); await page.locator('[data-compare-link]').click(); check(await page.locator('.compare-table tr').count()===10,'Selected properties compare side by side');
await page.goto(base+'/property?id=1'); const original=await page.locator('[data-payment]').textContent(); await page.locator('[data-calculator] input[name=rate]').fill('0'); check((await page.locator('[data-payment]').textContent())!==original,'Editable mortgage assumptions update estimate');
await page.setViewportSize({width:390,height:844});
for(const route of ['/','/properties','/property?id=1','/sell','/services']){
  await page.goto(base+route); check(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),`No horizontal overflow at mobile width: ${route}`);
}
await page.goto(base); await page.locator('.menu-toggle').click(); check(await page.locator('.main-nav').isVisible(),'Accessible mobile navigation opens');
await loadPhotos(page);
await page.screenshot({path:'tests/results/home-mobile.png',fullPage:true});
async function account(email){const ctx=await browser.newContext({viewport:{width:1440,height:1000}}); const p=await ctx.newPage();p.on('pageerror',e=>errors.push(e.message)); await p.goto(base+'/login'); await p.locator('input[name=email]').fill(email);await p.locator('input[name=password]').fill('IsolatedDemoPass123!');await Promise.all([p.waitForURL('**/dashboard'),p.getByRole('button',{name:'Sign in',exact:true}).click()]);return {ctx,p};}
const buyer=await account('buyer@aurelle.demo');const agent=await account('elena@aurelle.demo');const admin=await account('admin@aurelle.demo');
for(const tab of ['overview','favorites','collections','searches','listings','viewings','proposals','documents','services','notifications','settings']){const r=await buyer.p.goto(base+'/dashboard?tab='+tab);check(r.status()===200,`Buyer dashboard ${tab}`);}
await agent.p.goto(base+'/dashboard?tab=crm');check(await agent.p.locator('h1').textContent()==='Agent workspace','Assigned agent CRM available');
for(const section of ['listings','users','agents','valuations','services','billing','reports','reviews','categories','amenities','neighborhoods','content','templates','failures','audit','leads','viewings','proposals','pricing','providers','business','catalog']){const r=await admin.p.goto(base+'/admin?section='+section);check(r.status()===200,`Admin section ${section}`);}
const denied=await buyer.p.goto(base+'/admin');check(denied.status()===403,'Buyer cannot enter admin');
await buyer.p.goto(base+'/dashboard?tab=messages&conversation=1'); await agent.p.goto(base+'/dashboard?tab=messages&conversation=1');
await buyer.p.locator('[data-transport]').filter({hasText:'WebSocket connected'}).waitFor({timeout:15000});await agent.p.locator('[data-transport]').filter({hasText:'WebSocket connected'}).waitFor({timeout:15000});
check(true,'Two independent browser sessions authenticate WebSockets');
const note='Browser WebSocket check '+Date.now();await buyer.p.locator('.chat-compose textarea').fill(note);await buyer.p.locator('.chat-compose button').click();await agent.p.locator('.message span').filter({hasText:note}).waitFor({timeout:4000});check(true,'Persisted message broadcasts to independent recipient via WebSocket');
await buyer.p.locator('.message.mine').filter({hasText:note}).locator('small').filter({hasText:'Read'}).waitFor({timeout:16000});check(true,'Read receipts appear in sender session');
await agent.p.reload();await agent.p.locator('.message span').filter({hasText:note}).waitFor();check(true,'Reload recovers persisted messages without duplicates');
check(await agent.p.locator('.message span').filter({hasText:note}).count()===1,'Missed-message recovery deduplicates');
await buyer.p.screenshot({path:'tests/results/messages-desktop.png',fullPage:true});
// Simulate a worker outage by preventing new WebSocket connections in this session.
await buyer.ctx.routeWebSocket('**',ws=>ws.close());await buyer.p.reload();await buyer.p.locator('[data-transport]').filter({hasText:'polling'}).waitFor({timeout:15000});check(true,'Worker outage is honestly labeled near-real-time polling');
const offline='Polling fallback check '+Date.now();await agent.p.locator('.chat-compose textarea').fill(offline);await agent.p.locator('.chat-compose button').click();await buyer.p.locator('.message span').filter({hasText:offline}).waitFor({timeout:10000});check(true,'Polling recovers messages when worker unavailable');
await buyer.p.setViewportSize({width:390,height:844});check(await buyer.p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Mobile dashboard has no horizontal overflow');
const rejectedCsrf=await buyer.ctx.request.post(base+'/favorite',{form:{id:'2'}});check(rejectedCsrf.status()===419,'Mutation without CSRF token rejected');
await buyer.p.goto(base+'/dashboard?tab=documents');
const uploadName='browser-private-'+Date.now()+'.pdf';
await buyer.p.locator('input[type=file]').setInputFiles({name:uploadName,mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF')});
await Promise.all([buyer.p.waitForURL('**/dashboard?tab=documents'),buyer.p.getByRole('button',{name:'Upload document',exact:true}).click()]);
const docRecord=buyer.p.locator('.record').filter({hasText:uploadName});await docRecord.waitFor();const download=await docRecord.getByRole('link',{name:'Download'}).getAttribute('href');
check((await buyer.ctx.request.get(base+download)).status()===200,'Owner can download validated private PDF');
check((await admin.ctx.request.get(base+download)).status()===403,'Admin cannot download unshared private PDF');
await docRecord.locator('input[name=email]').fill('elena@aurelle.demo');await Promise.all([buyer.p.waitForURL('**/dashboard?tab=documents'),docRecord.getByRole('button',{name:'Share privately'}).click()]);
check((await agent.ctx.request.get(base+download)).status()===200,'Explicit recipient can download shared document');
await Promise.all([buyer.p.waitForURL('**/dashboard?tab=documents'),buyer.p.locator('.record').filter({hasText:uploadName}).getByRole('button',{name:'Revoke'}).click()]);
check((await agent.ctx.request.get(base+download)).status()===403,'Revoked recipient cannot download');
const buyerCsrf=await buyer.p.locator('meta[name=csrf-token]').getAttribute('content');
const invalidUpload=await buyer.ctx.request.post(base+'/dashboard/action',{multipart:{_csrf:buyerCsrf,action:'document_upload',tab:'documents',file:{name:'unsafe.html',mimeType:'text/html',buffer:Buffer.from('<script>bad()</script>')}}});check(invalidUpload.status()===422,'Invalid upload MIME rejected by server');
await admin.p.goto(base+'/admin?section=content');const about=admin.p.locator('.record').filter({has:admin.p.locator('input[name=slug][value=about]')});
const updated='Veyra isolated browser test '+Date.now();await about.locator('textarea[name=body]').fill(updated);await Promise.all([admin.p.waitForURL('**/admin?section=content'),about.getByRole('button',{name:'Save public content'}).click()]);
await page.goto(base+'/about');check((await page.locator('.prose').textContent()).includes(updated),'Administrative content change appears on public website');
check((await admin.ctx.request.get(base+'/admin/export')).headers()['content-type'].includes('text/csv'),'Audited service invoice CSV export works');
await buyer.p.goto(base+'/dashboard?tab=viewings');check(await buyer.p.locator('.appointment-calendar').isVisible(),'Persistent appointment calendar available');check(await buyer.p.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),'Appointment calendar fits mobile width');
check(errors.length===0,'No browser script errors: '+errors.join('; '));
await browser.close();console.log(`${checks} browser checks passed.`);
