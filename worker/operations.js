import { hydrateProducts } from "./channel.js";
const response = (data, status = 200) =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store",
    },
  });
const clean = (v, max = 200) =>
  typeof v === "string" ? v.trim().slice(0, max) : "";
const reject = (message, status = 400) => {
  throw Object.assign(new Error(message), { status });
};
const parse = async (request) => {
  const text = await request.text();
  if (text.length > 1000000) reject("הבקשה גדולה מדי.", 413);
  try {
    return JSON.parse(text);
  } catch {
    reject("בקשה לא תקינה.");
  }
};
const records = async (db, table) => {
  const rows = await db
    .prepare(
      `SELECT id,data,updated_at FROM ${table} ORDER BY updated_at DESC LIMIT 1000`,
    )
    .all();
  return rows.results.map((r) => ({
    ...JSON.parse(r.data),
    id: r.id,
    revision: r.updated_at,
  }));
};
const save = async (db, table, id, data, revision) => {
  const existing = await db
    .prepare(`SELECT updated_at FROM ${table} WHERE id=?`)
    .bind(id)
    .first();
  if (revision && existing && revision !== existing.updated_at)
    reject("הרשומה השתנתה מאז פתיחתה. רעננו ונסו שוב.", 409);
  const now = new Date().toISOString();
  await db
    .prepare(
      `INSERT INTO ${table}(id,data,updated_at) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at`,
    )
    .bind(id, JSON.stringify(data), now)
    .run();
  return { ...data, id, revision: now };
};
const mail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
export async function publicOperations(request, env) {
  const { pathname: path } = new URL(request.url),
    method = request.method;
  if (path === "/api/collections" && method === "GET")
    return response(
      (await records(env.DB, "collections"))
        .filter((c) => c.active && !c.archived)
        .sort((a, b) => (a.position || 0) - (b.position || 0)),
    );
  if (path === "/api/requests" && method === "POST") {
    const d = await parse(request),
      email = clean(d.email, 254).toLowerCase();
    if (
      !mail(email) ||
      !clean(d.name) ||
      !clean(d.orderNumber) ||
      !["cancel", "return", "exchange", "support"].includes(d.type) ||
      d.privacyAcknowledged !== true
    )
      reject(
        "יש להזין שם, אימייל, מספר הזמנה וסוג פנייה ולאשר את הודעת הפרטיות.",
      );
    const recent = await env.DB.prepare(
      "SELECT COUNT(*) AS count FROM requests WHERE json_extract(data,'$.email')=? AND updated_at>?",
    )
      .bind(email, new Date(Date.now() - 3600000).toISOString())
      .first();
    if (recent.count >= 5)
      reject("נשלחו מספר פניות בזמן קצר. אפשר לפנות גם במייל.", 429);
    const id = crypto.randomUUID();
    await save(env.DB, "requests", id, {
      id,
      name: clean(d.name),
      email,
      orderNumber: clean(d.orderNumber, 100),
      type: d.type,
      message: clean(d.message, 2000),
      status: "new",
      createdAt: new Date().toISOString(),
      privacyVersion: "2026-10-07",
    });
    return response({ ok: true, reference: id }, 201);
  }
  if (path === "/api/quote" && method === "POST") {
    const d = await parse(request);
    if (!Array.isArray(d.items) || !d.items.length || d.items.length > 100)
      reject("הסל אינו תקין.");
    const products = await hydrateProducts(
      env,
      (await records(env.DB, "products")).filter(p => d.items.some(i => i.id === p.id)),
    );
    let subtotal = 0;
    const items = [],
      quantities = new Map();
    for (const line of d.items) {
      const p = products.find(
        (p) => p.id === line.id && p.active !== false && !p.archived,
      );
      if (
        !p ||
        !p.sizes.includes(line.size) ||
        !Number.isInteger(line.qty) ||
        line.qty < 1 ||
        line.qty > 5
      )
        reject("פריט או כמות אינם זמינים.");
      const variant = p.variants?.find((v) => v.id === line.variantId);
      if (p.variants?.length && (!variant || variant.size !== line.size))
        reject("יש לבחור גרסת מידה וצבע תקינה.");
      const key = p.id + ":" + line.size + ":" + (line.variantId || "");
      if (quantities.has(key)) reject("פריט כפול בסל.");
      quantities.set(key, line.qty);
      const sizeStock = variant?.stock ?? p.sizeStock?.[line.size] ?? p.stock;
      if (line.qty > sizeStock) reject("אין מספיק מלאי במידה שנבחרה.");
      const productQty = d.items
        .filter((l) => l.id === p.id)
        .reduce((n, l) => n + l.qty, 0);
      if (productQty > p.stock) reject("אין מספיק מלאי לפריט.");
      items.push({ ...line, price: p.price, he: p.he });
      subtotal += Math.round(p.price * 100) * line.qty;
    }
    let discount = 0,
      promotion = null;
    const code = clean(d.code, 40).toUpperCase();
    if (code) {
      const all = await records(env.DB, "promotions");
      const p = all.find((p) => p.code === code && p.active && !p.archived);
      const dateParts = new Intl.DateTimeFormat("en-US", {
        timeZone: "Asia/Jerusalem",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).formatToParts(new Date());
      const part = (type) => dateParts.find((p) => p.type === type).value;
      const today = part("year") + "-" + part("month") + "-" + part("day");
      if (
        !p ||
        (p.startsAt && p.startsAt > today) ||
        (p.endsAt && p.endsAt < today)
      )
        reject("הקוד אינו פעיל או שפג תוקפו.");
      if (subtotal / 100 < (p.minSubtotal || 0))
        reject("סכום הסל נמוך מהמינימום לקוד.");
      const eligible = items
        .filter(
          (i) =>
            !p.excludeSale ||
            !products.find((p) => p.id === i.id)?.previousPrice,
        )
        .reduce((n, i) => n + Math.round(i.price * 100) * i.qty, 0);
      if (!eligible) reject("הקוד אינו חל על הפריטים שבסל.");
      discount = Math.min(eligible, Math.round((eligible * p.discount) / 100));
      promotion = { code: p.code, discount: p.discount };
    }
    if (!["standard", "express"].includes(d.shipping || "standard"))
      reject("שיטת משלוח לא תקינה.");
    const opRow = await env.DB.prepare(
      "SELECT value FROM settings WHERE key='operations'",
    ).first();
    const op = opRow ? JSON.parse(opRow.value) : {};
    const shipping =
      d.shipping === "express"
        ? (op.expressPrice ?? 199)
        : (op.standardPrice ?? 49);
    return response({
      items,
      subtotal: subtotal / 100,
      discount: discount / 100,
      shipping,
      vatRate: 18,
      vatIncluded: Math.round(((subtotal - discount) * 18) / 118) / 100,
      netSubtotal:
        (subtotal - discount - Math.round(((subtotal - discount) * 18) / 118)) /
        100,
      total: (subtotal - discount) / 100 + shipping,
      promotion,
      quotedAt: new Date().toISOString(),
      paymentConnected: false,
    });
  }
  return null;
}
export async function adminOperations(request, env) {
  const { pathname: path } = new URL(request.url),
    method = request.method;
  if (path === "/api/admin/integration-status" && method === "GET")
    return response({
      payment: false,
      invoicing: false,
      automatic: false,
      reason: "נדרשים בחירת ספקים, מתאמים רשמיים ופרטי גישה.",
    });
  if (path === "/api/admin/audit" && method === "GET") {
    const rows = await env.DB.prepare(
      "SELECT id,actor,action,created_at FROM audit ORDER BY created_at DESC LIMIT 200",
    ).all();
    return response(rows.results);
  }
  if (path === "/api/admin/health" && method === "GET") {
    await env.DB.prepare("SELECT 1").first();
    const business = await env.DB.prepare(
      "SELECT value FROM settings WHERE key='business'",
    ).first();
    const b = business ? JSON.parse(business.value) : {};
    return response({
      database: true,
      media: !!env.BUCKET,
      payments: false,
      warehouse: false,
      businessComplete: !!(
        b.legalName &&
        b.registrationNumber &&
        b.address &&
        b.phone
      ),
      supportEmail: env.ADMIN_CONTACT_EMAIL || "your.shem88@gmail.com",
      checkedAt: new Date().toISOString(),
    });
  }
  const match = path.match(
    /^\/api\/admin\/(collections|customers|requests|member-profiles)(?:\/([^/]+))?$/,
  );
  if (!match) return null;
  const table = match[1] === "member-profiles" ? "member_profiles" : match[1],
    id = match[2];
  if (method === "GET") return response(await records(env.DB, table));
  if (!["POST", "PUT"].includes(method))
    return response({ error: "פעולה אינה נתמכת." }, 405);
  const d = await parse(request),
    recordId = id || crypto.randomUUID();
  if (method === "PUT" && !id) reject("חסר מזהה.");
  if (id) {
    const exists = await env.DB.prepare(`SELECT id FROM ${table} WHERE id=?`)
      .bind(id)
      .first();
    if (!exists && table !== "member_profiles") reject("הרשומה לא נמצאה.", 404);
  }
  let value;
  if (table === "collections") {
    if (!clean(d.title)) reject("יש להזין שם קולקציה.");
    value = {
      id: recordId,
      title: clean(d.title),
      description: clean(d.description, 2000),
      productIds: [...new Set(Array.isArray(d.productIds) ? d.productIds : [])]
        .filter((x) => typeof x === "string")
        .slice(0, 1000),
      active: d.active === true,
    };
    const products = await records(env.DB, "products");
    if (value.productIds.some((id) => !products.some((p) => p.id === id)))
      reject("הקולקציה כוללת מוצר שאינו קיים.");
  }
  if (table === "customers") {
    const email = clean(d.email, 254).toLowerCase();
    if (!clean(d.name) || !mail(email)) reject("נדרשים שם ואימייל תקינים.");
    const duplicate = await env.DB.prepare(
      "SELECT id FROM customers WHERE lower(json_extract(data,'$.email'))=? AND id<>?",
    )
      .bind(email, recordId)
      .first();
    if (duplicate) reject("כרטיס לקוח עם אימייל זה כבר קיים.", 409);
    value = {
      id: recordId,
      name: clean(d.name),
      email,
      phone: clean(d.phone, 40),
      notes: clean(d.notes, 3000),
      tags: clean(d.tags, 200),
      createdAt: d.createdAt || new Date().toISOString(),
    };
  }
  if (table === "requests") {
    if (!id) reject("פניות מתקבלות מטופס השירות.", 409);
    const old = await env.DB.prepare("SELECT data FROM requests WHERE id=?")
      .bind(id)
      .first();
    if (
      !["new", "reviewing", "collection", "resolved", "rejected"].includes(
        d.status,
      )
    )
      reject("מצב פנייה לא תקין.");
    value = {
      ...JSON.parse(old.data),
      status: d.status,
      notes: clean(d.notes, 2000),
    };
  }
  if (table === "member_profiles") {
    if (
      !id ||
      !(await env.DB.prepare("SELECT id FROM members WHERE id=?")
        .bind(id)
        .first())
    )
      reject("חבר לא נמצא.", 404);
    if (!["standard", "vip"].includes(d.tier)) reject("דרגת מועדון לא תקינה.");
    const oldProfile = await env.DB.prepare(
      "SELECT data FROM member_profiles WHERE id=?",
    )
      .bind(id)
      .first();
    const member = await env.DB.prepare("SELECT email FROM members WHERE id=?")
      .bind(id)
      .first();
    value = {
      ...(oldProfile ? JSON.parse(oldProfile.data) : {}),
      email: member.email,
      status: "active",
      id: recordId,
      name: clean(d.name),
      phone: clean(d.phone, 40),
      tier: d.tier,
      notes: clean(d.notes, 2000),
      cardNumber:
        "SHEM-" + recordId.replaceAll("-", "").slice(0, 12).toUpperCase(),
    };
  }
  return response(
    await save(env.DB, table, recordId, value, d.revision),
    method === "POST" ? 201 : 200,
  );
}
