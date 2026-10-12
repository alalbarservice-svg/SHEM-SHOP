import { qaFixtures } from './qa-fixtures.js';
import {
  sign,
  setting,
  makeOrder,
  confirmPayment,
  processMail,
  cleanPayment,
} from "./management.js";
import { publicOperations } from "./operations.js";
const now = () => new Date().toISOString();
const j = (d, status = 200) =>
  new Response(JSON.stringify(d), {
    status,
    headers: {
      "content-type": "application/json",
      "cache-control": "no-store",
    },
  });
const fail = (m, s = 409) => {
  throw Object.assign(new Error(m), { status: s });
};
const read = async (db, t, id) => {
  const r = await db
    .prepare(`SELECT data,updated_at FROM ${t} WHERE id=?`)
    .bind(id)
    .first();
  return r ? { ...JSON.parse(r.data), revision: r.updated_at } : null;
};
const put = (db, t, id, d) =>
  db
    .prepare(
      `INSERT INTO ${t}(id,data,updated_at) VALUES(?,?,?) ON CONFLICT(id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at`,
    )
    .bind(id, JSON.stringify(d), now())
    .run();
const body = async (r) => {
  const s = await r.text();
  if (s.length > 200000) fail("בקשה גדולה מדי.", 413);
  try {
    return JSON.parse(s);
  } catch {
    fail("בקשה לא תקינה.", 400);
  }
};
export const isHome = (env) => env.SHOP_ROLE === "home";
function origin(env) {
  const u = new URL(isHome(env) ? env.SHEM_ORIGIN : env.SHIMI_ORIGIN);
  if (u.protocol !== "https:") fail("כתובת חנות לא תקינה.");
  return u.origin;
}
export function peerOrigins(env) {
  const values = [origin(env), isHome(env) ? env.SHEM_PUBLIC_ORIGIN : env.SHIMI_PUBLIC_ORIGIN];
  return [...new Set(values.filter(Boolean).map(value => {
    const u = new URL(value);
    if (u.protocol !== "https:") fail("כתובת חנות לא תקינה.");
    return u.origin;
  }))];
}
async function verify(r, secret, legacy = false) {
  if (!secret) fail("חיבור בין החנויות טרם הוגדר.", 503);
  const ts = r.headers.get(legacy ? "x-shem-timestamp" : "x-channel-timestamp"),
    sig = r.headers.get(legacy ? "x-shem-signature" : "x-channel-signature");
  if (!/^\d+$/.test(ts || "") || Math.abs(Date.now() / 1000 - Number(ts)) > 300)
    fail("תוקף חתימה פג.", 401);
  const raw = await r.text();
  if (raw.length > 200000) fail("בקשה גדולה מדי.", 413);
  const input = legacy
    ? ts + "." + raw
    : [ts, r.method, new URL(r.url).pathname, raw].join(".");
  const expected = await sign(secret, input);
  let diff = 0;
  if (!/^[a-f0-9]{64}$/.test(sig || "")) fail("חתימה חסרה.", 401);
  for (let i = 0; i < 64; i++)
    diff |= sig.charCodeAt(i) ^ expected.charCodeAt(i);
  if (diff) fail("חתימה שגויה.", 401);
  return JSON.parse(raw);
}
export async function peer(env, path, d) {
  const raw = JSON.stringify(d),
    ts = String(Math.floor(Date.now() / 1000)),
    url = origin(env) + path;
  const headers = {
    "content-type": "application/json",
    "x-channel-timestamp": ts,
    "x-channel-signature": await sign(
      env.CHANNEL_SECRET,
      [ts, "POST", path, raw].join("."),
    ),
  };
  if (env.PEER_SERVICE_TOKEN)
    headers["OAI-Sites-Authorization"] = "Bearer " + env.PEER_SERVICE_TOKEN;
  const r = await fetch(url, {
    method: "POST",
    headers,
    body: raw,
    redirect: "manual",
    signal: AbortSignal.timeout(15000),
  });
  if (r.status >= 300 && r.status < 400) fail("החנות המקושרת החזירה הפניה לא מאושרת.", 502);
  const result = await r.json();
  if (!r.ok)
    fail(
      result.error || "החנות המקושרת אינה זמינה.",
      r.status === 401 ? 502 : r.status,
    );
  return result;
}
export async function stockView(env, p, exclude = "") {
  const rows = await env.DB.prepare(
    "SELECT variant_id,size,SUM(qty) qty FROM channel_reservations WHERE product_id=? AND state='held' AND expires_at>? AND reservation_id<>? GROUP BY variant_id,size",
  )
    .bind(p.id, now(), exclude)
    .all();
  return applyHolds(p, rows.results);
}
function applyHolds(p, holds) {
  const total = holds.reduce((n, r) => n + r.qty, 0);
  return {
    ...p,
    stock: Math.max(0, p.stock - total),
    sizeStock: p.sizeStock
      ? Object.fromEntries(
          Object.entries(p.sizeStock).map(([s, n]) => [
            s,
            Math.max(
              0,
              n -
                holds
                  .filter((r) => r.size === s)
                  .reduce((a, r) => a + r.qty, 0),
            ),
          ]),
        )
      : undefined,
    variants: (p.variants || []).map((v) => ({
      ...v,
      stock: Math.max(
        0,
        v.stock -
          holds
            .filter((r) => r.variant_id === v.id)
            .reduce((a, r) => a + r.qty, 0),
      ),
    })),
  };
}
export async function reserveStock(env, id, items) {
  if (!items.length) return;
  const existing = await env.DB.prepare(
    "SELECT product_id,variant_id,size,qty,state,expires_at FROM channel_reservations WHERE reservation_id=? ORDER BY product_id,variant_id,size",
  )
    .bind(id)
    .all();
  const canonical = (arr) =>
    JSON.stringify(
      arr
        .map((x) => [
          x.productId || x.product_id,
          x.variantId || x.variant_id || "",
          x.size,
          Number(x.qty),
        ])
        .sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))),
    );
  if (existing.results.length) {
    if (canonical(existing.results) !== canonical(items))
      fail("מזהה הזמנה כבר משמש לסל אחר.");
    if (
      existing.results.every(
        (x) =>
          x.state === "committed" ||
          (x.state === "held" && x.expires_at > now()),
      )
    )
      return;
    fail("שמירת המלאי פגה. יש להתחיל הזמנה חדשה.");
  }
  const expires = new Date(Date.now() + 20 * 60000).toISOString(),
    stmts = [],
    group = new Map();
  for (const line of items) {
    const p =
      group.get(line.productId)?.p ||
      (await read(env.DB, "products", line.productId));
    if (!p || p.archived || p.active === false) fail("מוצר אינו זמין.");
    if (!Number.isInteger(line.qty) || line.qty < 1 || line.qty > 100)
      fail("כמות אינה תקינה.");
    const v = p.variants?.find((v) => v.id === line.variantId);
    if (
      (p.variants?.length && (!v || v.size !== line.size)) ||
      !p.sizes.includes(line.size)
    )
      fail("גרסת מוצר אינה תואמת.");
    if (
      line.priceCents !== undefined &&
      line.priceCents !== Math.round(p.price * 100)
    )
      fail("מחיר השתנה. יש לרענן את הסל.");
    let g = group.get(p.id);
    if (!g) {
      g = { p, lines: [] };
      group.set(p.id, g);
    }
    g.lines.push(line);
    stmts.push(
      env.DB.prepare(
        "INSERT INTO channel_reservations(reservation_id,product_id,variant_id,size,qty,state,expires_at) VALUES(?,?,?,?,?,'held',?)",
      ).bind(id, p.id, line.variantId || "", line.size, line.qty, expires),
    );
  }
  for (const { p, lines } of group.values()) {
    stmts.push(
      env.DB.prepare(
        "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN EXISTS(SELECT 1 FROM products WHERE id=? AND updated_at=?) AND (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND state='held' AND expires_at<=?)>=0 AND (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND state='held' AND expires_at>?)<=? THEN 1 ELSE 0 END",
      ).bind(
        "hold-total:" + id + ":" + p.id,
        p.id,
        p.revision,
        p.id,
        now(),
        p.id,
        now(),
        p.stock,
      ),
    );
    for (const l of lines) {
      const v = p.variants?.find((v) => v.id === l.variantId);
      const n = v?.stock ?? p.sizeStock?.[l.size] ?? p.stock;
      stmts.push(
        env.DB.prepare(
          "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND variant_id=? AND size=? AND state='held' AND expires_at>?)<=? THEN 1 ELSE 0 END",
        ).bind(
          "hold-line:" + id + ":" + p.id + ":" + (l.variantId || l.size),
          p.id,
          l.variantId || "",
          l.size,
          now(),
          n,
        ),
      );
    }
  }
  try {
    await env.DB.batch(stmts);
  } catch {
    const held = await env.DB.prepare(
      "SELECT COUNT(*) n FROM channel_reservations WHERE reservation_id=?",
    )
      .bind(id)
      .first();
    if (held.n) return reserveStock(env, id, items);
    fail("המלאי השתנה או אינו מספיק. רעננו את הסל.");
  }
}
export function stockGuards(env, p, exclude, tag) {
  const stmts = [
    env.DB.prepare(
      "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND state='held' AND expires_at>? AND reservation_id<>?)<=? THEN 1 ELSE 0 END",
    ).bind(tag + ":total:" + p.id, p.id, now(), exclude, p.stock),
  ];
  for (const v of p.variants || [])
    stmts.push(
      env.DB.prepare(
        "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND variant_id=? AND state='held' AND expires_at>? AND reservation_id<>?)<=? THEN 1 ELSE 0 END",
      ).bind(
        tag + ":variant:" + p.id + ":" + v.id,
        p.id,
        v.id,
        now(),
        exclude,
        v.stock,
      ),
    );
  if (!p.variants?.length && p.sizeStock)
    for (const [size, n] of Object.entries(p.sizeStock))
      stmts.push(
        env.DB.prepare(
          "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN (SELECT COALESCE(SUM(qty),0) FROM channel_reservations WHERE product_id=? AND size=? AND state='held' AND expires_at>? AND reservation_id<>?)<=? THEN 1 ELSE 0 END",
        ).bind(
          tag + ":size:" + p.id + ":" + size,
          p.id,
          size,
          now(),
          exclude,
          n,
        ),
      );
  return stmts;
}
export const releaseStock = (env, id) =>
  env.DB.prepare(
    "UPDATE channel_reservations SET state='released' WHERE reservation_id=? AND state='held'",
  )
    .bind(id)
    .run();
