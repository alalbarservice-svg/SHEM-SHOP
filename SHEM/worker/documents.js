import { PDFDocument, rgb, PageSizes } from "pdf-lib";
import fontkit from "@pdf-lib/fontkit";
import bidiFactory from "bidi-js";
import { regular, bold, logo } from "./document-assets.js";

const bidi = bidiFactory();
const bytes = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
const clean = (s) =>
  String(s ?? "").replace(/[\u0000-\u001f\u202a-\u202e\u2066-\u2069]/g, " ");
const visual = (s) => {
  const t = clean(s);
  return bidi.getReorderedString(
    t,
    bidi.getEmbeddingLevels(
      t,
      /[\u0590-\u05ff]/.test(t) || !/[A-Za-z]/.test(t) ? "rtl" : "ltr",
    ),
  );
};
const cash = (n) => (Number(n || 0) / 100).toFixed(2) + " ₪";
export const shippingLabel = (d) =>
  d.shippingLabel ||
  {
    standard: "שליח עד הבית - 5-7 ימי עסקים",
    express: "שליח עד הבית - יום עסקים אחד",
  }[d.shippingMethod] ||
  d.shippingMethod ||
  "שליח עד הבית";
export function documentAmounts(d) {
  const gross = d.subtotalCents - (d.discountCents || 0);
  const rate =
    d.vatRate ??
    (d.business?.vatMode === "exempt" ? 0 : Number(d.business?.vatRate ?? 18));
  const vat = d.vatItemsCents ?? Math.round((gross * rate) / (100 + rate));
  return { gross, rate, vat, net: gross - vat };
}

export function documentRegistrationNumber(d, type = "invoice") {
  if (type !== "invoice" && d.business?.registrationNumberScope === "invoice") return "";
  return d.business?.registrationNumber || "";
}

