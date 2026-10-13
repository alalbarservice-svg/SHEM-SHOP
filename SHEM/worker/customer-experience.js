import { setting } from "./management.js";
import { storedDocumentPDF } from "./documents.js";
import { hydrateProducts } from "./channel.js";

const reply = (data, status = 200) =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "private,no-store",
      "x-content-type-options": "nosniff",
    },
  });
const fail = (message, status = 400) => {
  throw Object.assign(new Error(message), { status });
};
const text = (value, max = 200) =>
  typeof value === "string" ? value.trim().slice(0, max) : "";
const stamp = () => new Date().toISOString();
const parse = async (request) => {
  const raw = await request.text();
  if (raw.length > 1000000) fail("הבקשה גדולה מדי.", 413);
  try {
    return JSON.parse(raw);
  } catch {
    fail("הבקשה אינה תקינה.");
  }
};
const identity = (request) => ({
  id: request.headers.get("oai-authenticated-user-id") || "",
  email: text(
    request.headers.get("oai-authenticated-user-email"),
    254,
  ).toLowerCase(),
});
const requireIdentity = (request) => {
  const user = identity(request);
  if (!user.id || !user.email)
    fail("יש להתחבר כדי לראות את ההזמנות וההטבות שלך.", 401);
  return user;
};
const row = async (db, table, id) => {
  const r = await db
    .prepare(`SELECT data,updated_at FROM ${table} WHERE id=?`)
    .bind(id)
    .first();
  return r ? { ...JSON.parse(r.data), id, updatedAt: r.updated_at } : null;
};
const asset = (value) =>
  !value ||
  (typeof value === "string" &&
    /^\/(assets|media)\/[a-zA-Z0-9_./-]+$/.test(value) &&
    !value.includes(".."));
export function productPresentation(data) {
  const images = Array.isArray(data.images) ? data.images : [];
  if (images.length > 3 || images.some((i) => !i || !asset(i.url) || !i.url))
    fail("ניתן להוסיף עד שלוש תמונות מוצר שהועלו לאתר.");
  const guide = data.sizeGuide || {};
  const columns = Array.isArray(guide.columns)
    ? guide.columns.map((c) => text(c, 60))
    : [];
  const rows = Array.isArray(guide.rows) ? guide.rows : [];
  if (
    columns.length > 6 ||
    rows.length > 30 ||
    columns.some((c) => !c) ||
    new Set(columns).size !== columns.length
  )
    fail("טבלת המידות אינה תקינה.");
  if (rows.length && !columns.length) fail("יש להוסיף לפחות עמודת מדידה אחת.");
  if (
    rows.some(
      (r) =>
        !text(r.size, 20) ||
        !Array.isArray(r.values) ||
        r.values.length !== columns.length,
    )
  )
    fail("יש להזין מידה וערך לכל עמודה בטבלת המידות.");
  if (new Set(rows.map((r) => text(r.size, 20))).size !== rows.length)
    fail("יש להסיר מידות כפולות בטבלה.");
  const colors = Array.isArray(data.colors) ? data.colors : [];
  if (colors.length > 1 && !data.variants?.length)
    fail("למוצר עם מספר צבעים יש להגדיר גרסאות מידה וצבע.");
  if (
    colors.length > 30 ||
    colors.some(
      (c) =>
        !text(c.name, 50) ||
        !/^#[0-9a-f]{6}$/i.test(c.hex || "") ||
        !asset(c.imageUrl),
    )
  )
    fail("יש להזין שם צבע וקוד צבע תקין.");
  if (new Set(colors.map((c) => text(c.name, 50))).size !== colors.length)
    fail("יש להסיר צבעים כפולים.");
  return {
    images: images.map((i) => ({ url: i.url, alt: text(i.alt, 160) })),
    sizeGuide: {
      columns,
      rows: rows.map((r) => ({
        size: text(r.size, 20),
        values: r.values.map((v) => text(String(v), 60)),
      })),
      instructions: text(guide.instructions, 1500),
      fit: text(guide.fit, 500),
      model: text(guide.model, 500),
    },
    colors: colors.map((c) => ({
      name: text(c.name, 50),
      hex: c.hex,
      imageUrl: c.imageUrl || "",
    })),
  };
}