async function commitStock(env, id) {
  const rows = await env.DB.prepare(
    "SELECT * FROM channel_reservations WHERE reservation_id=?",
  )
    .bind(id)
    .all();
  if (!rows.results.length) fail("שמירת מלאי לא נמצאה.");
  if (rows.results.every((x) => x.state === "committed"))
    return { ok: true, duplicate: true };
  if (rows.results.some((x) => x.state !== "held" || x.expires_at <= now()))
    fail("נדרש בירור תשלום: שמירת המלאי פגה.");
  const stmts = [],
    products = new Map(),
    revision = now();
  for (const line of rows.results) {
    let p =
      products.get(line.product_id) ||
      (await read(env.DB, "products", line.product_id));
    if (!p || p.archived) fail("המוצר אינו זמין.");
    const v = p.variants?.find((v) => v.id === line.variant_id);
    if (
      (v?.stock ?? p.sizeStock?.[line.size] ?? p.stock) < line.qty ||
      p.stock < line.qty
    )
      fail("נדרש בירור מלאי.");
    if (v) v.stock -= line.qty;
    else if (p.sizeStock) p.sizeStock[line.size] -= line.qty;
    p.stock -= line.qty;
    if (v)
      p.sizeStock = Object.fromEntries(
        p.sizes.map((s) => [
          s,
          p.variants
            .filter((v) => v.size === s)
            .reduce((n, v) => n + v.stock, 0),
        ]),
      );
    products.set(p.id, p);
  }
  for (const p of products.values()) {
    const old = p.revision;
    delete p.revision;
    stmts.push(
      env.DB.prepare(
        "UPDATE products SET data=?,updated_at=? WHERE id=? AND updated_at=?",
      ).bind(JSON.stringify(p), revision, p.id, old),
    );
    stmts.push(
      env.DB.prepare(
        "INSERT INTO operation_guards(id,valid) SELECT ?,CASE WHEN EXISTS(SELECT 1 FROM products WHERE id=? AND updated_at=?) THEN 1 ELSE 0 END",
      ).bind("commit:" + id + ":" + p.id, p.id, revision),
    );
    stmts.push(
      env.DB.prepare(
        "INSERT INTO stock_movements(id,data,updated_at) VALUES(?,?,?)",
      ).bind(
        crypto.randomUUID(),
        JSON.stringify({
          productId: p.id,
          delta: -rows.results
            .filter((r) => r.product_id === p.id)
            .reduce((n, r) => n + r.qty, 0),
          reason: "רכישה בחנות SHIMI " + id,
          system: true,
          createdAt: revision,
        }),
        revision,
      ),
    );
  }
  for (const p of products.values())
    stmts.push(...stockGuards(env, p, id, "commit-holds:" + id));
  stmts.push(
    env.DB.prepare(
      "UPDATE channel_reservations SET state='committed' WHERE reservation_id=? AND state='held'",
    ).bind(id),
  );
  await env.DB.batch(stmts);
  return { ok: true };
}
async function source(env, id) {
  const p = await read(env.DB, "products", id);
  if (!p || p.archived) fail("מוצר המקור אינו זמין.", 404);
  const a = await stockView(env, p);
  return {
    id: p.id,
    sku: p.sku,
    price: p.price,
    previousPrice: p.previousPrice ?? null,
    revision: p.revision,
    active: p.active,
    availability: {
      stock: a.stock,
      sizeStock: a.sizeStock,
      variants: a.variants.map((v) => ({
        id: v.id,
        size: v.size,
        color: v.color,
        sku: v.sku,
        stock: v.stock,
      })),
    },
    sizes: p.sizes,
    color: p.color,
  };
}
async function syncLink(env, link, providedSource) {
  try {
    const s =
      providedSource ||
      (await peer(env, "/api/channel/source", { id: link.sourceId }));
    const p = await read(env.DB, "products", link.id);
    if (!p) fail("מוצר היעד חסר.");
    const variants = (p.variants || []).map((v) => {
      const sourceId = link.variantMap?.[v.id];
      const sv = s.availability.variants.find((x) => x.id === sourceId);
      if (!sv) fail("מיפוי מידה וצבע חסר או השתנה.");
      return { ...v, sku: sv.sku };
    });
    if (p.variants?.length === 0 && s.availability.variants.length)
      fail("יש ליצור ולמפות גרסאות יעד.");
    if (!p.sizes.every((x) => s.sizes.includes(x)))
      fail("מידות המוצר אינן תואמות.");
    const changed = {
      ...p,
      sku: s.sku,
      price: s.price,
      previousPrice: s.previousPrice || undefined,
      variants,
    };
    delete changed.revision;
    // Optimistic product update preserves concurrent editing of independent copy/images.
    const result = await env.DB.prepare(
      "UPDATE products SET data=?,updated_at=? WHERE id=? AND updated_at=?",
    )
      .bind(JSON.stringify(changed), now(), p.id, p.revision)
      .run();
    if (!result.meta.changes) fail("כרטיס המוצר השתנה. נסו לסנכרן שוב.");
    await put(env.DB, "product_links", link.id, {
      ...link,
      status: s.active === false ? "source-inactive" : "connected",
      lastSyncAt: now(),
      sourceRevision: s.revision,
      error: "",
    });
    return { product: changed, source: s };
  } catch (e) {
    await put(env.DB, "product_links", link.id, {
      ...link,
      status: "error",
      error: e.message,
      lastAttemptAt: now(),
    });
    throw e;
  }
}
function projection(p, a) {
  return {
    id: p.id,
    sku: p.sku,
    price: p.price,
    previousPrice: p.previousPrice ?? null,
    revision: p.revision,
    active: p.active,
    availability: {
      stock: a.stock,
      sizeStock: a.sizeStock,
      variants: a.variants.map((v) => ({
        id: v.id,
        size: v.size,
        color: v.color,
        sku: v.sku,
        stock: v.stock,
      })),
    },
    sizes: p.sizes,
    color: p.color,
  };
}
export async function hydrateProducts(env, products) {
  const held = await env.DB.prepare(
    "SELECT product_id,variant_id,size,SUM(qty) qty FROM channel_reservations WHERE state='held' AND expires_at>? GROUP BY product_id,variant_id,size",
  )
    .bind(now())
    .all();
  const local = (p) =>
    applyHolds(
      p,
      held.results.filter((r) => r.product_id === p.id),
    );
  if (!isHome(env)) return products.map(local);
  const rows = await env.DB.prepare("SELECT data FROM product_links").all(),
    links = rows.results.map((r) => JSON.parse(r.data)),
    byId = new Map(links.map((l) => [l.id, l])),
    snapshots = new Map();
  const needed = links.filter((l) => products.some((p) => p.id === l.id));
  for (let offset = 0; offset < needed.length; offset += 100) {
    const batch = needed.slice(offset, offset + 100);
    try {
      const d = await peer(env, "/api/channel/sources", {
        ids: batch.map((l) => l.sourceId),
      });
      for (const p of d.products) snapshots.set(p.id, p);
    } catch {}
  }
  const result = [];
  for (const p of products) {
    const link = byId.get(p.id);
    if (!link) {
      result.push(local(p));
      continue;
    }
    const failClosed = () => ({
      ...p,
      stock: 0,
      sizeStock: Object.fromEntries(p.sizes.map((x) => [x, 0])),
      variants: (p.variants || []).map((v) => ({ ...v, stock: 0 })),
      syncUnavailable: true,
    });
    const s = snapshots.get(link.sourceId);
    if (!s) {
      result.push(failClosed());
      continue;
    }
    try {
      if (
        !p.sizes.every((x) => s.sizes.includes(x)) ||
        (!p.variants?.length && s.availability.variants.length)
      )
        throw Error("mapping");
      const variants = (p.variants || []).map((v) => {
        const sv = s.availability.variants.find(
          (x) => x.id === link.variantMap[v.id],
        );
        if (!sv) throw Error("mapping");
        return { ...v, sku: sv.sku, stock: sv.stock };
      });
      const needsSync =
        p.sku !== s.sku ||
        p.price !== s.price ||
        Number(p.previousPrice || 0) !== Number(s.previousPrice || 0) ||
        variants.some((v, i) => v.sku !== p.variants[i].sku) ||
        link.status === "error";
      const base = needsSync ? (await syncLink(env, link, s)).product : p;
      result.push({
        ...base,
        price: s.price,
        previousPrice: s.previousPrice || undefined,
        active: p.active !== false && s.active !== false,
        stock: s.availability.stock,
        sizeStock: s.availability.sizeStock,
        variants,
      });
    } catch {
      result.push(failClosed());
    }
  }
  return result;
}
export async function linkedGuard(env, id) {
  return read(env.DB, "product_links", id);
}
async function admin(request, env, path) {
  if (path === "/api/admin/channel/config") {
    if (request.method === "GET") return j(await setting(env.DB, "gateway"));
    if (request.method !== "PUT") fail("Method not allowed", 405);
    const d = await body(request),
      c = {
        provider: String(d.provider || "")
          .trim()
          .slice(0, 100),
        collectorName: String(d.collectorName || "")
          .trim()
          .slice(0, 150),
        merchantId: String(d.merchantId || "")
          .trim()
          .slice(0, 100),
      };
    await env.DB.prepare(
      "INSERT INTO settings(key,value,updated_at) VALUES('gateway',?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",
    )
      .bind(JSON.stringify(c), now())
      .run();
    return j(c);
  }
  if (path === "/api/admin/channel/status")
    return j({
      role: isHome(env) ? "home" : "source",
      peerOrigin: isHome(env) ? env.SHEM_ORIGIN : env.SHIMI_ORIGIN,
      connected:
        !!env.CHANNEL_SECRET &&
        !!(isHome(env) ? env.SHEM_ORIGIN : env.SHIMI_ORIGIN),
      paymentReady: !!(
        isHome(env) &&
        env.PAYMENT_ADAPTER_ENABLED === "true" &&
        env.PAYMENT_ADAPTER_URL &&
        env.PAYMENT_ADAPTER_SECRET
      ),
      providerConfigured: false,
      links: (
        await env.DB.prepare(
          "SELECT data FROM product_links ORDER BY updated_at DESC",
        ).all()
      ).results.map((x) => JSON.parse(x.data)),
      sessions: (
        await env.DB.prepare(
          "SELECT data FROM payment_sessions ORDER BY updated_at DESC LIMIT 100",
        ).all()
      ).results.map((x) => {
        const d = JSON.parse(x.data);
        delete d.token;
        return d;
      }),
    });
  const match = path.match(
    /^\/api\/admin\/channel\/products\/([^/]+)\/(link|sync|unlink|source)$/,
  );
  if (!match) return null;
  const [, id, action] = match;
  const p = await read(env.DB, "products", id);
  if (!p) fail("מוצר לא נמצא.", 404);
  if (action === "source" && request.method === "GET")
    return j({
      url:
        new URL(request.url).origin +
        "/api/channel/source/" +
        encodeURIComponent(id),
      link: await linkedGuard(env, id),
    });
  if (request.method !== "POST") fail("Method not allowed", 405);
  const d = await body(request);
  if (!isHome(env)) {
    if (action === "sync") {
      const link = await linkedGuard(env, id);
      if (!link) fail("אין חיבור למוצר.");
      await peer(env, "/api/channel/refresh", { id });
      await put(env.DB, "product_links", id, {
        ...link,
        status: "connected",
        lastSyncAt: now(),
        error: "",
      });
      return j({ ok: true });
    }
    fail("חיבור המקור נעשה בכרטיס SHIMI.");
  }
  if (action === "link") {
    let u;
    try {
      u = new URL(d.sourceUrl);
    } catch {
      fail("קישור מקור אינו תקין.", 400);
    }
    if (
      !peerOrigins(env).includes(u.origin) ||
      !/^\/api\/channel\/source\/[a-zA-Z0-9-]+$/.test(u.pathname) ||
      u.search ||
      u.hash
    )
      fail("יש להשתמש בקישור מקור מהחנות SHEM המוגדרת.", 400);
    const sourceId = u.pathname.split("/").pop(),
      old = await linkedGuard(env, id);
    if (old) fail("יש לנתק את החיבור הקיים לפני החלפת המקור.");
    const duplicate = await env.DB.prepare(
      "SELECT id FROM product_links WHERE json_extract(data,'$.sourceId')=?",
    )
      .bind(sourceId)
      .first();
    if (duplicate && duplicate.id !== id)
      fail("מוצר המקור כבר מקושר לכרטיס אחר.");
    const s = await peer(env, "/api/channel/source", { id: sourceId }),
      map = {};
    if (!s.sku) fail("יש להגדיר מק״ט במוצר SHEM לפני החיבור.");
    for (const v of p.variants || []) {
      const sv = s.availability.variants.find(
        (x) => x.id === d.variantMap?.[v.id],
      );
      if (!sv || sv.size !== v.size)
        fail("נדרש מיפוי ידני לכל גרסה באותה מידה.");
      map[v.id] = sv.id;
    }
    if (
      new Set(Object.values(map)).size !== Object.keys(map).length ||
      s.availability.variants.some((v) => !Object.values(map).includes(v.id))
    )
      fail("נדרש מיפוי חד ערכי לכל גרסאות המקור.");
    if (!p.variants?.length && s.availability.variants.length)
      fail("למקור יש גרסאות. יש ליצור גרסאות בכרטיס SHIMI.");
    if (!p.sizes.every((x) => s.sizes.includes(x)))
      fail("המידות אינן תואמות למקור.");
    const link = {
      id,
      sourceId,
      sourceUrl: u.href,
      variantMap: map,
      status: "pending",
      createdAt: now(),
    };
    await peer(env, "/api/channel/register", {
      sourceId,
      homeId: id,
      variantMap: map,
    });
    await put(env.DB, "product_links", id, link);
    try {
      const result = await syncLink(env, link);
      return j(result);
    } catch (e) {
      throw e;
    }
  }
  const link = await linkedGuard(env, id);
  if (!link) fail("אין חיבור למוצר.");
  if (action === "sync") return j(await syncLink(env, link));
  if (action === "unlink") {
    await peer(env, "/api/channel/unregister", {
      sourceId: link.sourceId,
      homeId: id,
    });
    await env.DB.prepare("DELETE FROM product_links WHERE id=?").bind(id).run();
    p.active = false;
    delete p.revision;
    await put(env.DB, "products", id, p);
    return j({ ok: true, product: p });
  }
  return null;
}
async function providerSession(env, session, base) {
  const gateway = await setting(env.DB, "gateway");
  if (!gateway.collectorName)
    fail("יש להגדיר את שם העסק שגובה את התשלום.", 503);
  if (
    env.PAYMENT_ADAPTER_ENABLED !== "true" ||
    !env.PAYMENT_ADAPTER_URL ||
    !env.PAYMENT_ADAPTER_SECRET
  )
    fail("ספק סליקה טרם חובר ונבדק. לא בוצע חיוב.", 503);
  const url = new URL(env.PAYMENT_ADAPTER_URL);
  if (url.protocol !== "https:") fail("כתובת מתאם סליקה אינה תקינה.");
  const raw = JSON.stringify({
      idempotencyKey: session.id,
      orderId: session.id,
      amountCents: session.amountCents,
      currency: "ILS",
      customer: session.customer,
      callbackUrl: base + "/api/channel/provider-callback",
    }),
    ts = String(Math.floor(Date.now() / 1000));
  const r = await fetch(url, {
    method: "POST",
    redirect: "manual",
    signal: AbortSignal.timeout(15000),
    headers: {
      "content-type": "application/json",
      "x-shem-timestamp": ts,
      "x-shem-signature": await sign(
        env.PAYMENT_ADAPTER_SECRET,
        ts + "." + raw,
      ),
    },
    body: raw,
  });
  if (!r.ok) fail("ספק הסליקה לא אישר פתיחת תשלום.", 502);
  const data = await r.json(),
    checkout = new URL(data.checkoutUrl);
  if (
    checkout.protocol !== "https:" ||
    checkout.origin !== new URL(env.PAYMENT_CHECKOUT_ORIGIN).origin
  )
    fail("כתובת התשלום אינה מאושרת.", 502);
  return { ...session, checkoutUrl: checkout.href, status: "pending" };
}
async function acceptShem(request, env) {
  const d = await verify(request, env.HOME_PAYMENT_SECRET, true);
  if (
    d.protocol !== "shem-home-v1" ||
    d.currency !== "ILS" ||
    !Array.isArray(d.items) ||
    !d.items.length
  )
    fail("בקשת תשלום אינה תקינה.");
  const original = await peer(env, "/api/channel/order", { id: d.orderId });
  for (const k of ["amountCents", "shippingCents", "discountCents"])
    if (d[k] !== original[k]) fail("הסכום אינו תואם להזמנת SHEM.");
  if (
    JSON.stringify(d.items) !== JSON.stringify(original.items) ||
    JSON.stringify(d.customer) !== JSON.stringify(original.customer)
  )
    fail("פרטי ההזמנה אינם תואמים.");
  if (!peerOrigins(env).some(base => d.callbackUrl === base + "/api/home/payment-callback"))
    fail("כתובת אישור אינה מאושרת.");
  for (const i of d.items) {
    const rows = await env.DB.prepare(
      "SELECT data FROM product_links WHERE json_extract(data,'$.sourceId')=?",
    )
      .bind(i.productId)
      .all();
    const link = rows.results[0] ? JSON.parse(rows.results[0].data) : null;
    if (!link) fail("מוצר SHEM אינו מקושר ל-SHIMI.");
    const { product: p } = await syncLink(env, link);
    const targetVariant = i.variantId
      ? p.variants.find((v) => link.variantMap[v.id] === i.variantId)
      : null;
    if (i.variantId && !targetVariant) fail("גרסה אינה מקושרת.");
    if (
      (targetVariant?.sku || p.sku) !== i.sku ||
      Math.round(p.price * 100) !== i.priceCents
    )
      fail("מחיר או מק״ט אינם תואמים.");
  }
  const id = "shem:" + d.orderId,
    old = await read(env.DB, "payment_sessions", id),
    hash = await sign(env.CHANNEL_SECRET, JSON.stringify(d));
  if (old) {
    if (old.hash !== hash) fail("מזהה תשלום משמש להזמנה אחרת.");
    if (old.checkoutUrl)
      return j({
        embedUrl:
          new URL(request.url).origin +
          "/payment/" +
          encodeURIComponent(old.id) +
          "?token=" +
          old.token,
      });
  }
  let s = old || {
    id,
    source: "SHEM",
    orderId: d.orderId,
    amountCents: d.amountCents,
    currency: "ILS",
    customer: d.customer,
    items: d.items,
    shippingCents: d.shippingCents,
    discountCents: d.discountCents,
    callbackUrl: d.callbackUrl,
    hash,
    token: crypto.randomUUID() + crypto.randomUUID(),
    status: "creating",
    createdAt: now(),
  };
  await put(env.DB, "payment_sessions", id, s);
  try {
    s = await providerSession(env, s, new URL(request.url).origin);
    await put(env.DB, "payment_sessions", id, s);
  } catch (e) {
    await put(env.DB, "payment_sessions", id, {
      ...s,
      status: "creation-failed",
      error: e.message,
    });
    throw e;
  }
  return j(
    {
      embedUrl:
        new URL(request.url).origin +
        "/payment/" +
        encodeURIComponent(id) +
        "?token=" +
        s.token,
    },
    201,
  );
}
async function finalize(env, s, event) {
  if (s.paymentReference && s.paymentReference !== event.transactionId)
    fail("אסמכתת תשלום אינה תואמת.");
  const details = cleanPayment(event.paymentDetails || {});
  s = {
    ...s,
    paymentReference: event.transactionId,
    paymentDetails: details,
    status: "paid-awaiting-sync",
    paidAt: s.paidAt || now(),
  };
  await put(env.DB, "payment_sessions", s.id, s);
  try {
    if (s.source === "SHEM") {
      const raw = JSON.stringify({
          orderId: s.orderId,
          status: "paid",
          currency: "ILS",
          amountCents: s.amountCents,
          transactionId: event.transactionId,
          paymentDetails: details,
        }),
        ts = String(Math.floor(Date.now() / 1000));
      const r = await fetch(s.callbackUrl, {
        method: "POST",
        redirect: "manual",
        headers: {
          "content-type": "application/json",
          "x-shem-timestamp": ts,
          "x-shem-signature": await sign(
            env.HOME_PAYMENT_SECRET,
            ts + "." + raw,
          ),
        },
        body: raw,
      });
      if (!r.ok) fail("התשלום אושר אך העדכון ל-SHEM דורש ניסיון חוזר.");
    } else {
      if (s.centralItems?.length)
        await peer(env, "/api/channel/commit", { reservationId: s.id });
      const o = await read(env.DB, "orders", s.orderId);
      o.centralInventoryConfirmed = true;
      delete o.revision;
      await put(env.DB, "orders", o.id, o);
      await confirmPayment(env, o.id, event.transactionId, "shimi", details);
      await processMail(env);
    }
    s.status = "paid";
    s.error = "";
    await put(env.DB, "payment_sessions", s.id, s);
    return j({ ok: true });
  } catch (e) {
    await put(env.DB, "payment_sessions", s.id, { ...s, error: e.message });
    throw e;
  }
}
async function ownCheckout(request, env) {
  if (request.headers.get("origin") !== new URL(request.url).origin)
    fail("Origin rejected", 403);
  const d = await body(request);
  if (d.termsAccepted !== true) fail("יש לאשר את תנאי הרכישה.", 400);
  await hydrateProducts(
    env,
    (await env.DB.prepare("SELECT data FROM products").all()).results.map((x) =>
      JSON.parse(x.data),
    ),
  );
  const quoteResponse = await publicOperations(
    new Request(new URL("/api/quote", request.url), {
      method: "POST",
      body: JSON.stringify(d),
    }),
    env,
  );
  const quote = await quoteResponse.json();
  if (!quoteResponse.ok) fail(quote.error, quoteResponse.status);
  const config = await setting(env.DB, "operations");
  if (!config.checkoutEnabled || env.PAYMENT_ADAPTER_ENABLED !== "true")
    fail("הרכישה עדיין אינה פעילה. ספק סליקה טרם חובר.", 503);
  const draft = await makeOrder(env.DB, {
    ...d,
    items: d.items.map((i) => ({ ...i, productId: i.id })),
    shippingCost: quote.shipping,
    shippingMethod: d.shipping,
    discount: quote.discount,
  });
  if (!draft.customer.phone || !draft.customer.city || !draft.customer.street)
    fail("יש להשלים פרטי משלוח.", 400);
  const id = crypto.randomUUID(),
    order = {
      ...draft,
      id,
      source: "SHIMI",
      status: "new",
      paymentStatus: "unpaid",
      createdAt: now(),
      viewToken: crypto.randomUUID() + crypto.randomUUID(),
    },
    central = [],
    local = [];
  for (const i of order.items) {
    const link = await linkedGuard(env, i.productId);
    if (link) {
      i.linkedSourceId = link.sourceId;
      central.push({
        ...i,
        productId: link.sourceId,
        variantId: link.variantMap?.[i.variantId] || "",
        priceCents: i.priceCents,
      });
    } else local.push(i);
  }
  const session = {
    id: "shimi:" + id,
    orderId: id,
    source: "SHIMI",
    amountCents: order.totalCents,
    currency: "ILS",
    customer: order.customer,
    items: order.items,
    centralItems: central,
    token: order.viewToken,
    status: "creating",
    createdAt: now(),
  };
  await put(env.DB, "orders", id, order);
  try {
    await reserveStock(env, id, local);
    if (central.length)
      await peer(env, "/api/channel/reserve", {
        reservationId: session.id,
        items: central,
      });
    await put(env.DB, "payment_sessions", session.id, session);
    const s = await providerSession(env, session, new URL(request.url).origin);
    await put(env.DB, "payment_sessions", s.id, s);
    return j(
      {
        orderId: id,
        viewToken: order.viewToken,
        embedUrl:
          new URL(request.url).origin +
          "/payment/" +
          encodeURIComponent(s.id) +
          "?token=" +
          s.token,
      },
      201,
    );
  } catch (e) {
    await releaseStock(env, id);
    if (central.length)
      try {
        await peer(env, "/api/channel/release", { reservationId: session.id });
      } catch {}
    await put(env.DB, "payment_sessions", session.id, {
      ...session,
      status: "creation-failed",
      error: e.message,
    });
    throw e;
  }
}