export async function createDocumentPDF(d, type = "invoice") {
  const pdf = await PDFDocument.create();
  pdf.registerFontkit({
    create(data) {
      const font = fontkit.create(data),
        layout = font.layout.bind(font);
      font.layout = (text, features) =>
        layout(text, features, undefined, undefined, "ltr");
      return font;
    },
  });
  const normal = await pdf.embedFont(bytes(regular), { subset: true });
  const heavy = await pdf.embedFont(bytes(bold), { subset: true });
  const mark = await pdf.embedPng(bytes(logo));
  pdf.setTitle(
    type === "invoice"
      ? "SHEM - חשבונית עסקה " + d.number
      : "SHEM - תעודת הזמנה " + d.orderId,
  );
  pdf.setAuthor("SHEM");
  pdf.setCreationDate(new Date(d.issuedAt));
  pdf.setModificationDate(new Date(d.issuedAt));
  const dark = rgb(0.043, 0.043, 0.043),
    gold = rgb(0.788, 0.663, 0.416),
    ink = rgb(0.13, 0.13, 0.13),
    muted = rgb(0.43, 0.43, 0.43),
    line = rgb(0.88, 0.86, 0.82);
  const W = PageSizes.A4[0],
    H = PageSizes.A4[1],
    L = 42,
    R = W - 42;
  let page, y;
  const text = (
    s,
    x,
    baseline,
    size = 10,
    weight = false,
    color = ink,
    align = "right",
  ) => {
    const f = weight ? heavy : normal,
      t = visual(s),
      width = f.widthOfTextAtSize(t, size);
    page.drawText(t, {
      x: align === "right" ? x - width : x,
      y: baseline,
      size,
      font: f,
      color,
    });
  };
  const wrap = (s, width, size = 10, weight = false) => {
    const f = weight ? heavy : normal,
      out = [],
      words = clean(s).split(/\s+/);
    let row = "";
    for (const word of words) {
      const next = row ? row + " " + word : word;
      if (f.widthOfTextAtSize(visual(next), size) > width && row) {
        out.push(row);
        row = word;
      } else row = next;
    }
    if (row) out.push(row);
    return out.flatMap((row) => {
      if (f.widthOfTextAtSize(visual(row), size) <= width) return [row];
      const a = [];
      let part = "";
      for (const c of row) {
        if (f.widthOfTextAtSize(visual(part + c), size) > width) {
          a.push(part);
          part = c;
        } else part += c;
      }
      if (part) a.push(part);
      return a;
    });
  };
  const paragraph = (s, size = 10, weight = false, color = ink) => {
    for (const row of wrap(s, R - L, size, weight)) {
      ensure(18);
      text(row, R, y, size, weight, color);
      y -= 16;
    }
  };
  const start = () => {
    page = pdf.addPage(PageSizes.A4);
    page.drawRectangle({
      x: 0,
      y: H - 165,
      width: W,
      height: 165,
      color: dark,
    });
    page.drawImage(mark, { x: L, y: H - 93, width: 58, height: 58 });
    text("SHEM", R, H - 55, 24, true, rgb(1, 1, 1));
    text(
      type === "invoice"
        ? d.business?.documentTitle || "חשבונית עסקה"
        : "תעודת הזמנה",
      R,
      H - 91,
      20,
      true,
      rgb(1, 1, 1),
    );
    text(
      type === "invoice" ? "מקור" : "פרטי הזמנה למנהל",
      R,
      H - 115,
      10,
      false,
      gold,
    );
    page.drawRectangle({
      x: L,
      y: H - 147,
      width: R - L,
      height: 2,
      color: gold,
    });
    text(d.number || d.orderId, R, H - 140, 9, false, gold);
    y = H - 190;
  };
  const ensure = (height) => {
    if (y - height < 62) start();
  };
  const rule = () => {
    ensure(12);
    page.drawLine({
      start: { x: L, y },
      end: { x: R, y },
      thickness: 0.6,
      color: line,
    });
    y -= 18;
  };
  start();
  const b = d.business || {},
    c = d.customer || {};
  paragraph(b.legalName || b.displayName || "SHEM", 12, true);
  const registrationNumber = documentRegistrationNumber(d, type);
  if (registrationNumber) paragraph("מספר עסק: " + registrationNumber, 9, false, muted);
  paragraph(
    [b.address, b.phone, b.email].filter(Boolean).join(" | "),
    9,
    false,
    muted,
  );
  paragraph(
    "תאריך: " +
      new Date(d.issuedAt).toLocaleDateString("he-IL", {
        timeZone: "Asia/Jerusalem",
      }),
    9,
    false,
    muted,
  );
  paragraph("מספר הזמנה: " + d.orderId, 9, false, muted);
  y -= 8;
  rule();
  paragraph("פרטי הלקוח", 11, true);
  paragraph(c.name, 12, true);
  paragraph([c.phone, c.email].filter(Boolean).join(" | "), 10);
  paragraph(
    "כתובת משלוח: " +
      [c.street, c.apartment, c.city, c.postcode].filter(Boolean).join(", "),
    10,
  );
  paragraph(shippingLabel(d), 10, false, muted);
  y -= 8;
  const tableHead = () => {
    ensure(32);
    page.drawRectangle({
      x: L,
      y: y - 9,
      width: R - L,
      height: 27,
      color: rgb(0.95, 0.94, 0.92),
    });
    text("פריט / מק״ט", R - 8, y, 9, true);
    text("מידה / צבע", 385, y, 9, true);
    text("כמות", 286, y, 9, true);
    text("יחידה", 232, y, 9, true);
    text("סכום", 138, y, 9, true);
    y -= 34;
  };
  tableHead();
  for (const item of d.items) {
    const name = wrap(item.name, 155, 10, true),
      sku = wrap(item.sku || "", 155, 8),
      variant = wrap(
        [item.size, item.color].filter(Boolean).join(" / "),
        85,
        9,
      );
    const height =
      Math.max((name.length + sku.length) * 14, variant.length * 14, 28) + 15;
    if (y - height < 65) {
      start();
      tableHead();
    }
    let base = y;
    for (const row of name) {
      text(row, R - 8, base, 10, true);
      base -= 14;
    }
    for (const row of sku) {
      text(row, R - 8, base, 8, false, muted);
      base -= 14;
    }
    variant.forEach((row, i) => text(row, 385, y - i * 14, 9));
    text(item.qty, 286, y, 10);
    text(cash(item.priceCents), 232, y, 9);
    text(cash(item.qty * item.priceCents), 138, y, 9, true);
    y -= height;
    rule();
  }
  const a = documentAmounts(d);
  ensure(190);
  const sum = (label, value, strong = false) => {
    text(label, R, y, 10, strong);
    text(cash(value), L + 110, y, 11, strong);
    y -= 24;
  };
  if (d.discountCents) sum("הנחה כלולה במחירי הסיכום", d.discountCents);
  sum("סך פריטים לפני מע״מ", a.net);
  sum("מע״מ כלול בפריטים (" + a.rate + "%)", a.vat);
  sum("סך פריטים כולל מע״מ", a.gross, true);
  sum("משלוח כולל מע״מ", d.shippingCents);
  y -= 10;
  page.drawRectangle({
    x: L,
    y: y - 20,
    width: R - L,
    height: 43,
    color: dark,
  });
  text("סה״כ לתשלום", R - 14, y - 4, 12, true, rgb(1, 1, 1));
  text(cash(d.totalCents), L + 123, y - 4, 16, true, gold);
  y -= 55;
  if (d.paymentReference)
    paragraph("אסמכתת תשלום: " + d.paymentReference, 9, false, muted);
  if (type === "order" && d.paymentDetails) {
    paragraph("אמצעי תשלום: " + (d.paymentDetails.method || ""), 9);
    if (d.paymentDetails.last4)
      paragraph(
        "כרטיס: **** " +
          d.paymentDetails.last4 +
          " | " +
          (d.paymentDetails.cardholderName || ""),
        9,
      );
  }
  if (b.documentFooter) paragraph(b.documentFooter, 9, false, muted);
  const pages = pdf.getPages();
  pages.forEach((p, i) => {
    page = p;
    page.drawLine({
      start: { x: L, y: 42 },
      end: { x: R, y: 42 },
      thickness: 0.6,
      color: line,
    });
    text("SHEM / " + (i + 1) + " / " + pages.length, R, 26, 8, false, muted);
  });
  return pdf.save();
}