export const defaultHelp = [
  {
    id: "sizes",
    category: "product",
    title: "איך בוחרים מידה וצבע?",
    answer:
      "בדף המוצר בחרו צבע ומידה. מדריך המידות מציג את המדידות שהוזנו עבור הפריט. אם חסר מידע או שיש ספק, פנו לשירות לפני ההזמנה.",
    action: "shop",
    active: true,
  },
  {
    id: "delivery",
    category: "delivery",
    title: "כמה עולה המשלוח ומתי הוא מגיע?",
    answer:
      "בחרו משלוח בעמוד פרטי ההזמנה. המחיר וזמן האספקה של האפשרות שנבחרה מוצגים לפני התשלום. פרטי המדיניות המלאים זמינים בעמוד תשלום ומשלוחים.",
    action: "payments",
    active: true,
  },
  {
    id: "tracking",
    category: "orders",
    title: "איך עוקבים אחר ההזמנה?",
    answer:
      "באזור שלי ניתן לראות הזמנות של החשבון המחובר. רכשתם כאורחים? השתמשו בקישור האישי שקיבלתם בסיום הרכישה. סטטוס המשלוח מתעדכן לפי המידע שהחנות קיבלה.",
    action: "account",
    active: true,
  },
  {
    id: "invoice",
    category: "orders",
    title: "איפה חשבונית העסקה?",
    answer:
      "לאחר אישור התשלום חשבונית העסקה זמינה בעמוד סיום הרכישה ובפרטי ההזמנה. ניתן לפתוח ולהוריד את קובץ ה-PDF למחשב או לטלפון.",
    action: "account",
    active: true,
  },
  {
    id: "exchange",
    category: "returns",
    title: "איך מבקשים החלפה?",
    answer:
      "להחלפה יש לפנות לתמיכה. הכינו את מספר ההזמנה, הפריט והמידה המבוקשת. ההחלפה תתואם עם נציג לפי המדיניות וזמינות המלאי.",
    action: "contact",
    active: true,
  },
  {
    id: "return",
    category: "returns",
    title: "איך מבקשים החזרה או ביטול?",
    answer:
      "ניתן להגיש בקשה דרך עמוד ביטול עסקה או לפנות לתמיכה. הגשת בקשה אינה אישור אוטומטי להחזר כספי. פרטי המדיניות מופיעים בעמוד ההחלפות וההחזרות.",
    action: "cancel",
    active: true,
  },
  {
    id: "payment",
    category: "payment",
    title: "התשלום לא הסתיים. מה לעשות?",
    answer:
      "אם מופיעה הודעה שמצב התשלום נבדק, המתינו לבדיקה ואל תשלמו שוב. אפשר לפתוח את ההזמנה האישית ולבדוק את הסטטוס. אם הוא עדיין אינו ברור, פנו לתמיכה עם מספר ההזמנה.",
    action: "account",
    active: true,
  },
  {
    id: "coupon",
    category: "club",
    title: "למה קוד ההטבה לא מתקבל?",
    answer:
      "קוד עשוי להיות מוגבל בתוקף, בסכום הסל, במוצרים או בשילוב עם מבצעים. ההודעה ליד הקוד תסביר אם אינו חל על הסל. ההטבות מחושבות לפני התשלום.",
    action: "club",
    active: true,
  },
].map((item) => ({
  ...item,
  placement: ["sizes", "exchange", "return"].includes(item.id)
    ? "product"
    : ["payment", "coupon"].includes(item.id)
      ? "checkout"
      : item.id === "delivery"
        ? "all"
        : "none",
}));

