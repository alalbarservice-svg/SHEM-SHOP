const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://localhost:8081';
const PW = fs.readFileSync('/home/claude/.stg-login-shopmgr','utf8').trim();
const OUT = __dirname + '/shots';
const results = [];
const check = (n, ok, d='') => { results.push({name:n, ok:!!ok, detail:String(d).slice(0,300)}); console.log((ok?'PASS ':'FAIL ')+n+(ok?'':' -> '+d)); };
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args:['--no-sandbox'] });
  for (const vp of [{n:'desktop',w:1366,h:900},{n:'mobile',w:390,h:844}]) {
    const ctx = await browser.newContext({ viewport:{width:vp.w,height:vp.h}, locale:'he-IL' });
    await ctx.route(/^https?:\/\/(?!localhost)/, r=>r.abort());
    const page = await ctx.newPage();
    const errs = [];
    page.on('console', m => { if (m.type()==='error' && !/Failed to load resource/.test(m.text())) errs.push(m.text()); });
    page.on('pageerror', e => errs.push('PAGEERR '+e.message));
    await page.goto(BASE+'/wp-login.php');
    await page.fill('#user_login','shopmgr'); await page.fill('#user_pass',PW); await page.click('#wp-submit'); await page.waitForURL(u=>!u.pathname.includes('wp-login'),{waitUntil:'domcontentloaded',timeout:45000});
    await page.waitForTimeout(500);
    check(vp.n+': login as shop manager', !page.url().includes('wp-login'), page.url());
    await page.goto(BASE+'/wp-admin/admin.php?page=shem-dok');
    await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
    const body = await page.locator('body').innerText();
    check(vp.n+': editor page renders', body.length>200, body.slice(0,120));
    await page.screenshot({ path: `${OUT}/${vp.n}-editor.png`, fullPage:true });
    const noHscroll = await page.evaluate(()=>document.documentElement.scrollWidth <= window.innerWidth+2);
    check(vp.n+': no horizontal overflow', noHscroll);
    // archive tab / list
    const html = await page.content();
    check(vp.n+': archive present', /archive|ארכיון/i.test(html));
    const delBtn = await page.locator('button:has-text("מחיקה"), a:has-text("מחיקה")').count();
    check(vp.n+': no delete control visible on load', delBtn===0, 'count='+delBtn);
    check(vp.n+': no console errors', errs.length===0, errs.join(' | '));
    await ctx.close();
  }
  await browser.close();
  fs.writeFileSync(__dirname+'/results-ui.json', JSON.stringify(results,null,1));
  const f = results.filter(r=>!r.ok).length; console.log('TOTAL',results.length,'FAIL',f); process.exit(f?1:0);
})().catch(e=>{console.error(e);process.exit(2)});