export async function storedDocumentPDF(env, d, type = "invoice") {
  const key =
    (type === "invoice" ? "documents/" : "order-documents/") +
    d.orderId +
    ".pdf";
  const previous = await env.BUCKET?.get(key);
  if (previous) return new Uint8Array(await previous.arrayBuffer());
  const output = await createDocumentPDF(d, type);
  if (env.BUCKET)
    await env.BUCKET.put(key, output, {
      httpMetadata: { contentType: "application/pdf" },
    });
  return output;
}
export function base64PDF(data) {
  let s = "";
  for (let i = 0; i < data.length; i += 16384)
    s += String.fromCharCode(...data.subarray(i, i + 16384));
  return btoa(s);
}

const esc = (s) =>
  String(s ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
export function documentHTML(d) {
  const b = d.business || {},
    c = d.customer || {},
    a = documentAmounts(d);
  const row = (label, value, strong = false) =>
    `<div class="sum ${strong ? "strong" : ""}"><span>${esc(label)}</span><b>${cash(value)}</b></div>`;
  return `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>חשבונית עסקה ${esc(d.number)}</title><style>
  @page{size:A4;margin:14mm}*{box-sizing:border-box}body{margin:0;background:#e8e4dc;color:#171717;font:14px Arial,sans-serif}.sheet{width:210mm;min-height:297mm;margin:24px auto;background:white;padding:16mm;box-shadow:0 15px 60px #0001}.mast{background:#0b0b0b;color:white;padding:28px;display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #c9a96a}.mast img{width:75px;height:75px;object-fit:contain}.mast h1{margin:12px 0 8px;font-size:30px}.brand{font-size:24px;letter-spacing:5px}.gold{color:#c9a96a}.meta{display:flex;justify-content:space-between;gap:20px;padding:26px 0;border-bottom:1px solid #e8e4dc}.meta div{flex:1}.muted{color:#666;font-size:12px}.customer{padding:22px 0}h2{font-size:18px;margin:8px 0}p{line-height:1.6;overflow-wrap:anywhere;margin:5px 0}table{width:100%;border-collapse:collapse;table-layout:fixed;margin:14px 0 24px}th{background:#f4f2ee;text-align:right;padding:12px 8px;font-size:12px}td{padding:15px 8px;border-bottom:1px solid #e8e4dc;vertical-align:top;overflow-wrap:anywhere}th:first-child{width:36%}th:nth-child(2){width:20%}tr{break-inside:avoid}thead{display:table-header-group}.totals{margin-right:auto;width:70%;break-inside:avoid}.sum{display:flex;justify-content:space-between;gap:15px;padding:9px 0}.strong{border-top:1px solid #e8e4dc}.total{background:#0b0b0b;color:white;padding:20px;margin-top:12px;font-size:20px}.total b{color:#c9a96a}.footer{border-top:1px solid #e8e4dc;padding-top:20px;margin-top:25px}.copy{font-size:13px;letter-spacing:2px}@media print{body{background:white}.sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}.mast,.total{-webkit-print-color-adjust:exact;print-color-adjust:exact}}@media(max-width:820px){.sheet{width:100%;min-height:0;margin:0;padding:20px}.mast{padding:20px}.mast h1{font-size:23px}.meta{flex-direction:column}.totals{width:100%}table{font-size:12px}th,td{padding:10px 4px}}
  </style></head><body><article class="sheet"><header class="mast"><div><div class="brand">SHEM</div><h1>${esc(b.documentTitle || "חשבונית עסקה")}</h1><div class="gold copy">מקור</div><p class="gold">${esc(d.number)}</p></div><img alt="SHEM" src="data:image/png;base64,${logo}"></header>
  <section class="meta"><div><h2>${esc(b.legalName || b.displayName || "SHEM")}</h2><p>מספר עסק: ${esc(b.registrationNumber)}</p><p>${esc(b.address)}</p><p>${esc(b.phone)} | ${esc(b.email)}</p></div><div><p>תאריך: ${esc(new Date(d.issuedAt).toLocaleDateString("he-IL", { timeZone: "Asia/Jerusalem" }))}</p><p class="muted">מספר הזמנה: ${esc(d.orderId)}</p></div></section>
  <section class="customer"><p class="muted">פרטי הלקוח והמשלוח</p><h2>${esc(c.name)}</h2><p>${esc(c.phone)} | ${esc(c.email)}</p><p>${esc([c.street, c.apartment, c.city, c.postcode].filter(Boolean).join(", "))}</p><p>${esc(shippingLabel(d))}</p></section>
  <table><thead><tr><th>פריט / מק״ט</th><th>מידה / צבע</th><th>כמות</th><th>יחידה</th><th>סכום</th></tr></thead><tbody>${d.items.map((i) => `<tr><td><b>${esc(i.name)}</b><p class="muted">${esc(i.sku)}</p></td><td>${esc(i.size)} / ${esc(i.color)}</td><td>${i.qty}</td><td>${cash(i.priceCents)}</td><td>${cash(i.qty * i.priceCents)}</td></tr>`).join("")}</tbody></table>
  <section class="totals">${d.discountCents ? row("הנחה כלולה בסיכום", d.discountCents) : ""}${row("סך פריטים לפני מע״מ", a.net)}${row("מע״מ כלול בפריטים (" + a.rate + "%)", a.vat)}${row("סך פריטים כולל מע״מ", a.gross, true)}${row("משלוח כולל מע״מ", d.shippingCents)}<div class="sum total"><span>סה״כ לתשלום</span><b>${cash(d.totalCents)}</b></div></section>
  <footer class="footer"><p class="muted">אסמכתת תשלום: ${esc(d.paymentReference)}</p><p>${esc(b.documentFooter)}</p></footer></article></body></html>`;
}
