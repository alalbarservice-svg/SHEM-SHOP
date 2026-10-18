const { chromium } = require('playwright'); const fs=require('fs');
const BASE='http://localhost:8081', PW=fs.readFileSync('/home/claude/.stg-login-shopmgr','utf8').trim(), OUT=__dirname+'/shots';
const R=[]; const ck=(n,ok,d='')=>{R.push({name:n,ok:!!ok,detail:String(d).slice(0,300)});console.log((ok?'PASS ':'FAIL ')+n+(ok?'':' -> '+d));};
(async()=>{
 const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium',args:['--no-sandbox']});
 for(const vp of [{n:'desktop',w:1366,h:900},{n:'mobile',w:390,h:844}]){
 const ctx=await b.newContext({viewport:{width:vp.w,height:vp.h},locale:'he-IL'});
 await ctx.route(/^https?:\/\/(?!localhost)/,r=>r.abort());
 const p=await ctx.newPage(); const errs=[]; p.on('pageerror',e=>errs.push(e.message)); p.on('console',m=>{if(m.type()==='error'&&!/Failed to load resource/.test(m.text()))errs.push(m.text())});
 await p.goto(BASE+'/wp-login.php'); await p.fill('#user_login','shopmgr'); await p.fill('#user_pass',PW);
 await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),p.click('#wp-submit')]);
 await p.goto(BASE+'/wp-admin/admin.php?page=shem-dok'); await p.waitForLoadState('networkidle'); await p.waitForTimeout(1000);
 await p.click('button:has-text("ארכיון מסמכים")'); await p.waitForTimeout(1500);
 const errBox=await p.locator('text=No route was found').count();
 ck(vp.n+': archive loads without API error',errBox===0);
 const rows=await p.locator('button:has-text("צפייה")').count(); ck(vp.n+': archive lists documents',rows>0,'rows='+rows);
 await p.screenshot({path:`${OUT}/${vp.n}-archive.png`});
 ck(vp.n+': archive has no delete button',(await p.locator('button:has-text("מחיקה"), button:has-text("מחק")').count())===0);
 await p.locator('button:has-text("צפייה")').first().click(); await p.waitForTimeout(1800);
 const body=await p.locator('body').innerText();
 ck(vp.n+': issued doc view opens (shows number)',/71\d\d/.test(body));
 const list=await p.evaluate(()=>[...document.querySelectorAll('#wpbody input:not([type=hidden]),#wpbody textarea,#wpbody select')].filter(e=>e.offsetParent&&!e.disabled&&!e.readOnly).map(e=>(e.id||e.name||e.dataset.f||e.type)+'@'+(e.closest('section,details,.sd-panel,aside')?.className||'')));console.log('EDITABLE',JSON.stringify(list));const editable=list.filter(x=>!/^(sd-order|sd-auto|sd-thankyou|sd-email|sd-credit-on|sd-alert-mail)@/.test(x)).length;
 ck(vp.n+': issued document fields are read-only (settings and order-import box excluded)',editable===0,'editable='+editable);
 const saveBtn=await p.locator('button:has-text("שמירה"), button:has-text("שמור")').count();
 ck(vp.n+': no save control on issued doc',saveBtn===0,'count='+saveBtn);
 await p.screenshot({path:`${OUT}/${vp.n}-issued-readonly.png`,fullPage:true});
 await p.locator('button:has-text("התראות")').first().click(); await p.waitForTimeout(1500);
 const dlg=await p.locator('dialog[open]').count(); ck(vp.n+': alerts dialog opens',dlg>0,'dialogs='+dlg);
 await p.screenshot({path:`${OUT}/${vp.n}-alerts.png`});
 ck(vp.n+': no console errors',errs.length===0,errs.join(' | '));
 await ctx.close();}
 await b.close(); fs.writeFileSync(__dirname+'/results-ui3.json',JSON.stringify(R,null,1));
 const f=R.filter(r=>!r.ok).length; console.log('TOTAL',R.length,'FAIL',f);
})();
