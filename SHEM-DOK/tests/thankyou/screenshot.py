from playwright.sync_api import sync_playwright
url=open('ty_url.txt').read().strip()
with sync_playwright() as p:
    b=p.chromium.launch()
    for name,w,h in (('ty-desktop',1100,900),('ty-mobile',390,800)):
        c=b.new_context(viewport={'width':w,'height':h},locale='he-IL')
        pg=c.new_page()
        pg.route('**/*',lambda r: r.continue_() if r.request.url.startswith('http://localhost') else r.abort())
        pg.goto(url,wait_until='domcontentloaded',timeout=60000); pg.wait_for_timeout(1500)
        txt=pg.inner_text('body')
        print(name,'greeting' ,'לקוח יקר, תודה שרכשת בSHEM מותגים' in txt,'sub','הזמנתך נקלטה, והתשלום בוצע!' in txt,'btn','הורדת חשבונית עסקה' in txt)
        pg.screenshot(path=name+'.png',full_page=False); c.close()
    b.close()
