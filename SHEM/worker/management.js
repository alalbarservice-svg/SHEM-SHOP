import {
  reserveStock,
  releaseStock,
  stockView,
  stockGuards,
  linkedGuard,
  isHome,
  validateReviewToken,
} from "./channel.js";
import {
  documentHTML,
  storedDocumentPDF,
  createDocumentPDF,
  base64PDF,
  shippingLabel,
} from "./documents.js";
import { publicOperations } from "./operations.js";
const json = (d, s = 200) =>
  new Response(JSON.stringify(d), {
    status: s,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store",
    },
  });
const fail = (m, s = 400) => {
  throw Object.assign(new Error(m), { status: s });
};
const str = (v, n = 500) => (typeof v === "string" ? v.trim().slice(0, n) : "");
const email = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
const now = () => new Date().toISOString();
const amount = (v) => Math.round(Number(v) * 100);
export function cleanPayment(d = {}) {
  const method = ["card", "bank-transfer", "cash", "other"].includes(d.method)
    ? d.method
    : "other";
  if (d.last4 && !/^\d{4}$/.test(String(d.last4)))
    fail("יש להזין ארבע ספרות אחרונות בלבד.");
  const installments = Number(d.installments || 1);
  if (!Number.isInteger(installments) || installments < 1 || installments > 36)
    fail("מספר תשלומים לא תקין.");
  return {
    method,
    last4: str(String(d.last4 || ""), 4),
    cardholderName: str(d.cardholderName, 150),
    brand: str(d.brand, 40),
    authorizationCode: str(d.authorizationCode, 60),
    installments,
  };
}
const parse = async (r) => {
  const t = await r.text();
  if (t.length > 1000000) fail("הבקשה גדולה מדי.", 413);
  try {
    return JSON.parse(t);
  } catch {
    fail("בקשה לא תקינה.");
  }
};
export const setting = async (db, key) => {
  const r = await db
    .prepare("SELECT value FROM settings WHERE key=?")
    .bind(key)
    .first();
  return r ? JSON.parse(r.value) : {};
};
const get = async (db, table, id) => {
  const r = await db
    .prepare(`SELECT data,updated_at FROM ${table} WHERE id=?`)
    .bind(id)
    .first();
  return r ? { ...JSON.parse(r.data), id, revision: r.updated_at } : null;
};
export const list = async (db, table) => {
  const r = await db
    .prepare(
      `SELECT id,data,updated_at FROM ${table} ORDER BY updated_at DESC LIMIT 1000`,
    )
    .all();
  return r.results.map((x) => ({
    ...JSON.parse(x.data),
    id: x.id,
    revision: x.updated_at,
  }));
};
const statement = (db, table, id, d, rev) =>
  db
    .prepare(
      `INSERT INTO ${table}(id,data,updated_at) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at`,
    )
    .bind(id, JSON.stringify(d), rev);