export function reviewSummary(quote, products, shipping, code) {
  const cents = (value) => Math.round(value * 100);
  const items = quote.items.map((line) => {
    const p = products.find((p) => p.id === line.id);
    const v = (p.variants || []).find((v) => v.id === line.variantId);
    return {
      productId: p.id,
      variantId: v?.id || "",
      name: p.he,
      sku: v?.sku || p.sku,
      size: line.size,
      color: v?.color || p.color,
      qty: line.qty,
      priceCents: cents(line.price),
      lineCents: cents(line.price) * line.qty,
    };
  });
  return {
    items,
    currency: "ILS",
    subtotalCents: cents(quote.subtotal),
    discountCents: cents(quote.discount),
    shippingCents: cents(quote.shipping),
    vatCents: cents(quote.vatIncluded),
    netSubtotalCents: cents(quote.netSubtotal),
    totalCents: cents(quote.total),
    shippingMethod: shipping || "standard",
    couponCode: String(code || "")
      .trim()
      .toUpperCase(),
    vatRate: 18,
  };
}
async function summaryFromQuote(env, data, quote) {
  const ids = [...new Set(data.items.map((i) => i.id))];
  const rows = await env.DB.prepare(
    "SELECT data FROM products WHERE id IN (" +
      ids.map(() => "?").join(",") +
      ")",
  )
    .bind(...ids)
    .all();
  const products = rows.results.map((r) => JSON.parse(r.data));
  for (const id of ids) {
    const p = products.find((p) => p.id === id);
    if (!p || p.archived || p.active === false) fail("מוצר אינו זמין.", 409);
    const line = quote.items.find((i) => i.id === id);
    if (Math.round(p.price * 100) !== Math.round(line.price * 100))
      fail("מחיר השתנה במהלך הבדיקה. רעננו את הסיכום.", 409);
  }
  return reviewSummary(quote, products, data.shipping, data.code);
}
export async function validateReviewToken(env, data, quote) {
  if (!data.reviewToken) return;
  const [expires, digest] = String(data.reviewToken).split(".");
  if (
    !/^\d+$/.test(expires) ||
    Number(expires) < Date.now() ||
    Number(expires) > Date.now() + 301000
  )
    fail("סיכום ההזמנה פג. יש לאמת שוב את ההזמנה.", 409);
  const summary = await summaryFromQuote(env, data, quote);
  if (
    !env.CHANNEL_SECRET ||
    digest !==
      (await sign(env.CHANNEL_SECRET, expires + "." + JSON.stringify(summary)))
  )
    fail("פרטי הסל או המחיר השתנו. יש לאמת שוב את סיכום ההזמנה.", 409);
}
async function checkoutReview(request, env) {
  if (request.headers.get("origin") !== new URL(request.url).origin)
    fail("Origin rejected", 403);
  if (!request.headers.get("content-type")?.includes("application/json"))
    fail("JSON required", 415);
  const data = await body(request);
  if (data.termsAccepted !== true) fail("יש לאשר תנאי רכישה.", 400);
  const c = data.customer || {};
  if (
    !String(c.name || "").trim() ||
    !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email || "") ||
    !String(c.phone || "").trim() ||
    !String(c.city || "").trim() ||
    !String(c.street || "").trim()
  )
    fail("נדרשים שם, אימייל, טלפון וכתובת משלוח תקינים.", 400);
  const qr = await publicOperations(
    new Request(new URL("/api/quote", request.url), {
      method: "POST",
      body: JSON.stringify({
        items: data.items,
        shipping: data.shipping,
        code: data.code,
      }),
    }),
    env,
  );
  const quote = await qr.json();
  if (!qr.ok) fail(quote.error, qr.status);
  const summary = await summaryFromQuote(env, data, quote);
  if (isHome(env))
    return j({
      source: summary,
      home: null,
      verified: false,
      quote,
      checkedAt: now(),
    });
  if (!env.CHANNEL_SECRET || !env.SHIMI_ORIGIN)
    fail("חיבור לסיכום SHIMI טרם הוגדר.", 503);
  const home = await peer(env, "/api/channel/review", {
    protocol: "shem-review-v1",
    summary,
  });
  if (
    JSON.stringify(home.summary) !== JSON.stringify(summary) ||
    home.verified !== true
  )
    fail("סיכומי ההזמנה אינם תואמים. לא ניתן להמשיך.", 409);
  const expires = String(Date.now() + 300000);
  return j({
    source: summary,
    home: home.summary,
    verified: true,
    quote,
    checkedAt: now(),
    reviewToken:
      expires +
      "." +
      (await sign(env.CHANNEL_SECRET, expires + "." + JSON.stringify(summary))),
  });
}
async function acceptReview(env, data) {
  const s = data.summary;
  if (
    data.protocol !== "shem-review-v1" ||
    !s ||
    s.currency !== "ILS" ||
    !Array.isArray(s.items) ||
    !s.items.length ||
    s.items.length > 100
  )
    fail("סיכום הזמנה לא תקין.", 400);
  const ids = [...new Set(s.items.map((i) => i.productId))];
  const links = (
    await env.DB.prepare(
      "SELECT data FROM product_links WHERE json_extract(data,'$.sourceId') IN (" +
        ids.map(() => "?").join(",") +
        ")",
    )
      .bind(...ids)
      .all()
  ).results.map((r) => JSON.parse(r.data));
  if (ids.some((id) => !links.some((l) => l.sourceId === id)))
    fail("מוצר SHEM אינו מקושר ל-SHIMI.", 409);
  const sources = (await peer(env, "/api/channel/sources", { ids })).products;
  const synced = new Map();
  const selected = [],
    quantities = new Map();
  for (const i of s.items) {
    if (!Number.isInteger(i.qty) || i.qty < 1 || i.qty > 5)
      fail("כמות לא תקינה.", 400);
    const key = i.productId + ":" + i.variantId + ":" + i.size;
    if (quantities.has(key)) fail("פריט כפול בסיכום.", 400);
    quantities.set(key, i.qty);
    const link = links.find((l) => l.sourceId === i.productId);
    if (!synced.has(i.productId)) {
      const source = sources.find((p) => p.id === i.productId);
      if (!source) fail("מוצר מקור אינו זמין.", 409);
      synced.set(i.productId, await syncLink(env, link, source));
    }
    const { product: p, source } = synced.get(i.productId);
    if (p.archived || p.active === false || source.active === false)
      fail("מוצר מקושר אינו פעיל.", 409);
    const v = i.variantId
      ? p.variants.find((v) => link.variantMap[v.id] === i.variantId)
      : null;
    const sv = i.variantId
      ? source.availability.variants.find((v) => v.id === i.variantId)
      : null;
    if (
      (p.variants?.length && !v) ||
      !p.sizes.includes(i.size) ||
      (v && v.size !== i.size)
    )
      fail("מיפוי מידה וצבע אינו תואם.", 409);
    if (
      (v?.sku || p.sku) !== i.sku ||
      Math.round(p.price * 100) !== i.priceCents ||
      i.lineCents !== i.priceCents * i.qty
    )
      fail("מחיר או מק״ט אינם תואמים.", 409);
    const available =
      sv?.stock ??
      source.availability.sizeStock?.[i.size] ??
      source.availability.stock;
    if (
      i.qty > available ||
      s.items
        .filter((x) => x.productId === i.productId)
        .reduce((n, x) => n + x.qty, 0) > source.availability.stock
    )
      fail("אין מספיק מלאי במוצר המקושר.", 409);
    selected.push({
      id: p.id,
      variantId: v?.id || "",
      sku: i.sku,
      priceCents: i.priceCents,
      qty: i.qty,
    });
  }
  const fields = [
    "subtotalCents",
    "discountCents",
    "shippingCents",
    "vatCents",
    "netSubtotalCents",
    "totalCents",
  ];
  if (
    fields.some((k) => !Number.isSafeInteger(s[k]) || s[k] < 0) ||
    s.discountCents > s.subtotalCents ||
    s.items.reduce((n, i) => n + i.lineCents, 0) !== s.subtotalCents ||
    s.totalCents !== s.subtotalCents - s.discountCents + s.shippingCents ||
    s.vatCents !==
      Math.round(((s.subtotalCents - s.discountCents) * 18) / 118) ||
    s.netSubtotalCents + s.vatCents !== s.subtotalCents - s.discountCents ||
    !["standard", "express"].includes(s.shippingMethod) ||
    s.vatRate !== 18
  )
    fail("סכומי הסיכום אינם תואמים.", 409);
  // SHEM remains the sole coupon/shipping authority. Do not reprice through SHIMI's independent cart.
  return j({ summary: s, verified: true, selectedCount: selected.length });
}