const customerOrder = async (env, order) => ({
  id: order.id,
  createdAt: order.createdAt || order.updatedAt,
  updatedAt: order.updatedAt,
  status: order.status,
  paymentStatus: order.paymentStatus,
  customer: order.customer,
  items: (order.items || []).map((i) => ({
    name: i.name,
    sku: i.sku,
    size: i.size,
    color: i.color,
    qty: i.qty,
    priceCents: i.priceCents,
    lineCents: i.lineCents,
  })),
  totalCents: order.totalCents || Math.round((order.total || 0) * 100),
  subtotalCents: order.subtotalCents,
  discountCents: order.discountCents,
  shippingCents: order.shippingCents,
  shippingMethod: order.shippingMethod,
  tracking: order.tracking || "",
  carrier: order.carrier || "",
  trackingUrl: /^https:\/\//.test(order.trackingUrl || "")
    ? order.trackingUrl
    : "",
  estimatedDelivery: order.estimatedDelivery || "",
  shipmentHistory: (order.shipmentHistory || []).map((i) => ({
    status: text(i.status, 100),
    at: text(i.at, 40),
  })),
  invoiceReady:
    order.paymentStatus === "paid" &&
    !!(await row(env.DB, "invoice_documents", order.id)),
});
const ownedOrder = async (request, env, id) => {
  const order = await row(env.DB, "orders", id),
    user = identity(request);
  const token = request.headers.get("authorization")?.replace(/^Bearer /, "");
  const authorized =
    order &&
    ((user.id &&
      user.email &&
      user.email === text(order.customer?.email, 254).toLowerCase()) ||
      (token && order.viewToken && token === order.viewToken));
  if (!authorized)
    fail(
      "לא ניתן לפתוח את ההזמנה. בדקו את הקישור האישי או התחברו לחשבון הרכישה.",
      404,
    );
  return order;
};
export async function shipmentEvent(request, env) {
  if (request.method !== "POST")
    return reply({ error: "Method not allowed" }, 405);
  if (!env.SHIPPING_WEBHOOK_SECRET)
    return reply({ error: "חיבור עדכוני משלוח עדיין אינו פעיל." }, 503);
  const raw = await request.text();
  if (raw.length > 20000) return reply({ error: "Request too large" }, 413);
  const timestamp = request.headers.get("x-shem-timestamp") || "",
    signature = request.headers.get("x-shem-signature") || "";
  if (
    !/^\d{13}$/.test(timestamp) ||
    Math.abs(Date.now() - Number(timestamp)) > 300000 ||
    !/^[0-9a-f]{64}$/i.test(signature)
  )
    return reply({ error: "Invalid signature" }, 401);
  const key = await crypto.subtle.importKey(
    "raw",
    new TextEncoder().encode(env.SHIPPING_WEBHOOK_SECRET),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["verify"],
  );
  const bytes = Uint8Array.from(signature.match(/../g), (n) => parseInt(n, 16));
  if (
    !(await crypto.subtle.verify(
      "HMAC",
      key,
      bytes,
      new TextEncoder().encode(timestamp + "." + raw),
    ))
  )
    return reply({ error: "Invalid signature" }, 401);
  let d;
  try {
    d = JSON.parse(raw);
  } catch {
    return reply({ error: "Invalid JSON" }, 400);
  }
  if (
    !text(d.eventId, 100) ||
    !text(d.orderId, 100) ||
    !["processing", "shipped", "completed"].includes(d.status)
  )
    return reply({ error: "Invalid event" }, 400);
  if (
    await env.DB.prepare("SELECT id FROM cx_shipment_events WHERE id=?")
      .bind(d.eventId)
      .first()
  )
    return reply({ ok: true, duplicate: true });
  const old = await row(env.DB, "orders", d.orderId);
  if (!old || old.paymentStatus !== "paid" || old.status === "cancelled")
    return reply({ error: "Order unavailable" }, 409);
  const trackingUrl = text(d.trackingUrl, 500);
  if (trackingUrl) {
    try {
      const u = new URL(trackingUrl);
      if (u.protocol !== "https:" || u.username || u.password)
        return reply({ error: "Invalid tracking URL" }, 400);
    } catch {
      return reply({ error: "Invalid tracking URL" }, 400);
    }
  }
  if (
    d.estimatedDelivery &&
    (!/^\d{4}-\d{2}-\d{2}$/.test(d.estimatedDelivery) ||
      Number.isNaN(Date.parse(d.estimatedDelivery)))
  )
    return reply({ error: "Invalid delivery date" }, 400);
  const rank = { new: 0, processing: 1, shipped: 2, completed: 3 };
  const status =
    rank[d.status] >= (rank[old.status || "new"] ?? 0) ? d.status : old.status;
  const updated = {
    ...old,
    shipmentEventId: d.eventId,
    status,
    carrier: text(d.carrier, 100) || old.carrier,
    tracking: text(d.tracking, 200) || old.tracking,
    trackingUrl: trackingUrl || old.trackingUrl,
    estimatedDelivery: text(d.estimatedDelivery, 10) || old.estimatedDelivery,
    shipmentHistory: [
      ...(old.shipmentHistory || []),
      ...(status !== old.status ? [{ status, at: stamp() }] : []),
    ].slice(-30),
  };
  delete updated.updatedAt;
  const t = new Date(
    Math.max(Date.now(), Date.parse(old.updatedAt) + 1),
  ).toISOString();
  try {
    await env.DB.batch([
      env.DB.prepare(
        "INSERT INTO cx_shipment_events(id,order_id,created_at) VALUES(?,?,?)",
      ).bind(d.eventId, d.orderId, t),
      env.DB.prepare(
        "UPDATE orders SET data=?,updated_at=? WHERE id=? AND updated_at=?",
      ).bind(JSON.stringify(updated), t, old.id, old.updatedAt),
      env.DB.prepare(
        "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN EXISTS(SELECT 1 FROM orders WHERE id=? AND updated_at=? AND json_extract(data,'$.shipmentEventId')=?) THEN 1 ELSE 0 END",
      ).bind("shipment:" + d.eventId, old.id, t, d.eventId),
      env.DB.prepare(
        "INSERT INTO audit(id,actor,action,created_at) VALUES(?,?,?,?)",
      ).bind(
        crypto.randomUUID(),
        "shipment-integration",
        "עדכון משלוח מאומת להזמנה " + old.id,
        t,
      ),
    ]);
  } catch {
    if (
      await env.DB.prepare("SELECT id FROM cx_shipment_events WHERE id=?")
        .bind(d.eventId)
        .first()
    )
      return reply({ ok: true, duplicate: true });
    return reply({ error: "Order changed; retry event" }, 409);
  }
  return reply({ ok: true, status });
}
export async function customerExperience(request, env, admin = false) {
  const url = new URL(request.url),
    path = url.pathname,
    method = request.method;
  if (path === "/api/help" && method === "GET")
    return reply(
      (await setting(env.DB, "help")).items?.filter((i) => i.active) ||
        defaultHelp,
    );
  if (path === "/api/customer/me" && method === "GET") {
    const user = identity(request);
    if (!user.id || !user.email)
      return reply({ signedIn: false, orders: [], notifications: [] });
    const rs = await env.DB.prepare(
      "SELECT id,data,updated_at FROM orders WHERE lower(json_extract(data,'$.customer.email'))=? AND COALESCE(json_extract(data,'$.archived'),0)=0 ORDER BY updated_at DESC LIMIT 100",
    )
      .bind(user.email)
      .all();
    const orders = await Promise.all(
      rs.results.map((r) =>
        customerOrder(env, {
          ...JSON.parse(r.data),
          id: r.id,
          updatedAt: r.updated_at,
        }),
      ),
    );
    const members = await env.DB.prepare(
      "SELECT id,data FROM member_profiles WHERE lower(json_extract(data,'$.email'))=? AND COALESCE(json_extract(data,'$.archived'),0)=0 LIMIT 1",
    )
      .bind(user.email)
      .first();
    const member = members ? JSON.parse(members.data) : null,
      rules = await setting(env.DB, "loyalty");
    let balance = 0,
      pending = 0;
    if (member) {
      const lr = await env.DB.prepare(
        "SELECT data FROM loyalty_ledger WHERE json_extract(data,'$.memberId')=?",
      )
        .bind(members.id)
        .all();
      for (const r of lr.results) {
        const e = JSON.parse(r.data);
        if (e.expiresAt && e.expiresAt <= stamp()) continue;
        if (e.availableAt && e.availableAt > stamp())
          pending += Number(e.deltaCents || 0);
        else balance += Number(e.deltaCents || 0);
      }
    }
    const watches = await env.DB.prepare(
      "SELECT id,data FROM cx_watches WHERE user_id=? ORDER BY updated_at DESC LIMIT 100",
    )
      .bind(user.id)
      .all();
    const notifications = [];
    const experience = await setting(env.DB, "experience");
    for (const r of watches.results) {
      const w = JSON.parse(r.data),
        raw = await row(env.DB, "products", w.productId),
        p = raw ? (await hydrateProducts(env, [raw]))[0] : null;
      const available =
        p &&
        p.active !== false &&
        !p.archived &&
        (w.variantId
          ? (p.variants || []).find((v) => v.id === w.variantId)?.stock > 0
          : (p.sizeStock?.[w.size] ?? p.stock) > 0);
      if (available)
        notifications.push({
          id: r.id,
          productId: p.id,
          title: p.he,
          size: w.size,
          color: w.color,
          kind: "back-in-stock",
        });
    }
    const pr = await env.DB.prepare(
      "SELECT data FROM cx_preferences WHERE user_id=?",
    )
      .bind(user.id)
      .first();
    return reply({
      signedIn: true,
      email: user.email,
      orders,
      notifications:
        experience.siteAlertsEnabled !== false &&
        (!pr || JSON.parse(pr.data).siteNotifications !== false)
          ? notifications
          : [],
      watches: watches.results.map((r) => ({
        id: r.id,
        ...JSON.parse(r.data),
      })),
      member: member
        ? {
            tier: member.tier,
            status: member.status,
            cardNumber: member.cardNumber,
            balanceCents: Math.max(0, balance),
            pendingCents: Math.max(0, pending),
            benefits: rules.benefits || "",
            enabled: rules.enabled === true,
          }
        : null,
      preferences: pr ? JSON.parse(pr.data) : { siteNotifications: true },
    });
  }
  const om = path.match(/^\/api\/customer\/orders\/([^/]+)(\/invoice)?$/);
  if (om && method === "GET") {
    const order = await ownedOrder(request, env, decodeURIComponent(om[1]));
    if (!om[2]) return reply(await customerOrder(env, order));
    if (order.paymentStatus !== "paid")
      fail("החשבונית תהיה זמינה לאחר אישור התשלום.", 409);
    const doc = await row(env.DB, "invoice_documents", order.id);
    if (!doc) fail("המסמך עדיין בהכנה. אפשר לרענן בעוד רגע.", 409);
    const bytes = await storedDocumentPDF(env, doc, "invoice");
    return new Response(bytes, {
      headers: {
        "content-type": "application/pdf",
        "content-disposition": `${url.searchParams.get("download") === "1" ? "attachment" : "inline"}; filename="SHEM-invoice-${order.id.replace(/[^a-zA-Z0-9-]/g, "")}.pdf"`,
        "cache-control": "private,no-store",
        "referrer-policy": "no-referrer",
      },
    });
  }
  if (path === "/api/customer/preferences" && method === "PUT") {
    const user = requireIdentity(request),
      d = await parse(request);
    const p = { siteNotifications: d.siteNotifications !== false };
    await env.DB.prepare(
      "INSERT INTO cx_preferences(user_id,data,updated_at) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at",
    )
      .bind(user.id, JSON.stringify(p), stamp())
      .run();
    return reply(p);
  }
  if (path === "/api/customer/watches" && method === "POST") {
    const user = requireIdentity(request),
      d = await parse(request),
      p = await row(env.DB, "products", text(d.productId, 100));
    if ((await setting(env.DB, "experience")).siteAlertsEnabled === false)
      fail("התראות חזרה למלאי אינן פעילות כרגע.", 409);
    if (!p || p.archived || p.active === false) fail("הפריט אינו זמין.", 404);
    const variantId = text(d.variantId, 100),
      size = text(d.size, 20),
      v = (p.variants || []).find((v) => v.id === variantId);
    if (
      (p.variants?.length && !v) ||
      !p.sizes.includes(size) ||
      (v && v.size !== size)
    )
      fail("יש לבחור מידה וצבע להתראה.");
    const id = user.id + ":" + p.id + ":" + (variantId || size);
    const value = {
      productId: p.id,
      variantId,
      size,
      color: v?.color || p.color,
      createdAt: stamp(),
    };
    const count = await env.DB.prepare(
      "SELECT COUNT(*) AS n FROM cx_watches WHERE user_id=?",
    )
      .bind(user.id)
      .first();
    if (
      count.n >= 100 &&
      !(await env.DB.prepare("SELECT id FROM cx_watches WHERE id=?")
        .bind(id)
        .first())
    )
      fail("ניתן לשמור עד מאה התראות.", 429);
    await env.DB.prepare(
      "INSERT INTO cx_watches(id,user_id,data,updated_at) VALUES(?,?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at",
    )
      .bind(id, user.id, JSON.stringify(value), stamp())
      .run();
    return reply({ ok: true }, 201);
  }
  if (path.startsWith("/api/customer/watches/") && method === "DELETE") {
    const user = requireIdentity(request);
    await env.DB.prepare("DELETE FROM cx_watches WHERE id=? AND user_id=?")
      .bind(
        decodeURIComponent(path.slice("/api/customer/watches/".length)),
        user.id,
      )
      .run();
    return reply({ ok: true });
  }
  if (path === "/api/experience/events" && method === "POST") {
    const d = await parse(request),
      allowed = [
        "product_view",
        "cart_add",
        "checkout_start",
        "review_ready",
        "checkout_error",
        "payment_open",
        "purchase_confirmed",
        "search_empty",
        "help_open",
      ];
    if (
      d.consent !== true ||
      !allowed.includes(d.event) ||
      !/^[a-zA-Z0-9-]{16,80}$/.test(d.sessionId || "")
    )
      fail("אירוע לא תקין.");
    const enabled =
      (await setting(env.DB, "experience")).analyticsEnabled !== false;
    if (!enabled) return reply({ recorded: false });
    const start = new Date(Date.now() - 3600000).toISOString(),
      cnt = await env.DB.prepare(
        "SELECT COUNT(*) AS n FROM cx_events WHERE session_id=? AND created_at>?",
      )
        .bind(d.sessionId, start)
        .first();
    if (cnt.n >= 120) return reply({ recorded: false }, 429);
    const detail = {
      route: [
        "home",
        "product",
        "shop",
        "checkout",
        "account",
        "help",
      ].includes(d.route)
        ? d.route
        : "other",
      productId: /^[a-zA-Z0-9_-]{1,100}$/.test(d.productId || "")
        ? d.productId
        : "",
      reason: [
        "network",
        "validation",
        "payment",
        "no-results",
        "unknown",
      ].includes(d.reason)
        ? d.reason
        : "",
    };
    await env.DB.prepare(
      "INSERT INTO cx_events(id,session_id,event,detail,created_at) VALUES(?,?,?,?,?)",
    )
      .bind(
        crypto.randomUUID(),
        d.sessionId,
        d.event,
        JSON.stringify(detail),
        stamp(),
      )
      .run();
    await env.DB.prepare("DELETE FROM cx_events WHERE created_at<?")
      .bind(new Date(Date.now() - 90 * 86400000).toISOString())
      .run();
    return reply({ recorded: true }, 201);
  }
  if (path === "/api/experience" && method === "GET") {
    const config = await setting(env.DB, "experience");
    return reply({
      analyticsEnabled: config.analyticsEnabled !== false,
      siteAlertsEnabled: config.siteAlertsEnabled !== false,
      externalMessagingConnected: false,
    });
  }
  if (!admin) return null;
  if (path === "/api/admin/experience" && method === "GET") {
    const help = await setting(env.DB, "help"),
      config = await setting(env.DB, "experience");
    const since = new Date(Date.now() - 30 * 86400000).toISOString();
    const events = await env.DB.prepare(
      "SELECT event,COUNT(*) AS events,COUNT(DISTINCT session_id) AS sessions FROM cx_events WHERE created_at>? GROUP BY event",
    )
      .bind(since)
      .all();
    const requests = await env.DB.prepare(
      "SELECT json_extract(data,'$.type') AS type,COUNT(*) AS count FROM requests WHERE updated_at>? GROUP BY type",
    )
      .bind(since)
      .all();
    return reply({
      help: help.items || defaultHelp,
      config: {
        analyticsEnabled: config.analyticsEnabled !== false,
        siteAlertsEnabled: config.siteAlertsEnabled !== false,
      },
      events: events.results,
      requests: requests.results,
      externalMessagingConnected: false,
      trackingConnected: !!env.SHIPPING_WEBHOOK_SECRET,
    });
  }
  if (path === "/api/admin/experience" && method === "PUT") {
    const d = await parse(request);
    if (!Array.isArray(d.help) || d.help.length > 100)
      fail("ניתן להוסיף עד מאה תשובות.");
    const help = d.help.map((i) => ({
      id: text(i.id, 100) || crypto.randomUUID(),
      title: text(i.title, 200),
      answer: text(i.answer, 4000),
      category: [
        "product",
        "orders",
        "delivery",
        "returns",
        "payment",
        "club",
      ].includes(i.category)
        ? i.category
        : "orders",
      placement: ["all", "product", "checkout", "none"].includes(i.placement)
        ? i.placement
        : "all",
      action: [
        "shop",
        "payments",
        "account",
        "contact",
        "cancel",
        "club",
      ].includes(i.action)
        ? i.action
        : "contact",
      active: i.active === true,
    }));
    if (
      help.some((i) => !i.title || !i.answer) ||
      new Set(help.map((i) => i.id)).size !== help.length
    )
      fail("יש להזין שאלה ותשובה ולהימנע ממזהים כפולים.");
    const config = {
      analyticsEnabled: d.config?.analyticsEnabled === true,
      siteAlertsEnabled: d.config?.siteAlertsEnabled !== false,
    };
    const t = stamp();
    await env.DB.batch([
      env.DB.prepare(
        "INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",
      ).bind("help", JSON.stringify({ items: help }), t),
      env.DB.prepare(
        "INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",
      ).bind("experience", JSON.stringify(config), t),
      env.DB.prepare(
        "INSERT INTO audit(id,actor,action,created_at) VALUES(?,?,?,?)",
      ).bind(
        crypto.randomUUID(),
        identity(request).id,
        "עדכון מרכז העזרה ושירות עצמי",
        t,
      ),
    ]);
    return reply({ ok: true });
  }
  return null;
}