const save = async (db, table, id, d, expected) => {
  const rev = now();
  if (expected) {
    const result = await db
      .prepare(
        `UPDATE ${table} SET data=?,updated_at=? WHERE id=? AND updated_at=?`,
      )
      .bind(JSON.stringify(d), rev, id, expected)
      .run();
    if (!result.meta.changes) fail("הרשומה השתנתה. רעננו לפני שמירה.", 409);
  } else await statement(db, table, id, d, rev).run();
  return { ...d, id, revision: rev };
};
const cleanCustomer = (d) => {
  const c = {
    name: str(d.name, 150),
    email: str(d.email, 254).toLowerCase(),
    phone: str(d.phone, 40),
    city: str(d.city, 150),
    street: str(d.street, 300),
    apartment: str(d.apartment, 50),
    postcode: str(d.postcode, 30),
  };
  if (!c.name || !email(c.email)) fail("שם ואימייל תקינים נדרשים.");
  return c;
};
const tables = {
  collections: "collections",
  customers: "customers",
  cards: "member_profiles",
  requests: "requests",
  activities: "activities",
  orders: "orders",
  products: "products",
  promotions: "promotions",
  stock: "stock_movements",
  loyalty: "loyalty_ledger",
  documents: "invoice_documents",
  mail: "mail_jobs",
};
export function esc(v) {
  return String(v ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
}
const shekel = (c) => (c / 100).toFixed(2) + " ₪";
export const invoiceHTML = documentHTML;

const invoiceComplete = (b) =>
  !!(
    b.legalName &&
    b.registrationNumber &&
    b.address &&
    b.phone &&
    email(b.email) &&
    ["included", "exempt"].includes(b.vatMode)
  );
export async function makeOrder(db, d) {
  const customer = cleanCustomer(d.customer || {});
  if (!Array.isArray(d.items) || !d.items.length || d.items.length > 100)
    fail("יש להוסיף פריטים להזמנה.");
  const products = await list(db, "products");
  const items = [],
    seen = new Set();
  for (const x of d.items) {
    const p = products.find((p) => p.id === x.productId && !p.archived);
    if (
      !p ||
      !p.sizes.includes(x.size) ||
      !Number.isInteger(Number(x.qty)) ||
      Number(x.qty) < 1 ||
      Number(x.qty) > 1000
    )
      fail("פריט, מידה או כמות אינם תקינים.");
    const variant = (p.variants || []).find((v) => v.id === x.variantId);
    if (p.variants?.length && !variant) fail("יש לבחור גרסת מוצר.");
    if (variant && variant.size !== x.size) fail("המידה אינה תואמת לגרסה.");
    const key = p.id + ":" + (variant?.id || x.size);
    if (seen.has(key)) fail("יש לאחד פריטים כפולים.");
    seen.add(key);
    items.push({
      productId: p.id,
      variantId: variant?.id || "",
      name: p.he,
      sku: variant?.sku || p.sku,
      homeSku: variant?.homeSku || p.homeSku || "",
      size: x.size,
      color: variant?.color || p.color,
      qty: Number(x.qty),
      priceCents: amount(p.price),
    });
  }
  const subtotalCents = items.reduce((n, i) => n + i.qty * i.priceCents, 0),
    shippingCents = amount(d.shippingCost ?? 49),
    discountCents = amount(d.discount ?? 0);
  if (
    !Number.isInteger(shippingCents) ||
    shippingCents < 0 ||
    shippingCents > 100000 ||
    !Number.isInteger(discountCents) ||
    discountCents < 0 ||
    discountCents > subtotalCents
  )
    fail("משלוח או הנחה אינם תקינים.");
  return {
    customer,
    items,
    subtotalCents,
    shippingCents,
    discountCents,
    vatRate: 18,
    vatItemsCents: Math.round(((subtotalCents - discountCents) * 18) / 118),
    vatShippingCents: Math.round((shippingCents * 18) / 118),
    shippingLabel: shippingLabel(d),
    totalCents: subtotalCents + shippingCents - discountCents,
    total: (subtotalCents + shippingCents - discountCents) / 100,
    shippingMethod: str(d.shippingMethod) || "standard",
    notes: str(d.notes, 2000),
  };
}
export async function confirmPayment(
  env,
  orderId,
  reference,
  source,
  details = {},
) {
  const db = env.DB,
    order = await get(db, "orders", orderId);
  if (!order || order.archived) fail("הזמנה לא נמצאה.", 404);
  if (order.paymentStatus === "paid") return order;
  if (!reference) fail("נדרשת אסמכתת תשלום.");
  const rev = now(),
    statements = [],
    guard = [],
    changed = new Map();
  for (const i of order.items) {
    const p =
      changed.get(i.productId) || (await get(db, "products", i.productId));
    if (!p || p.archived) fail("מוצר אינו זמין.");
    if (isHome(env) && (await linkedGuard(env, p.id))) {
      if (!order.centralInventoryConfirmed)
        fail("יש לאמת מלאי מרכזי ב-SHEM לפני אישור תשלום.");
      continue;
    }
    const available = await stockView(env, p, orderId);
    const availableVariant = available.variants?.find(
      (v) => v.id === i.variantId,
    );
    if (
      (availableVariant?.stock ??
        available.sizeStock?.[i.size] ??
        available.stock) < i.qty ||
      available.stock < i.qty
    )
      fail("המלאי שמור להזמנה אחרת.", 409);
    const variant = (p.variants || []).find((v) => v.id === i.variantId);
    const stock = variant ? variant.stock : (p.sizeStock?.[i.size] ?? p.stock);
    if (stock < i.qty || p.stock < i.qty)
      fail("אין מספיק מלאי להשלמת ההזמנה.", 409);
    if (variant) {
      variant.stock -= i.qty;
      p.stock = p.variants.reduce((n, v) => n + v.stock, 0);
      p.sizeStock = Object.fromEntries(
        p.sizes.map((s) => [
          s,
          p.variants
            .filter((v) => v.size === s)
            .reduce((n, v) => n + v.stock, 0),
        ]),
      );
    } else {
      p.stock -= i.qty;
      if (p.sizeStock) p.sizeStock[i.size] -= i.qty;
    }
    changed.set(p.id, p);
    statements.push(
      statement(
        db,
        "stock_movements",
        crypto.randomUUID(),
        {
          productId: p.id,
          size: i.size,
          variantId: i.variantId,
          delta: -i.qty,
          reason: "תשלום הזמנה " + orderId,
          createdAt: rev,
          system: true,
        },
        rev,
      ),
    );
  }
  for (const p of changed.values()) {
    const expected = p.revision;
    delete p.revision;
    statements.push(
      db
        .prepare(
          "UPDATE products SET data=?,updated_at=? WHERE id=? AND updated_at=?",
        )
        .bind(JSON.stringify(p), rev, p.id, expected),
    );
    statements.push(...stockGuards(env, p, orderId, "paid-holds:" + orderId));
    guard.push(p.id);
  }
  const paid = {
    ...order,
    paymentStatus: "paid",
    paid: true,
    paidAt: rev,
    paymentReference: reference,
    paymentSource: source,
    paymentDetails: cleanPayment(details),
    invoiceDocumentId: orderId,
    status: "processing",
  };
  delete paid.revision;
  statements.push(
    db
      .prepare(
        "UPDATE orders SET data=?,updated_at=? WHERE id=? AND updated_at=?",
      )
      .bind(JSON.stringify(paid), rev, orderId, order.revision),
  );
  guard.push("ORDER:" + orderId);
  statements.push(
    db
      .prepare("INSERT INTO payment_transactions(id,order_id) VALUES(?,?)")
      .bind(source + ":" + reference, orderId),
  );
  const business = await setting(db, "invoice"),
    docId = orderId;
  if (invoiceComplete(business)) {
    const sequence = await db
      .prepare(
        "INSERT INTO document_sequences(id,value) VALUES('transaction',1) ON CONFLICT(id) DO UPDATE SET value=value+1 RETURNING value",
      )
      .first();
    const vatRate = Number(business.vatRate || 0),
      doc = {
        id: docId,
        number:
          (business.numberPrefix || "SHEM-") +
          String(sequence.value).padStart(6, "0"),
        business,
        customer: order.customer,
        items: order.items,
        orderId,
        issuedAt: rev,
        ...Object.fromEntries(
          [
            "subtotalCents",
            "shippingCents",
            "discountCents",
            "totalCents",
            "shippingMethod",
            "shippingLabel",
            "vatRate",
            "vatItemsCents",
            "vatShippingCents",
          ].map((k) => [k, order[k]]),
        ),
        vatCents:
          business.vatMode === "included"
            ? Math.round((order.totalCents * vatRate) / (100 + vatRate))
            : 0,
        paymentReference: reference,
      };
    statements.push(
      db
        .prepare(
          "INSERT INTO invoice_documents(id,data,updated_at) VALUES(?,?,?)",
        )
        .bind(docId, JSON.stringify(doc), rev),
    );
    if (env.INVOICE_DELIVERY_MODE !== "download") statements.push(
      statement(
        db,
        "mail_jobs",
        docId,
        {
          id: docId,
          documentId: docId,
          recipient: order.customer.email,
          status: "pending",
          attempts: 0,
          createdAt: rev,
        },
        rev,
      ),
    );
  } else {
    paid.invoiceStatus = "missing-business-details";
    statements.push(
      db
        .prepare("UPDATE orders SET data=? WHERE id=? AND updated_at=?")
        .bind(JSON.stringify(paid), orderId, rev),
    );
  }
  if (env.INVOICE_DELIVERY_MODE !== "download") statements.push(
    statement(
      db,
      "mail_jobs",
      "manager:" + orderId,
      {
        id: "manager:" + orderId,
        kind: "manager-order",
        orderId,
        documentData: {
          ...paid,
          business,
          orderId,
          number: orderId,
          issuedAt: rev,
        },
        recipient: env.ADMIN_CONTACT_EMAIL || "your.shem88@gmail.com",
        status: "pending",
        attempts: 0,
        createdAt: rev,
      },
      rev,
    ),
  );
  const loyalty = await setting(db, "loyalty"),
    profiles = await list(db, "member_profiles"),
    profile = profiles.find(
      (p) =>
        p.email === order.customer.email &&
        p.status === "active" &&
        !p.archived,
    );
  if (profile && loyalty.enabled) {
    const eligible = order.items
      .filter(
        (i) => !loyalty.excludeSale || !changed.get(i.productId)?.previousPrice,
      )
      .reduce((n, i) => n + i.qty * i.priceCents, 0);
    const base = order.subtotalCents
      ? Math.round(
          (eligible * (order.subtotalCents - order.discountCents)) /
            order.subtotalCents,
        )
      : 0;
    const earn = Math.round((base * Number(loyalty.earnPercent || 0)) / 100);
    if (earn)
      statements.push(
        statement(
          db,
          "loyalty_ledger",
          orderId,
          {
            memberId: profile.id,
            orderId,
            deltaCents: earn,
            type: "earned",
            availableAt: new Date(
              Date.now() + Number(loyalty.pendingDays ?? 14) * 86400000,
            ).toISOString(),
            expiresAt: new Date(
              Date.now() + Number(loyalty.expiryDays || 365) * 86400000,
            ).toISOString(),
            createdAt: rev,
            system: true,
          },
          rev,
        ),
      );
  }
  // A CHECK failure rolls back the entire D1 batch if any concurrent update lost its revision.
  const conditions = guard.map((id) =>
    id.startsWith("ORDER:")
      ? `EXISTS(SELECT 1 FROM orders WHERE id=? AND updated_at=?)`
      : `EXISTS(SELECT 1 FROM products WHERE id=? AND updated_at=?)`,
  );
  const binds = guard.flatMap((id) => [id.replace(/^ORDER:/, ""), rev]);
  statements.push(
    db
      .prepare(
        `INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN ${conditions.join(" AND ")} THEN 1 ELSE 0 END`,
      )
      .bind("payment:" + orderId, ...binds),
  );
  statements.push(
    db
      .prepare(
        "UPDATE channel_reservations SET state='committed' WHERE reservation_id=? AND state='held'",
      )
      .bind(orderId),
  );
  await db.batch(statements);
  return { ...paid, revision: rev };
}
export async function processMail(env) {
  if (env.INVOICE_DELIVERY_MODE === "download")
    return { sent: 0, blocked: "מסמכי ההזמנה זמינים להורדה באתר; דיוור כבוי." };
  if (!env.RESEND_API_KEY || !env.MAIL_FROM)
    return { sent: 0, blocked: "נדרשים שירות מייל ודומיין שולח מאומת." };
  const jobs = (await list(env.DB, "mail_jobs"))
    .filter(
      (j) =>
        j.status === "pending" ||
        (j.status === "sending" &&
          Date.now() - Date.parse(j.lastAttemptAt || j.revision) > 300000 &&
          Date.now() - Date.parse(j.firstAttemptAt || j.revision) <
            23 * 3600000) ||
        (j.status === "failed" &&
          j.attempts < 5 &&
          (!j.firstAttemptAt ||
            Date.now() - Date.parse(j.firstAttemptAt) < 23 * 3600000) &&
          (!j.nextAttemptAt || j.nextAttemptAt <= now())),
    )
    .slice(0, 10);
  let sent = 0;
  for (const job of jobs) {
    const started = now();
    job.firstAttemptAt = job.firstAttemptAt || started;
    job.lastAttemptAt = started;
    const claim = await env.DB.prepare(
      "UPDATE mail_jobs SET data=?,updated_at=? WHERE id=? AND updated_at=?",
    )
      .bind(
        JSON.stringify({ ...job, status: "sending" }),
        started,
        job.id,
        job.revision,
      )
      .run();
    if (!claim.meta.changes) continue;
    try {
      const manager = job.kind === "manager-order";
      const doc = manager
        ? job.documentData
        : await get(env.DB, "invoice_documents", job.documentId);
      if (!doc) throw Error("Missing document");
      const attachment = await storedDocumentPDF(
        env,
        doc,
        manager ? "order" : "invoice",
      );
      const r = await fetch("https://api.resend.com/emails", {
        method: "POST",
        headers: {
          Authorization: "Bearer " + env.RESEND_API_KEY,
          "Content-Type": "application/json",
          "Idempotency-Key": "shem-invoice-" + job.id,
        },
        body: JSON.stringify({
          from: env.MAIL_FROM,
          to: [job.recipient],
          subject: manager
            ? "SHEM - הזמנה ששולמה " + doc.orderId
            : "SHEM - חשבונית עסקה " + doc.number,
          html: manager
            ? `<div dir="rtl"><h2>SHEM - הזמנה חדשה ששולמה</h2><p>פרטי ההזמנה, הלקוח והמשלוח מצורפים במסמך A4.</p></div>`
            : `<div dir="rtl"><h2>תודה שבחרת SHEM</h2><p>חשבונית העסקה שלך מצורפת בקובץ PDF בגודל A4.</p><p>מספר מסמך: ${esc(doc.number)}</p></div>`,
          attachments: [
            {
              filename:
                (manager ? "SHEM-order-" : "SHEM-invoice-") +
                doc.orderId +
                ".pdf",
              content: base64PDF(attachment),
              content_type: "application/pdf",
            },
          ],
        }),
      });
      if (!r.ok) throw Error("שירות המייל החזיר " + r.status);
      const result = await r.json();
      await save(env.DB, "mail_jobs", job.id, {
        ...job,
        status: "sent",
        sentAt: now(),
        providerId: result.id,
        attempts: job.attempts + 1,
      });
      sent++;
    } catch (e) {
      await save(env.DB, "mail_jobs", job.id, {
        ...job,
        status: "failed",
        error: "שליחה נכשלה. יש לבדוק חיבור ספק.",
        attempts: job.attempts + 1,
        nextAttemptAt: new Date(
          Date.now() + 60000 * 2 ** job.attempts,
        ).toISOString(),
      });
    }
  }
  return { sent };
}
export async function management(request, env) {
  const path = new URL(request.url).pathname,
    method = request.method,
    db = env.DB;
  if (path === "/api/admin/manage/status" && method === "GET") {
    const b = await setting(db, "invoice"),
      home = await setting(db, "home"),
      operations = await setting(db, "operations"),
      products = await list(db, "products"),
      orders = await list(db, "orders");
    return json({
      database: true,
      media: !!env.BUCKET,
      invoiceComplete: invoiceComplete(b),
      mailConfigured: !!(env.RESEND_API_KEY && env.MAIL_FROM),
      homeConfigured: !!(env.HOME_CHECKOUT_URL && env.HOME_PAYMENT_SECRET),
      homeEnabled: home.enabled === true,
      unmapped: products.filter(
        (p) =>
          !p.archived && (!p.homeSku || p.variants?.some((v) => !v.homeSku)),
      ).length,
      lowStock: products
        .filter(
          (p) =>
            !p.archived &&
            p.stock > 0 &&
            p.stock <= Number(operations.lowStockThreshold ?? 3),
        )
        .map((p) => ({ id: p.id, name: p.he, stock: p.stock })),
      outOfStock: products.filter((p) => !p.archived && p.stock === 0).length,
      archivedProducts: products.filter((p) => p.archived).length,
      openReturns: (await list(db, "requests")).filter(
        (r) =>
          !r.archived && r.status !== "resolved" && r.status !== "rejected",
      ).length,
      unpaid: orders.filter((o) => o.paymentStatus !== "paid" && !o.archived)
        .length,
      failedMail: (await list(db, "mail_jobs")).filter(
        (j) => j.status === "failed",
      ).length,
      checkedAt: now(),
    });
  }
  if (
    [
      "/api/admin/manage/template-preview",
      "/api/admin/manage/template-preview.pdf",
    ].includes(path) &&
    method === "GET"
  ) {
    const b = await setting(db, "invoice");
    const doc = {
      number: "תצוגה לדוגמה בלבד",
      business: b,
      customer: {
        name: "לקוח לדוגמה",
        email: "example@example.test",
        phone: "0500000000",
        city: "עיר לדוגמה",
        street: "רחוב לדוגמה 1",
      },
      items: [
        {
          name: "פריט לדוגמה",
          sku: "EXAMPLE",
          size: "M",
          color: "שחור",
          qty: 1,
          priceCents: 19900,
        },
      ],
      orderId: "SAMPLE",
      issuedAt: now(),
      subtotalCents: 19900,
      discountCents: 0,
      shippingCents: 4900,
      totalCents: 24800,
      shippingMethod: "standard",
      vatCents:
        b.vatMode === "included"
          ? Math.round(
              (24800 * Number(b.vatRate || 0)) / (100 + Number(b.vatRate || 0)),
            )
          : 0,
      paymentReference: "דוגמה, לא בוצע תשלום",
    };
    if (path.endsWith(".pdf"))
      return new Response(await createDocumentPDF({ ...doc, vatRate: 18 }), {
        headers: {
          "content-type": "application/pdf",
          "cache-control": "no-store",
        },
      });
    return new Response(invoiceHTML(doc), {
      headers: {
        "content-type": "text/html; charset=utf-8",
        "cache-control": "no-store",
      },
    });
  }
  if (path === "/api/admin/manage/mail/process" && method === "POST")
    return json(await processMail(env));
  const sm = path.match(
    /^\/api\/admin\/manage\/settings\/(invoice|loyalty|home|operations|content|business)$/,
  );
  if (sm) {
    if (method === "GET") return json(await setting(db, sm[1]));
    if (method !== "PUT") fail("פעולה לא נתמכת.", 405);
    const d = await parse(request);
    let v = {};
    const key = sm[1];
    if (key === "invoice") {
      const currentInvoice = await setting(db, "invoice");
      if (currentInvoice.registrationNumberScope === "invoice") v.registrationNumberScope = "invoice";
      for (const k of [
        "legalName",
        "registrationNumber",
        "address",
        "phone",
        "email",
        "displayName",
        "documentTitle",
        "documentFooter",
        "numberPrefix",
        "vatMode",
      ])
        v[k] = str(d[k], k === "documentFooter" ? 1500 : 500);
      v.vatRate = Number(d.vatRate || 0);
      if (
        !["included", "exempt"].includes(v.vatMode) ||
        !Number.isFinite(v.vatRate) ||
        v.vatRate < 0 ||
        v.vatRate > 100
      )
        fail("מצב מע״מ אינו תקין.");
      if (v.email && !email(v.email)) fail("אימייל עסק אינו תקין.");
    }
    if (key === "loyalty") {
      for (const k of [
        "earnPercent",
        "maxRedeemPercent",
        "vipSpend",
        "vipOrders",
        "pendingDays",
        "expiryDays",
        "birthdayBenefit",
        "minRedemption",
      ]) {
        v[k] = Number(d[k] || 0);
        if (!Number.isFinite(v[k]) || v[k] < 0 || v[k] > 100000)
          fail("הגדרת הטבה אינה תקינה.");
      }
      if (
        v.expiryDays < 1 ||
        !Number.isInteger(v.pendingDays) ||
        !Number.isInteger(v.expiryDays) ||
        !Number.isInteger(v.vipOrders)
      )
        fail("ימי המתנה, תוקף ומספר הזמנות אינם תקינים.");
      if (v.earnPercent > 100 || v.maxRedeemPercent > 100)
        fail("אחוז הטבה חייב להיות בין 0 ל-100.");
      for (const k of ["enabled", "excludeSale", "allowStacking"])
        v[k] = d[k] === true;
      v.grossMarginPercent = Number(d.grossMarginPercent ?? 20);
      v.benefits = str(d.benefits, 2000);
    }
    if (key === "home") {
      for (const k of ["siteUrl", "displayName", "protocolVersion"])
        v[k] = str(d[k]);
      if (v.siteUrl && !/^https:\/\//.test(v.siteUrl))
        fail("כתובת אתר הבית חייבת להיות HTTPS.");
      v.protocolVersion = v.protocolVersion || "shem-home-v1";
      if (v.protocolVersion !== "shem-home-v1")
        fail("גרסת החיבור הנתמכת היא shem-home-v1.");
      if (v.siteUrl) {
        try {
          const u = new URL(v.siteUrl);
          if (u.username || u.password) fail("כתובת אתר הבית אינה תקינה.");
        } catch {
          fail("כתובת אתר הבית אינה תקינה.");
        }
      }
      v.enabled = d.enabled === true;
      v.priceAuthority = "manager";
      v.embedded = true;
    }
    if (key === "operations") {
      for (const k of [
        "standardPrice",
        "expressPrice",
        "standardDays",
        "expressDays",
        "lowStockThreshold",
      ]) {
        v[k] = Number(
          d[k] ??
            {
              standardPrice: 49,
              expressPrice: 199,
              standardDays: 7,
              expressDays: 1,
              lowStockThreshold: 3,
            }[k],
        );
        if (!Number.isFinite(v[k]) || v[k] < 0 || v[k] > 100000)
          fail("הגדרה תפעולית אינה תקינה.");
      }
      v.checkoutEnabled = d.checkoutEnabled === true;
      v.instagram = str(d.instagram || "new.shem", 100);
      v.whatsapp = str(d.whatsapp || "972529586766", 40);
      if (
        !/^[a-zA-Z0-9_.]{1,100}$/.test(v.instagram) ||
        !/^[0-9]{8,15}$/.test(v.whatsapp)
      )
        fail("פרטי אינסטגרם או וואטסאפ אינם תקינים.");
      v.serviceAreas = str(d.serviceAreas, 1000);
    }
    if (key === "business") {
      for (const k of [
        "legalName",
        "registrationNumber",
        "address",
        "phone",
        "accessibilityContact",
        "serviceAreas",
        "returnCollectionFee",
        "email",
        "displayName",
        "openingHours",
        "website",
        "taxStatus",
      ])
        v[k] = str(d[k], 1000);
    }
    if (key === "content") {
      v.heroTitle = str(d.heroTitle, 100);
      v.heroSubtitle = str(d.heroSubtitle, 300);
      v.arrivalsTitle = str(d.arrivalsTitle || "חדשים באתר שלנו", 100);
      v.saleTitle = str(d.saleTitle || "פריטים בסוף עונה", 100);
      v.announcement = str(d.announcement, 300);
      v.heroImages = (Array.isArray(d.heroImages) ? d.heroImages : [])
        .filter(
          (s) =>
            typeof s === "string" &&
            /^\/(assets|media)\/[\w./-]+$/.test(s) &&
            !s.includes(".."),
        )
        .slice(0, 3);
      v.blocks = (Array.isArray(d.blocks) ? d.blocks : [])
        .slice(0, 30)
        .map((b, i) => ({
          id: str(b.id) || crypto.randomUUID(),
          title: str(b.title, 150),
          text: str(b.text, 2000),
          link: /^#\//.test(b.link || "") ? str(b.link, 200) : "",
          label: str(b.label, 100),
          active: b.active === true,
          position: Number.isFinite(Number(b.position))
            ? Number(b.position)
            : i,
        }));
    }
    await db
      .prepare(
        "INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",
      )
      .bind(key, JSON.stringify(v), now())
      .run();
    return json(v);
  }
  const m = path.match(
    /^\/api\/admin\/manage\/(collections|customers|cards|requests|activities|orders|products|promotions|stock|loyalty|documents|mail)(?:\/([^/]+))?(?:\/(archive|restore|paid|payment-link|html|retry|redeem|release-credit|issue-document|birthday|pdf|order-pdf|payment-details))?$/,
  );
  if (!m) return null;
  const kind = m[1],
    table = tables[kind],
    id = m[2],
    action = m[3];
  if (method === "GET") {
    if (action === "pdf" || action === "order-pdf") {
      let doc;
      if (action === "pdf") doc = await get(db, "invoice_documents", id);
      else {
        const order = await get(db, "orders", id);
        if (!order) fail("הזמנה לא נמצאה.", 404);
        const invoice = await get(db, "invoice_documents", id);
        doc = {
          ...order,
          business: invoice?.business || (await setting(db, "invoice")),
          orderId: id,
          number: id,
          issuedAt: order.paidAt || order.createdAt,
        };
      }
      if (!doc) fail("מסמך לא נמצא.", 404);
      const output =
        action === "order-pdf" && doc.paymentStatus !== "paid"
          ? await createDocumentPDF(doc, "order")
          : await storedDocumentPDF(
              env,
              doc,
              action === "pdf" ? "invoice" : "order",
            );
      return new Response(output, {
        headers: {
          "content-type": "application/pdf",
          "content-disposition": 'inline; filename="SHEM-' + id + '.pdf"',
          "cache-control": "private,no-store",
          "x-content-type-options": "nosniff",
        },
      });
    }
    if (action === "html") {
      const doc = await get(db, "invoice_documents", id);
      if (!doc) fail("מסמך לא נמצא.", 404);
      return new Response(invoiceHTML(doc), {
        headers: {
          "content-type": "text/html; charset=utf-8",
          "cache-control": "no-store",
          "content-security-policy":
            "default-src 'none'; style-src 'unsafe-inline'; img-src data: https://shem-menswear.genial-comet-8090.chatgpt.site; frame-ancestors 'self'",
        },
      });
    }
    if (kind === "cards" && !id) {
      const cards = await list(db, table),
        orders = await list(db, "orders"),
        rules = await setting(db, "loyalty");
      return json(
        cards.map((c) => {
          const paid = orders.filter(
              (o) =>
                o.paymentStatus === "paid" &&
                o.customer?.email === c.email &&
                o.paidAt >= new Date(Date.now() - 365 * 86400000).toISOString(),
            ),
            spend =
              paid.reduce((n, o) => n + o.subtotalCents - o.discountCents, 0) /
              100;
          return {
            ...c,
            effectiveTier:
              c.tier === "vip" ||
              (rules.vipSpend > 0 && spend >= rules.vipSpend) ||
              (rules.vipOrders > 0 && paid.length >= rules.vipOrders)
                ? "vip"
                : "standard",
            annualSpend: spend,
            paidOrders: paid.length,
          };
        }),
      );
    }
    return json(id ? await get(db, table, id) : await list(db, table));
  }
  const d = await parse(request);
  let old = id ? await get(db, table, id) : null;
  if (id && !old) fail("רשומה לא נמצאה.", 404);
  if (kind === "orders" && action === "payment-details") {
    if (old.paymentStatus !== "paid")
      fail("פרטי תשלום מתועדים לאחר אישור תשלום.", 409);
    return json(
      await save(
        db,
        "orders",
        id,
        {
          ...old,
          paymentDetails: cleanPayment(d.paymentDetails || {}),
          paymentDetailsUpdatedAt: now(),
        },
        d.revision || old.revision,
      ),
    );
  }
  if (kind === "cards" && action === "birthday") {
    const rules = await setting(db, "loyalty"),
      date = new Date().toLocaleDateString("sv-SE", {
        timeZone: "Asia/Jerusalem",
      }),
      cents = amount(rules.birthdayBenefit || 0);
    if (
      old.archived ||
      old.status !== "active" ||
      !/^\d{4}-\d{2}-\d{2}$/.test(old.birthday || "") ||
      old.birthday.slice(5) !== date.slice(5) ||
      cents <= 0
    )
      fail("הטבת יום הולדת דורשת חבר פעיל, יום הולדת היום וסכום מוגדר.");
    const entryId = "birthday:" + id + ":" + date.slice(0, 4);
    if (await get(db, "loyalty_ledger", entryId))
      fail("הטבת יום הולדת כבר ניתנה השנה.", 409);
    const rev = now(),
      entry = {
        id: entryId,
        memberId: id,
        deltaCents: cents,
        type: "birthday",
        reason: "הטבת יום הולדת",
        availableAt: rev,
        createdAt: rev,
        expiresAt: new Date(
          Date.now() + Number(rules.expiryDays || 365) * 86400000,
        ).toISOString(),
        system: true,
      };
    await db
      .prepare("INSERT INTO loyalty_ledger(id,data,updated_at) VALUES(?,?,?)")
      .bind(entryId, JSON.stringify(entry), rev)
      .run();
    return json(entry, 201);
  }
  if (kind === "orders" && action === "issue-document") {
    if (old.paymentStatus !== "paid") fail("מסמך מופק רק להזמנה ששולמה.", 409);
    const existing = await get(db, "invoice_documents", id);
    if (existing)
      return json({ document: existing, mail: await processMail(env) });
    const business = await setting(db, "invoice");
    if (!invoiceComplete(business))
      fail("יש להשלים תחילה את פרטי העסק בתבנית חשבונית העסקה.", 409);
    const sequence = await db
      .prepare(
        "INSERT INTO document_sequences(id,value) VALUES('transaction',1) ON CONFLICT(id) DO UPDATE SET value=value+1 RETURNING value",
      )
      .first();
    const rev = now(),
      vatRate = Number(business.vatRate || 0),
      doc = {
        id,
        number:
          (business.numberPrefix || "SHEM-") +
          String(sequence.value).padStart(6, "0"),
        business,
        customer: old.customer,
        items: old.items,
        orderId: id,
        issuedAt: rev,
        ...Object.fromEntries(
          [
            "subtotalCents",
            "shippingCents",
            "discountCents",
            "totalCents",
            "shippingMethod",
            "shippingLabel",
            "vatRate",
            "vatItemsCents",
            "vatShippingCents",
          ].map((k) => [k, old[k]]),
        ),
        vatCents:
          business.vatMode === "included"
            ? Math.round((old.totalCents * vatRate) / (100 + vatRate))
            : 0,
        paymentReference: old.paymentReference,
      };
    await db.batch([
      db
        .prepare(
          "INSERT INTO invoice_documents(id,data,updated_at) VALUES(?,?,?)",
        )
        .bind(id, JSON.stringify(doc), rev),
      ...(env.INVOICE_DELIVERY_MODE === "download" ? [] : [statement(
        db,
        "mail_jobs",
        id,
        {
          id,
          documentId: id,
          recipient: old.customer.email,
          status: "pending",
          attempts: 0,
          createdAt: rev,
        },
        rev,
      )]),
      db
        .prepare(
          "UPDATE orders SET data=json_set(data,'$.invoiceStatus',?),updated_at=? WHERE id=?",
        )
        .bind(env.INVOICE_DELIVERY_MODE === "download" ? "available" : "queued", rev, id),
    ]);
    return json({ document: doc, mail: await processMail(env) }, 201);
  }
  if (kind === "orders" && action === "redeem") {
    if (old.paymentStatus === "paid" || old.redemptionId || old.archived)
      fail("ההזמנה אינה זמינה למימוש הטבה.", 409);
    const rules = await setting(db, "loyalty"),
      member = await get(db, "member_profiles", str(d.memberId)),
      cents = amount(d.amount);
    if (
      !rules.enabled ||
      !member ||
      member.archived ||
      member.status !== "active" ||
      member.email !== old.customer.email ||
      !Number.isInteger(cents) ||
      cents <= 0
    )
      fail("חבר, הזמנה או סכום הטבה אינם תקינים.");
    if (old.discountCents && !rules.allowStacking) fail("לא הוגדר כפל הטבות.");
    const availableLedger = (await list(db, "loyalty_ledger")).filter(
      (l) =>
        l.memberId === member.id &&
        l.availableAt <= now() &&
        (!l.expiresAt || l.expiresAt > now()),
    );
    const available = availableLedger.reduce((n, l) => n + l.deltaCents, 0);
    if (cents > available) fail("אין יתרת הטבה זמינה בסכום המבוקש.");
    const ps = await list(db, "products"),
      eligible = old.items
        .filter(
          (i) =>
            !rules.excludeSale ||
            !ps.find((p) => p.id === i.productId)?.previousPrice,
        )
        .reduce((n, i) => n + i.qty * i.priceCents, 0),
      cap = Math.floor((eligible * Number(rules.maxRedeemPercent || 0)) / 100);
    if (
      cents > cap ||
      cents < amount(rules.minRedemption || 0) ||
      cents > old.subtotalCents - old.discountCents
    )
      fail("סכום המימוש אינו עומד במינימום או בתקרה.");
    const rev = now(),
      entry = {
        id: crypto.randomUUID(),
        memberId: member.id,
        orderId: id,
        deltaCents: -cents,
        type: "redeemed",
        availableAt: rev,
        createdAt: rev,
        system: true,
      };
    const buckets = new Map();
    for (const l of availableLedger) {
      const key = l.expiresAt || "";
      buckets.set(key, (buckets.get(key) || 0) + l.deltaCents);
    }
    let remainder = cents;
    const allocations = [];
    for (const [expiresAt, balance] of [...buckets].sort(([a], [b]) =>
      (a || "9999").localeCompare(b || "9999"),
    )) {
      if (balance <= 0 || !remainder) continue;
      const used = Math.min(balance, remainder);
      remainder -= used;
      allocations.push({
        ...entry,
        id: allocations.length ? crypto.randomUUID() : entry.id,
        deltaCents: -used,
        ...(expiresAt ? { expiresAt } : {}),
      });
    }
    if (remainder) fail("יש לבדוק את התאמות היתרה לפני מימוש.", 409);
    allocations[0].allocations = allocations.map((a) => ({
      id: a.id,
      deltaCents: a.deltaCents,
      expiresAt: a.expiresAt || null,
    }));
    const next = {
      ...old,
      redemptionId: entry.id,
      loyaltyDiscountCents: cents,
      vatItemsCents: Math.round(
        ((old.subtotalCents - old.discountCents - cents) * 18) / 118,
      ),
      discountCents: old.discountCents + cents,
      totalCents: old.totalCents - cents,
      total: (old.totalCents - cents) / 100,
    };
    await db.batch([
      db
        .prepare(
          "UPDATE orders SET data=?,updated_at=? WHERE id=? AND updated_at=?",
        )
        .bind(JSON.stringify(next), rev, id, old.revision),
      ...allocations.map((a) => statement(db, "loyalty_ledger", a.id, a, rev)),
      db
        .prepare(
          "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN EXISTS(SELECT 1 FROM orders WHERE id=? AND updated_at=?) AND (SELECT COALESCE(SUM(json_extract(data,'$.deltaCents')),0) FROM loyalty_ledger WHERE json_extract(data,'$.memberId')=? AND json_extract(data,'$.availableAt')<=? AND (json_extract(data,'$.expiresAt') IS NULL OR json_extract(data,'$.expiresAt')>?))>=0 THEN 1 ELSE 0 END",
        )
        .bind("redeem:" + entry.id, id, rev, member.id, rev, rev),
    ]);
    return json({ ...next, revision: rev });
  }
  if (kind === "orders" && action === "release-credit") {
    if (old.paymentStatus === "paid" || !old.redemptionId)
      fail("אין מימוש פתוח לביטול.", 409);
    const entry = await get(db, "loyalty_ledger", old.redemptionId),
      rev = now(),
      next = {
        ...old,
        redemptionId: null,
        vatItemsCents: Math.round(
          ((old.subtotalCents - old.discountCents + old.loyaltyDiscountCents) *
            18) /
            118,
        ),
        discountCents: old.discountCents - old.loyaltyDiscountCents,
        totalCents: old.totalCents + old.loyaltyDiscountCents,
        total: (old.totalCents + old.loyaltyDiscountCents) / 100,
        loyaltyDiscountCents: 0,
      };
    await db.batch([
      db
        .prepare(
          "UPDATE orders SET data=?,updated_at=? WHERE id=? AND updated_at=?",
        )
        .bind(JSON.stringify(next), rev, id, old.revision),
      ...(entry.allocations || [entry]).map((a) =>
        statement(
          db,
          "loyalty_ledger",
          "release:" + a.id,
          {
            memberId: entry.memberId,
            orderId: id,
            deltaCents: -a.deltaCents,
            type: "released",
            availableAt: rev,
            createdAt: rev,
            system: true,
            ...(a.expiresAt ? { expiresAt: a.expiresAt } : {}),
          },
          rev,
        ),
      ),
      db
        .prepare(
          "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN EXISTS(SELECT 1 FROM orders WHERE id=? AND updated_at=?) THEN 1 ELSE 0 END",
        )
        .bind("release:" + entry.id, id, rev),
    ]);
    return json({ ...next, revision: rev });
  }
  if (kind === "mail" && action === "retry") {
    if (old.status === "sent") fail("המייל כבר התקבל אצל שירות המייל.", 409);
    if (
      old.firstAttemptAt &&
      Date.now() - Date.parse(old.firstAttemptAt) > 23 * 3600000
    )
      fail(
        "חלון מניעת כפילות פג. יש לאמת מול שירות המייל לפני שליחה נוספת.",
        409,
      );
    await save(
      db,
      table,
      id,
      { ...old, status: "pending", attempts: 0, nextAttemptAt: null },
      old.revision,
    );
    return json(await processMail(env));
  }
  if (action === "archive" || action === "restore") {
    if (["stock", "loyalty", "documents", "mail"].includes(kind))
      fail("תיעוד זה נשמר ללא מחיקה.", 409);
    if (kind === "orders" && old.redemptionId && old.paymentStatus !== "paid")
      fail("יש לבטל את מימוש ההטבה לפני ארכוב.", 409);
    if (
      kind === "orders" &&
      old.paymentStatus === "paid" &&
      action === "archive"
    )
      fail("הזמנה ששולמה נשמרת לתיעוד. אפשר לעדכן מצב טיפול.", 409);
    const next = {
      ...old,
      archived: action === "archive",
      archivedAt: action === "archive" ? now() : null,
    };
    if (
      ["products", "collections", "promotions"].includes(kind) &&
      action === "archive"
    ) {
      next.previousActive = next.active;
      next.active = false;
    }
    if (action === "restore" && next.previousActive !== undefined)
      next.active = next.previousActive;
    return json(await save(db, table, id, next, d.revision || old.revision));
  }
  if (kind === "orders" && action === "paid") {
    if (old.status === "cancelled")
      fail("הזמנה מבוטלת אינה ניתנת לתשלום.", 409);
    const result = await confirmPayment(
      env,
      id,
      str(d.reference),
      "manual-external",
      d.paymentDetails || {},
    );
    await processMail(env);
    return json(result);
  }
  if (kind === "orders" && action === "payment-link") {
    if (old.paymentStatus === "paid") fail("ההזמנה כבר שולמה.", 409);
    return json(
      await createHomeCheckout(env, old, new URL(request.url).origin),
    );
  }
  if (
    [
      "products",
      "promotions",
      "stock",
      "loyalty",
      "documents",
      "mail",
    ].includes(kind)
  )
    fail("יש להשתמש בפעולה הייעודית.", 405);
  let value = {
    id: id || crypto.randomUUID(),
    archived: false,
    createdAt: old?.createdAt || now(),
  };
  if (kind === "collections") {
    if (!str(d.title)) fail("נדרש שם קולקציה.");
    value = {
      ...value,
      title: str(d.title),
      description: str(d.description, 2000),
      active: d.active === true,
      position: Number(d.position || 0),
      productIds: [...new Set(Array.isArray(d.productIds) ? d.productIds : [])],
    };
    const ps = await list(db, "products");
    if (value.productIds.some((x) => !ps.some((p) => p.id === x)))
      fail("מוצר לא קיים בקולקציה.");
  }
  if (kind === "customers") {
    const c = cleanCustomer(d);
    const all = await list(db, table);
    if (all.some((x) => x.id !== id && x.email === c.email && !x.archived))
      fail("אימייל לקוח כבר קיים.", 409);
    value = {
      ...value,
      ...c,
      tags: str(d.tags),
      notes: str(d.notes, 3000),
      birthday: str(d.birthday, 30),
      preferredSize: str(d.preferredSize, 30),
    };
  }
  if (kind === "cards") {
    const c = cleanCustomer(d);
    if (!["standard", "vip"].includes(d.tier)) fail("דרגת חברות לא תקינה.");
    if (
      (await list(db, table)).some(
        (x) => x.email === c.email && x.id !== id && !x.archived,
      )
    )
      fail("חבר עם אימייל זה כבר קיים.", 409);
    value = {
      ...value,
      ...c,
      tier: d.tier,
      status: ["active", "suspended"].includes(d.status) ? d.status : "active",
      customerId: str(d.customerId),
      birthday: str(d.birthday, 30),
      notes: str(d.notes, 2000),
      cardNumber:
        old?.cardNumber ||
        "SHEM-" + value.id.replaceAll("-", "").slice(0, 12).toUpperCase(),
      marketingConsent: false,
    };
  }
  if (kind === "requests") {
    const c = cleanCustomer(d);
    if (
      !["support", "cancel", "return", "exchange"].includes(d.type) ||
      !["new", "reviewing", "collection", "resolved", "rejected"].includes(
        d.status,
      )
    )
      fail("סוג או מצב פנייה אינו תקין.");
    value = {
      ...value,
      ...c,
      type: d.type,
      status: d.status,
      orderNumber: str(d.orderNumber),
      message: str(d.message, 2000),
      notes: str(d.notes, 3000),
      collectionDate: str(d.collectionDate),
      tracking: str(d.tracking),
      resolution: str(d.resolution, 2000),
      source: "manual",
    };
  }
  if (kind === "activities") {
    if (!str(d.title) || !str(d.reason))
      fail("נדרשים כותרת וסיבה לפעילות ידנית.");
    value = {
      ...value,
      title: str(d.title),
      reason: str(d.reason, 2000),
      notes: str(d.notes, 3000),
      dueDate: str(d.dueDate),
      status: ["open", "done"].includes(d.status) ? d.status : "open",
      system: false,
      history: old
        ? [
            ...(old.history || []),
            {
              changedAt: now(),
              actor: request.headers.get("oai-authenticated-user-id"),
              title: old.title,
              reason: old.reason,
              notes: old.notes,
              status: old.status,
            },
          ].slice(-50)
        : [],
    };
  }
  if (kind === "orders") {
    const trackingUrl = str(d.trackingUrl, 500);
    if (
      trackingUrl &&
      (!/^https:\/\//.test(trackingUrl) ||
        (() => {
          try {
            const u = new URL(trackingUrl);
            return !!(u.username || u.password);
          } catch {
            return true;
          }
        })())
    )
      fail("קישור המעקב חייב להיות כתובת HTTPS תקינה.");
    if (
      d.estimatedDelivery &&
      (!/^\d{4}-\d{2}-\d{2}$/.test(d.estimatedDelivery) ||
        Number.isNaN(Date.parse(d.estimatedDelivery)))
    )
      fail("תאריך אספקה אינו תקין.");
    if (old?.redemptionId && old.paymentStatus !== "paid")
      fail("יש לבטל את מימוש ההטבה לפני עריכת הזמנה.", 409);
    if (old?.paymentStatus === "paid") {
      value = {
        ...old,
        status: ["processing", "shipped", "completed", "cancelled"].includes(
          d.status,
        )
          ? d.status
          : old.status,
        tracking: str(d.tracking),
        notes: str(d.notes, 2000),
      };
    } else
      value = {
        ...value,
        ...(await makeOrder(db, d)),
        paymentStatus: "unpaid",
        paid: false,
        status: ["draft", "new", "cancelled"].includes(d.status)
          ? d.status
          : "draft",
        source: "manual",
        tracking: str(d.tracking),
      };
    value.carrier = str(d.carrier, 100);
    value.trackingUrl = trackingUrl;
    value.estimatedDelivery = str(d.estimatedDelivery, 10);
    value.shipmentHistory = [
      ...(old?.shipmentHistory || []),
      ...(old?.status !== value.status
        ? [{ status: value.status, at: now() }]
        : []),
    ].slice(-30);
  }
  return json(
    await save(db, table, value.id, value, d.revision || old?.revision),
    id ? 200 : 201,
  );
}
export async function inventory(request, env) {
  if (
    new URL(request.url).pathname !== "/api/admin/manage/inventory" ||
    request.method !== "POST"
  )
    return null;
  const d = await parse(request),
    p = await get(env.DB, "products", str(d.productId));
  if (!p || p.archived) fail("מוצר לא נמצא.", 404);
  if (isHome(env) && (await linkedGuard(env, p.id)))
    fail("המלאי של מוצר מקושר מנוהל ב-SHEM.", 409);
  const delta = Number(d.delta);
  if (
    !Number.isInteger(delta) ||
    delta === 0 ||
    Math.abs(delta) > 100000 ||
    !str(d.reason)
  )
    fail("נדרשת כמות שינוי וסיבה.");
  const variant = p.variants?.find((v) => v.id === d.variantId);
  if (p.variants?.length && !variant) fail("בחרו גרסת מוצר.");
  const stock = variant ? variant.stock : (p.sizeStock?.[d.size] ?? p.stock);
  if (stock + delta < 0 || p.stock + delta < 0)
    fail("המלאי לא יכול להיות שלילי.");
  if (variant) {
    variant.stock += delta;
    p.stock = p.variants.reduce((n, v) => n + v.stock, 0);
    p.sizeStock = Object.fromEntries(
      p.sizes.map((s) => [
        s,
        p.variants.filter((v) => v.size === s).reduce((n, v) => n + v.stock, 0),
      ]),
    );
  } else {
    if (p.sizeStock) {
      if (!p.sizes.includes(d.size)) fail("מידה אינה קיימת.");
      p.sizeStock[d.size] += delta;
    }
    p.stock += delta;
  }
  const oldrev = p.revision,
    rev = now(),
    movement = {
      id: crypto.randomUUID(),
      productId: p.id,
      size: str(d.size),
      variantId: str(d.variantId),
      delta,
      reason: str(d.reason, 1000),
      createdAt: rev,
      system: false,
    };
  delete p.revision;
  await env.DB.batch([
    env.DB.prepare(
      "UPDATE products SET data=?,updated_at=? WHERE id=? AND updated_at=?",
    ).bind(JSON.stringify(p), rev, p.id, d.revision || oldrev),
    env.DB.prepare(
      "INSERT INTO stock_movements(id,data,updated_at) SELECT ?,?,? WHERE EXISTS(SELECT 1 FROM products WHERE id=? AND updated_at=?)",
    ).bind(movement.id, JSON.stringify(movement), rev, p.id, rev),
    ...stockGuards(env, p, "", "adjust:" + movement.id),
  ]);
  const saved = await get(env.DB, "products", p.id);
  if (saved.revision !== rev) fail("המלאי השתנה. רעננו לפני עדכון.", 409);
  return json({ product: saved, movement });
}
export async function adjustLoyalty(request, env) {
  if (
    new URL(request.url).pathname !== "/api/admin/manage/loyalty-adjust" ||
    request.method !== "POST"
  )
    return null;
  const d = await parse(request),
    member = await get(env.DB, "member_profiles", str(d.memberId)),
    deltaCents = amount(d.amount);
  if (
    !member ||
    member.archived ||
    !Number.isInteger(deltaCents) ||
    deltaCents === 0 ||
    Math.abs(deltaCents) > 1000000 ||
    !str(d.reason)
  )
    fail("נדרש חבר, סכום וסיבה תקינים.");
  const ledger = await list(env.DB, "loyalty_ledger"),
    balance = ledger
      .filter(
        (l) =>
          l.memberId === member.id &&
          l.availableAt <= now() &&
          (!l.expiresAt || l.expiresAt > now()),
      )
      .reduce((n, l) => n + l.deltaCents, 0);
  if (balance + deltaCents < 0) fail("היתרה לא יכולה להיות שלילית.");
  const entry = {
    id: crypto.randomUUID(),
    memberId: member.id,
    deltaCents,
    reason: str(d.reason),
    type: "manual",
    availableAt: now(),
    createdAt: now(),
    system: false,
  };
  const rev = now();
  await env.DB.batch([
    statement(env.DB, "loyalty_ledger", entry.id, entry, rev),
    env.DB.prepare(
      "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN (SELECT COALESCE(SUM(json_extract(data,'$.deltaCents')),0) FROM loyalty_ledger WHERE json_extract(data,'$.memberId')=? AND json_extract(data,'$.availableAt')<=? AND (json_extract(data,'$.expiresAt') IS NULL OR json_extract(data,'$.expiresAt')>?))>=0 THEN 1 ELSE 0 END",
    ).bind("adjust:" + entry.id, member.id, rev, rev),
  ]);
  return json({ ...entry, revision: rev }, 201);
}
export async function createHomeCheckout(env, order, origin) {
  const config = await setting(env.DB, "home");
  if (!config.enabled || !env.HOME_CHECKOUT_URL || !env.HOME_PAYMENT_SECRET)
    fail("אתר הבית טרם חובר. תשלום אינו זמין.", 503);
  const url = new URL(env.HOME_CHECKOUT_URL),
    site = new URL(config.siteUrl);
  if (url.protocol !== "https:" || url.origin !== site.origin)
    fail("כתובת החיבור לא תואמת לאתר הבית.", 409);
  if (order.items.some((i) => !i.homeSku))
    fail("חסר מיפוי מק״ט לאתר הבית.", 409);
  if (env.CHANNEL_SECRET) {
    for (const i of order.items) {
      const link = await linkedGuard(env, i.productId);
      if (!link || link.status !== "connected")
        fail("המוצר אינו מקושר ל-SHIMI.", 409);
      if (
        i.variantId &&
        !Object.values(link.variantMap || {}).includes(i.variantId)
      )
        fail("גרסת המוצר אינה מקושרת.", 409);
    }
  }
  const timestamp = String(Math.floor(Date.now() / 1000)),
    payload = JSON.stringify({
      protocol: "shem-home-v1",
      orderId: order.id,
      currency: "ILS",
      amountCents: order.totalCents,
      customer: order.customer,
      items: order.items,
      shippingCents: order.shippingCents,
      discountCents: order.discountCents,
      callbackUrl: origin + "/api/home/payment-callback",
    }),
    signature = await sign(env.HOME_PAYMENT_SECRET, timestamp + "." + payload);
  const r = await fetch(url.href, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "x-shem-timestamp": timestamp,
      "x-shem-signature": signature,
      ...(env.PEER_SERVICE_TOKEN
        ? { "OAI-Sites-Authorization": "Bearer " + env.PEER_SERVICE_TOKEN }
        : {}),
    },
    body: payload,
    redirect: "manual",
    signal: AbortSignal.timeout(15000),
  });
  if (!r.ok) fail("אתר הבית לא אישר יצירת תשלום.", 502);
  const data = await r.json(),
    frame = new URL(data.embedUrl);
  if (frame.protocol !== "https:" || frame.origin !== site.origin)
    fail("כתובת תשלום אינה תואמת לאתר הבית.", 502);
  return { embedUrl: frame.href, orderId: order.id };
}
export async function sign(secret, message) {
  const k = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["sign"],
  );
  return [
    ...new Uint8Array(
      await crypto.subtle.sign("HMAC", k, new TextEncoder().encode(message)),
    ),
  ]
    .map((x) => x.toString(16).padStart(2, "0"))
    .join("");
}
export async function paymentCallback(request, env) {
  try {
    if (!env.HOME_PAYMENT_SECRET || !env.DB)
      return json({ error: "חיבור תשלום אינו פעיל." }, 503);
    if (request.method !== "POST")
      return json({ error: "Method not allowed" }, 405);
    const timestamp = request.headers.get("x-shem-timestamp"),
      signature = request.headers.get("x-shem-signature");
    if (
      !/^\d+$/.test(timestamp || "") ||
      Math.abs(Date.now() / 1000 - Number(timestamp)) > 300
    )
      return json({ error: "Expired signature" }, 401);
    const raw = await request.text();
    if (raw.length > 100000) return json({ error: "Payload too large" }, 413);
    const expected = await sign(env.HOME_PAYMENT_SECRET, timestamp + "." + raw);
    if (!/^[a-f0-9]{64}$/.test(signature || ""))
      return json({ error: "Invalid signature" }, 401);
    let difference = 0;
    for (let i = 0; i < 64; i++)
      difference |= signature.charCodeAt(i) ^ expected.charCodeAt(i);
    if (difference) return json({ error: "Invalid signature" }, 401);
    const event = JSON.parse(raw),
      order = await get(env.DB, "orders", str(event.orderId));
    if (
      !order ||
      event.status !== "paid" ||
      event.currency !== "ILS" ||
      event.amountCents !== order.totalCents ||
      !str(event.transactionId)
    )
      return json({ error: "Payment mismatch" }, 409);
    if (order.paymentStatus === "paid") {
      if (order.paymentReference !== event.transactionId)
        return json({ error: "Transaction mismatch" }, 409);
      await processMail(env);
      return json({ ok: true, duplicate: true });
    }
    await confirmPayment(
      env,
      order.id,
      str(event.transactionId),
      "home",
      event.paymentDetails || {},
    );
    await processMail(env);
    return json({ ok: true });
  } catch (e) {
    return json(
      { error: e.status ? e.message : "Payment processing failed" },
      e.status || 500,
    );
  }
}
export async function publicCommerce(request, env) {
  const path = new URL(request.url).pathname;
  if (path.startsWith("/api/checkout-status/") && request.method === "GET") {
    const order = await get(env.DB, "orders", path.split("/").pop());
    if (
      !order ||
      order.viewToken !== new URL(request.url).searchParams.get("token")
    )
      return json({ error: "Not found" }, 404);
    const doc = order.paymentStatus === "paid"
      ? await get(env.DB, "invoice_documents", order.id) : null;
    return json({
      paymentStatus: order.paymentStatus,
      orderId: order.id,
      invoiceReady: !!doc,
    });
  }
  if (path.startsWith("/api/checkout-invoice/") && request.method === "GET") {
    const url = new URL(request.url);
    const order = await get(env.DB, "orders", path.split("/").pop());
    const token = url.searchParams.get("token");
    if (!order || !token || !order.viewToken || order.viewToken !== token)
      return json({ error: "Not found" }, 404);
    if (order.paymentStatus !== "paid")
      return json({ error: "התשלום עדיין לא אושר." }, 409);
    const doc = await get(env.DB, "invoice_documents", order.id);
    if (!doc) return json({ error: "חשבונית העסקה עדיין אינה זמינה." }, 409);
    const output = await storedDocumentPDF(env, doc, "invoice");
    const filename = "SHEM-invoice-" + order.id.replace(/[^a-zA-Z0-9-]/g, "") + ".pdf";
    return new Response(output, { headers: {
      "content-type": "application/pdf",
      "content-disposition": (url.searchParams.get("download") === "1" ? "attachment" : "inline") + '; filename="' + filename + '"',
      "cache-control": "private,no-store",
      "referrer-policy": "no-referrer",
      "x-content-type-options": "nosniff",
    }});
  }
  if (path !== "/api/checkout-home" || request.method !== "POST") return null;
  const config = await setting(env.DB, "operations"),
    home = await setting(env.DB, "home");
  if (
    !config.checkoutEnabled ||
    !home.enabled ||
    !env.HOME_CHECKOUT_URL ||
    !env.HOME_PAYMENT_SECRET
  )
    fail("הרכישה עדיין אינה פעילה. אתר הבית טרם חובר.", 503);
  const d = await parse(request);
  if (d.termsAccepted !== true) fail("יש לאשר תנאי רכישה.");
  const customer = cleanCustomer(d.customer || {});
  if (!customer.phone || !customer.city || !customer.street)
    fail("נדרשים טלפון וכתובת משלוח.");
  const existing = await env.DB.prepare(
    "SELECT COUNT(*) n FROM orders WHERE json_extract(data,'$.customer.email')=? AND updated_at>?",
  )
    .bind(customer.email, new Date(Date.now() - 3600000).toISOString())
    .first();
  if (existing.n >= 10) fail("נוצרו הזמנות רבות בזמן קצר.", 429);
  const quoteResponse = await publicOperations(
    new Request(new URL("/api/quote", request.url), {
      method: "POST",
      body: JSON.stringify({
        items: d.items,
        shipping: d.shipping,
        code: d.code,
      }),
    }),
    env,
  );
  const quote = await quoteResponse.json();
  if (!quoteResponse.ok)
    fail(quote.error || "הסל אינו תקין.", quoteResponse.status);
  await validateReviewToken(env, d, quote);
  const draft = await makeOrder(env.DB, {
    ...d,
    items: d.items.map((i) => ({ ...i, productId: i.id || i.productId })),
    shippingCost:
      d.shipping === "express"
        ? (config.expressPrice ?? 199)
        : (config.standardPrice ?? 49),
    discount: quote.discount,
    shippingMethod: d.shipping,
  });
  const id = crypto.randomUUID(),
    order = {
      ...draft,
      id,
      source: "storefront",
      status: "new",
      paymentStatus: "unpaid",
      paid: false,
      createdAt: now(),
      viewToken: crypto.randomUUID() + crypto.randomUUID(),
    };
  await save(env.DB, "orders", id, order);
  await reserveStock(env, id, order.items);
  try {
    const session = await createHomeCheckout(
      env,
      order,
      new URL(request.url).origin,
    );
    return json({ ...session, viewToken: order.viewToken }, 201);
  } catch (e) {
    await releaseStock(env, id);
    throw e;
  }
}
