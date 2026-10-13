import {readFile,writeFile} from 'node:fs/promises';
let h=await readFile(new URL('../dist/client/index.html',import.meta.url),'utf8');h=h.replace('SHIMI | חנות בייסיק','SHIMI | ממשק ניהול').replace('</head>','<meta name="robots" content="noindex,nofollow"></head>');await writeFile(new URL('../dist/client/admin.html',import.meta.url),h);

const receiptShell = (await readFile(new URL('../dist/client/index.html', import.meta.url), 'utf8'))
  .replace(/<title>[^<]*<\/title>/, '<title>מסמך הזמנה | SHIMI</title>')
  .replace('</head>', '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"></head>');
await writeFile(new URL('../dist/client/order-complete.html', import.meta.url), receiptShell);
