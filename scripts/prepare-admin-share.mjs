import { readFile, writeFile } from "node:fs/promises";

// Separate static entry lets crawlers see management branding without running JS.
// This contains only the public app shell; all management data remains authorized.
const index = new URL("../dist/client/index.html", import.meta.url);
let html = await readFile(index, "utf8");
html = html
  .replaceAll("SHEM | Their label. Your SHEM.", "SHEM | ממשק ניהול")
  .replaceAll("SHEM - אופנת גברים. Their label. Your SHEM.", "ממשק הניהול של SHEM. כניסה למנהלים מורשים בלבד.")
  .replaceAll('content="https://shem-menswear.genial-comet-8090.chatgpt.site/"', 'content="https://shem-menswear.genial-comet-8090.chatgpt.site/admin.html"')
  .replace('rel="canonical" href="https://shem-menswear.genial-comet-8090.chatgpt.site/"', 'rel="canonical" href="https://shem-menswear.genial-comet-8090.chatgpt.site/admin.html"')
  .replace("</head>", '<meta name="robots" content="noindex, nofollow" /></head>');
await writeFile(new URL("../dist/client/admin.html", import.meta.url), html);
console.log("Prepared branded management share entry: /admin.html");

const receiptShell = (await readFile(new URL('../dist/client/index.html', import.meta.url), 'utf8'))
  .replace(/<title>[^<]*<\/title>/, '<title>מסמך הזמנה | SHEM</title>')
  .replace('</head>', '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"></head>');
await writeFile(new URL('../dist/client/order-complete.html', import.meta.url), receiptShell);