export async function channelAPI(request, env, adminOnly = false) {
  const path = new URL(request.url).pathname;
  try {
    if (adminOnly)
      return path.startsWith("/api/admin/channel/")
        ? await admin(request, env, path)
        : null;
    if (path === "/api/checkout-review" && request.method === "POST")
      return await checkoutReview(request, env);
    if (
      path === "/api/shimi/checkout" &&
      isHome(env) &&
      request.method === "POST"
    )
      return await ownCheckout(request, env);
    if (!path.startsWith("/api/channel/") && !path.startsWith("/payment/"))
      return null;
    if (path.startsWith("/payment/") && isHome(env)) {
      const s = await read(
        env.DB,
        "payment_sessions",
        decodeURIComponent(path.slice(9)),
      );
      if (!s || s.token !== new URL(request.url).searchParams.get("token"))
        return j({ error: "Not found" }, 404);
      const collector = String(
        (await setting(env.DB, "gateway")).collectorName || "",
      )
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;");
      const html =
        '<!doctype html><html lang="he" dir="rtl"><meta name="viewport" content="width=device-width"><title>תשלום SHIMI</title><style>body{font-family:Arial;margin:24px}iframe{width:100%;height:580px;border:0}</style><h2>תשלום מאובטח</h2><p>העסק הגובה: ' +
        collector +
        "</p><p>הסכום לתשלום: " +
        (s.amountCents / 100).toFixed(2) +
        " ₪</p>" +
        (s.checkoutUrl
          ? '<iframe title="תשלום אצל ספק הסליקה" src="' +
            s.checkoutUrl.replaceAll("&", "&amp;").replaceAll('"', "&quot;") +
            '" sandbox="allow-scripts allow-forms allow-same-origin allow-popups"></iframe>'
          : "<p>ספק סליקה טרם חובר. לא בוצע חיוב.</p>") +
        "</html>";
      return new Response(html, {
        headers: {
          "content-type": "text/html;charset=utf-8",
          "cache-control": "no-store",
          "referrer-policy": "no-referrer",
          "content-security-policy":
            "default-src 'none'; style-src 'unsafe-inline'; frame-src " +
            new URL(s.checkoutUrl || "https://invalid.example").origin +
            "; frame-ancestors 'self' " +
            peerOrigins(env).join(" "),
        },
      });
    }
    if (request.method !== "POST") return j({ error: "Not found" }, 404);
    if (path === "/api/channel/checkout" && isHome(env))
      return await acceptShem(request, env);
    if (path === "/api/channel/provider-callback" && isHome(env)) {
      const event = await verify(request, env.PAYMENT_ADAPTER_SECRET, true),
        s = await read(env.DB, "payment_sessions", event.orderId);
      if (
        !s ||
        event.currency !== "ILS" ||
        event.amountCents !== s.amountCents ||
        !event.transactionId ||
        event.status !== "paid"
      )
        fail("אישור תשלום אינו תואם.");
      return await finalize(env, s, event);
    }
    if (path === '/api/channel/qa-fixtures') {
      if (!env.QA_FIXTURE_SECRET || Date.now() > Date.parse('2026-10-10T23:59:59Z')) return j({error:'Not found'},404);
      return j(await qaFixtures(env, await verify(request, env.QA_FIXTURE_SECRET), isHome(env)));
    }
    const d = await verify(request, env.CHANNEL_SECRET);
    if (isHome(env) && path === "/api/channel/review")
      return await acceptReview(env, d);
    if (isHome(env) && path === "/api/channel/refresh") {
      const rows = await env.DB.prepare(
        "SELECT data FROM product_links WHERE json_extract(data,'$.sourceId')=?",
      )
        .bind(d.id)
        .all();
      for (const r of rows.results) await syncLink(env, JSON.parse(r.data));
      return j({ ok: true });
    }
    if (isHome(env)) fail("Not found", 404);
    if (path === "/api/channel/sources") {
      if (
        !Array.isArray(d.ids) ||
        !d.ids.length ||
        d.ids.length > 100 ||
        d.ids.some((id) => typeof id !== "string" || id.length > 100)
      )
        fail("בקשת מוצרים אינה תקינה.", 400);
      const placeholders = d.ids.map(() => "?").join(",");
      const products = await env.DB.prepare(
        `SELECT id,data,updated_at FROM products WHERE id IN (${placeholders})`,
      )
        .bind(...d.ids)
        .all();
      const held = await env.DB.prepare(
        "SELECT product_id,variant_id,size,SUM(qty) qty FROM channel_reservations WHERE product_id IN (SELECT value FROM json_each(?)) AND state='held' AND expires_at>? GROUP BY product_id,variant_id,size",
      )
        .bind(JSON.stringify(d.ids), now())
        .all();
      return j({
        products: products.results
          .map((r) => ({
            ...JSON.parse(r.data),
            id: r.id,
            revision: r.updated_at,
          }))
          .filter((p) => !p.archived)
          .map((p) =>
            projection(
              p,
              applyHolds(
                p,
                held.results.filter((r) => r.product_id === p.id),
              ),
            ),
          ),
      });
    }
    if (path === "/api/channel/source") return j(await source(env, d.id));
    if (path === "/api/channel/register") {
      const p = await read(env.DB, "products", d.sourceId);
      if (!p || p.archived) fail("מוצר מקור אינו זמין.");
      const old = await linkedGuard(env, d.sourceId);
      if (old && old.homeId !== d.homeId)
        fail("מוצר המקור כבר מקושר למוצר אחר.");
      p.homeSku = p.sku;
      p.homeProductId = d.homeId;
      p.variants = (p.variants || []).map((v) => ({ ...v, homeSku: v.sku }));
      delete p.revision;
      await put(env.DB, "products", p.id, p);
      await put(env.DB, "product_links", d.sourceId, {
        id: d.sourceId,
        homeId: d.homeId,
        variantMap: d.variantMap || {},
        status: "connected",
        lastSyncAt: now(),
      });
      return j({ ok: true });
    }
    if (path === "/api/channel/unregister") {
      const old = await linkedGuard(env, d.sourceId);
      if (old && old.homeId === d.homeId)
        await env.DB.prepare("DELETE FROM product_links WHERE id=?")
          .bind(d.sourceId)
          .run();
      return j({ ok: true });
    }
    if (path === "/api/channel/order") {
      const o = await read(env.DB, "orders", d.id);
      if (!o || o.archived) fail("הזמנה חסרה.", 404);
      return j({
        items: o.items,
        customer: o.customer,
        amountCents: o.totalCents,
        shippingCents: o.shippingCents,
        discountCents: o.discountCents,
      });
    }
    if (path === "/api/channel/reserve") {
      if (!/^shimi:[a-zA-Z0-9-]+$/.test(d.reservationId))
        fail("מזהה אינו תקין.");
      await reserveStock(env, d.reservationId, d.items);
      return j({ ok: true });
    }
    if (path === "/api/channel/release") {
      await releaseStock(env, d.reservationId);
      return j({ ok: true });
    }
    if (path === "/api/channel/commit")
      return j(await commitStock(env, d.reservationId));
    return j({ error: "Not found" }, 404);
  } catch (e) {
    if (!e.status) console.error("Channel operation failed", e.message);
    return j(
      {
        error: e.status
          ? e.message
          : "לא ניתן להשלים את הפעולה. יש לבדוק את החיבור.",
      },
      e.status || 503,
    );
  }
}
export async function channelAdminExtras(request, env) {
  const path = new URL(request.url).pathname;
  if (path === "/api/admin/channel/preview" && request.method === "POST") {
    try {
      const d = await body(request),
        u = new URL(d.sourceUrl);
      if (
        !peerOrigins(env).includes(u.origin) ||
        !/^\/api\/channel\/source\/[a-zA-Z0-9-]+$/.test(u.pathname)
      )
        fail("קישור המקור אינו תקין.");
      return j(
        await peer(env, "/api/channel/source", {
          id: u.pathname.split("/").pop(),
        }),
      );
    } catch (e) {
      return j({ error: e.message }, e.status || 400);
    }
  }
  if (path === "/api/admin/channel/reconcile" && request.method === "POST") {
    const d = await body(request),
      s = await read(env.DB, "payment_sessions", d.id);
    if (!s || s.status !== "paid-awaiting-sync" || !s.paymentReference)
      return j({ error: "אין תשלום מאושר הממתין לסנכרון." }, 409);
    try {
      return await finalize(env, s, {
        transactionId: s.paymentReference,
        paymentDetails: s.paymentDetails,
      });
    } catch (e) {
      return j({ error: e.message }, e.status || 409);
    }
  }
  return null;
}
export async function notifyChange(request, env, result) {
  if (
    isHome(env) ||
    !result.ok ||
    !["PUT", "POST"].includes(request.method) ||
    !new URL(request.url).pathname.startsWith("/api/admin/products")
  )
    return;
  const p = await result.clone().json();
  if (!p.id || !(await linkedGuard(env, p.id))) return;
  try {
    await peer(env, "/api/channel/refresh", { id: p.id });
    const link = await linkedGuard(env, p.id);
    await put(env.DB, "product_links", p.id, {
      ...link,
      status: "connected",
      lastSyncAt: now(),
      error: "",
    });
  } catch (e) {
    const link = await linkedGuard(env, p.id);
    await put(env.DB, "product_links", p.id, {
      ...link,
      status: "sync-pending",
      error: e.message,
    });
  }
}
