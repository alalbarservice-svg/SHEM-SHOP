import {
  channelAPI,
  channelAdminExtras,
  hydrateProducts,
  linkedGuard,
  notifyChange,
  stockGuards,
  isHome,
} from "./channel.js";
import {
  management,
  inventory,
  adjustLoyalty,
  paymentCallback,
  setting,
  publicCommerce,
} from "./management.js";
import { publicOperations, adminOperations } from "./operations.js";
const json = (data, status = 200) =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "cache-control": "no-store",
      "x-content-type-options": "nosniff",
    },
  });
const getSetting = async (db, key) => {
  const row = await db
    .prepare("SELECT value FROM settings WHERE key = ?")
    .bind(key)
    .first();
  return row ? JSON.parse(row.value) : null;
};
const setSetting = (db, key, value) =>
  db
    .prepare(
      "INSERT INTO settings(key,value,updated_at) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_at=excluded.updated_at",
    )
    .bind(key, JSON.stringify(value), new Date().toISOString())
    .run();
const fail = (message, status = 400) => {
  const e = new Error(message);
  e.status = status;
  throw e;
};
const str = (v, max = 200) =>
  typeof v === "string" ? v.trim().slice(0, max) : "";
const email = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
async function handleApi(request, env) {
  const url = new URL(request.url),
    path = url.pathname;
  if (!env.DB) return json({ error: "החיבור למסד הנתונים אינו זמין." }, 503);
  try {
    const method = request.method;
    if (path === "/api/session" && method === "GET") {
      const uid = request.headers.get("oai-authenticated-user-id");
      return json({
        signedIn: !!uid,
        userId: uid || null,
        email: request.headers.get("oai-authenticated-user-email") || null,
      });
    }

    if (!["GET", "POST", "PUT", "PATCH", "DELETE"].includes(method))
      return json({ error: "Method not allowed" }, 405);
    if (method !== "GET") {
      if (request.headers.get("origin") !== url.origin)
        return json({ error: "Origin rejected" }, 403);
      if (
        path !== "/api/admin/media" &&
        !request.headers.get("content-type")?.includes("application/json")
      )
        return json({ error: "JSON required" }, 415);
      if (
        Number(request.headers.get("content-length") || 0) > 1000000 &&
        path !== "/api/admin/media"
      )
        return json({ error: "Request too large" }, 413);
    }
    const body = async () => {
      const raw = await request.text();
      if (raw.length > 1000000) fail("Request too large", 413);
      try {
        return JSON.parse(raw);
      } catch {
        fail("Invalid JSON");
      }
    };
    const commerceResult = await publicCommerce(request, env);
    if (commerceResult) return commerceResult;
    const publicResult = await publicOperations(request, env);
    if (publicResult) return publicResult;
    if (path === "/api/store" && method === "GET") {
      const value = (await getSetting(env.DB, "store")) || {};
      return json({
        ...(await setting(env.DB, "content")),
        operations: await setting(env.DB, "operations"),
        heroTitle:
          (await setting(env.DB, "content")).heroTitle || value.heroTitle,
        heroSubtitle:
          (await setting(env.DB, "content")).heroSubtitle || value.heroSubtitle,
        brands: value.brands,
        business: (await getSetting(env.DB, "business")) || {},
      });
    }
    if (path === "/api/catalogue" && method === "GET") {
      const rows = await env.DB.prepare(
        "SELECT data FROM products ORDER BY updated_at DESC",
      ).all();
      return json({
        products: (
          await hydrateProducts(
            env,
            rows.results
              .map((r) => JSON.parse(r.data))
              .filter((p) => p.active !== false && !p.archived),
          )
        ).map(({ homeSku, homeProductId, ...p }) => ({
          ...p,
          variants: (p.variants || []).map(({ homeSku, ...v }) => v),
        })),
        configured: !!(await getSetting(env.DB, "catalogueInitialized")),
      });
    }
    if (path === "/api/club" && method === "GET") {
      return json(
        (await getSetting(env.DB, "club")) || {
          title: "הסטייל שלך. המעגל שלך.",
          description: "המעגל של SHIMI",
          benefits: "גישה מוקדמת לקולקציות\nעדכונים על פריטים נבחרים",
          enabled: false,
          privacyUrl: "",
        },
      );
    }
    if (path === "/api/club" && method === "POST") {
      const config = await getSetting(env.DB, "club");
      if (!config?.enabled || !config.privacyUrl)
        return json({ error: "ההרשמה למועדון עדיין אינה פעילה." }, 409);
      const data = await body(),
        address = str(data.email, 254).toLowerCase();
      if (!email(address) || data.consent !== true)
        fail("כתובת אימייל תקינה והסכמה לקבלת עדכונים נדרשות.");
      const now = new Date().toISOString();
      await env.DB.prepare(
        "INSERT INTO members(id,email,status,consent_at,created_at) VALUES(?,?,?,?,?) ON CONFLICT(email) DO UPDATE SET status=excluded.status,consent_at=excluded.consent_at",
      )
        .bind(crypto.randomUUID(), address, "active", now, now)
        .run();
      const member = await env.DB.prepare(
        "SELECT id FROM members WHERE email=?",
      )
        .bind(address)
        .first();
      const profile = await env.DB.prepare(
        "SELECT id FROM member_profiles WHERE id=? OR json_extract(data,'$.email')=?",
      )
        .bind(member.id, address)
        .first();
      if (!profile)
        await env.DB.prepare(
          "INSERT INTO member_profiles(id,data,updated_at) VALUES(?,?,?)",
        )
          .bind(
            member.id,
            JSON.stringify({
              id: member.id,
              email: address,
              name: "",
              tier: "standard",
              status: "active",
              cardNumber:
                "SHIMI-" +
                member.id.replaceAll("-", "").slice(0, 12).toUpperCase(),
              createdAt: now,
              marketingConsent: false,
            }),
            now,
          )
          .run();
      return json({ ok: true });
    }
    if (!path.startsWith("/api/admin"))
      return json({ error: "Not found" }, 404);
    // The initial binding is permitted only while Sites access remains owner-only.
    // After binding, every request must match the stable dispatch-authenticated ID.
    const uid = request.headers.get("oai-authenticated-user-id");
    if (!uid)
      return json({ error: "יש להתחבר לחשבון המורשה כדי לנהל את החנות." }, 401);
    let principal = await getSetting(env.DB, "adminPrincipal");
    const configuredAdmins = (env.ADMIN_USER_IDS || "")
      .split(",")
      .map((x) => x.trim())
      .filter(Boolean);
    // Only platform-authenticated identity headers may grant access. Contact
    // details, request bodies and client storage never confer permissions.
    const authenticatedEmail = (
      request.headers.get("oai-authenticated-user-email") || ""
    )
      .trim()
      .toLowerCase();
    const configuredEmails = (env.ADMIN_EMAILS || "")
      .split(",")
      .map((value) => value.trim().toLowerCase())
      .filter(Boolean);
    if (configuredAdmins.length || configuredEmails.length) principal = null;
    if (
      configuredAdmins.includes(uid) ||
      (authenticatedEmail && configuredEmails.includes(authenticatedEmail))
    )
      principal = uid;
    if (!principal) {
      if (env.OWNER_PRIVATE_BOOTSTRAP !== "true")
        return json({ error: "מנהל מורשה טרם הוגדר." }, 403);
      await env.DB.prepare(
        "INSERT OR IGNORE INTO settings(key,value,updated_at) VALUES(?,?,?)",
      )
        .bind("adminPrincipal", JSON.stringify(uid), new Date().toISOString())
        .run();
      principal = await getSetting(env.DB, "adminPrincipal");
    }
    if (principal !== uid)
      return json({ error: "אין הרשאת ניהול לחשבון זה." }, 403);
    if (path === "/api/admin/session" && method === "GET")
      return json({
        authorized: true,
        contactEmail: env.ADMIN_CONTACT_EMAIL || "your.shem88@gmail.com",
        signedInEmail:
          request.headers.get("oai-authenticated-user-email") || "",
        ownershipTransferred: false,
        localPreview: env.LOCAL_PREVIEW === "true",
      });
    const channelResult =
      (await channelAdminExtras(request, env)) ||
      (await channelAPI(request, env, true));
    if (channelResult) return channelResult;
    const managedResult =
      (await inventory(request, env)) ||
      (await adjustLoyalty(request, env)) ||
      (await management(request, env));
    if (managedResult) return managedResult;
    const operationsResult = await adminOperations(request, env);
    if (operationsResult) return operationsResult;
    if (path === "/api/admin/settings" && method === "GET") {
      const rows = await env.DB.prepare(
        "SELECT key,value FROM settings WHERE key IN (?, ?, ?, ?, ?)",
      )
        .bind(
          "club",
          "store",
          "catalogueInitialized",
          "business",
          "integrations",
        )
        .all();
      return json(
        Object.fromEntries(
          rows.results.map((r) => [r.key, JSON.parse(r.value)]),
        ),
      );
    }
    if (path === "/api/admin/settings" && method === "PUT") {
      const data = await body();
      if (!["club", "store", "business", "integrations"].includes(data.key))
        fail("Invalid setting");
      if (data.key === "integrations") {
        const v = data.value || {};
        data.value = Object.fromEntries(
          [
            "paymentProvider",
            "merchantId",
            "invoiceProvider",
            "invoiceAccount",
          ].map((k) => [k, str(v[k], 150)]),
        );
      }
      if (data.key === "business") {
        const v = data.value || {};
        data.value = Object.fromEntries(
          [
            "legalName",
            "registrationNumber",
            "address",
            "phone",
            "accessibilityContact",
            "serviceAreas",
            "returnCollectionFee",
          ].map((k) => [k, str(v[k], 500)]),
        );
      }
      if (data.key === "club") {
        const v = data.value || {};
        if (
          v.enabled &&
          !/^(https:\/\/[^\s]+|#\/privacy)$/.test(v.privacyUrl || "")
        )
          fail("כדי להפעיל הרשמה יש להזין קישור למדיניות פרטיות.");
        data.value = {
          title: str(v.title, 150),
          description: str(v.description, 1000),
          benefits: str(v.benefits, 2000),
          enabled: v.enabled === true,
          privacyUrl: str(v.privacyUrl, 500),
        };
      }
      if (data.key === "store") {
        const v = data.value || {};
        data.value = {
          heroTitle: str(v.heroTitle, 100),
          heroSubtitle: str(v.heroSubtitle, 200),
          contactEmail: env.ADMIN_CONTACT_EMAIL || "your.shem88@gmail.com",
          warehouseSystem: str(v.warehouseSystem, 200),
          brands: Array.isArray(v.brands)
            ? v.brands
                .map((b) => str(b, 80))
                .filter(Boolean)
                .slice(0, 100)
            : [],
        };
      }
      await setSetting(env.DB, data.key, data.value);
      return json({ ok: true });
    }
    if (path === "/api/admin/media" && method === "POST") {
      if (!env.BUCKET) return json({ error: "אחסון התמונות אינו זמין." }, 503);
      const type = request.headers.get("content-type"),
        ext = { "image/png": "png", "image/jpeg": "jpg", "image/webp": "webp" }[
          type
        ];
      if (!ext) fail("יש להעלות PNG, JPG או WebP בלבד.");
      const bytes = await request.arrayBuffer();
      if (bytes.byteLength > 8 * 1024 * 1024)
        fail("התמונה גדולה מדי. עד 8MB.", 413);
      const view = new Uint8Array(bytes);
      const valid =
        ext === "png"
          ? view[0] === 137 &&
            view[1] === 80 &&
            view[2] === 78 &&
            view[3] === 71
          : ext === "jpg"
            ? view[0] === 255 && view[1] === 216 && view[2] === 255
            : String.fromCharCode(...view.slice(0, 4)) === "RIFF" &&
              String.fromCharCode(...view.slice(8, 12)) === "WEBP";
      if (!valid) fail("תוכן הקובץ אינו תואם לסוג התמונה.");
      const key = `products/${crypto.randomUUID()}.${ext}`;
      await env.BUCKET.put(key, bytes, { httpMetadata: { contentType: type } });
      return json({ imageUrl: `/media/${key}` }, 201);
    }
    const match = path.match(
      /^\/api\/admin\/(products|orders|promotions|members)(?:\/([^/]+))?$/,
    );
    if (!match) return json({ error: "Not found" }, 404);
    const table = match[1],
      id = match[2];
    if (method === "GET") {
      if (id && table !== "members") {
        const row = await env.DB.prepare(
          `SELECT data,updated_at FROM ${table} WHERE id=?`,
        )
          .bind(id)
          .first();
        if (!row) fail("הרשומה לא נמצאה.", 404);
        return json({ ...JSON.parse(row.data), revision: row.updated_at });
      }
      if (table === "members") {
        const rows = await env.DB.prepare(
          "SELECT id,email,status,consent_at,created_at FROM members ORDER BY created_at DESC LIMIT 1000",
        ).all();
        return json(rows.results);
      }
      const rows = await env.DB.prepare(
        `SELECT data,updated_at FROM ${table} ORDER BY updated_at DESC LIMIT 1000`,
      ).all();
      return json(
        rows.results.map((r) => ({
          ...JSON.parse(r.data),
          revision: r.updated_at,
        })),
      );
    }
    if (table === "members") {
      if (method !== "PATCH" || !id) fail("Invalid operation");
      const data = await body();
      if (!["active", "unsubscribed"].includes(data.status))
        fail("Invalid status");
      const exists = await env.DB.prepare("SELECT id FROM members WHERE id=?")
        .bind(id)
        .first();
      if (!exists) fail("החבר לא נמצא.", 404);
      if (data.status === "active")
        fail("הפעלת דיוור מחדש דורשת הסכמה חדשה מהלקוח.", 409);
      await env.DB.prepare("UPDATE members SET status=? WHERE id=?")
        .bind(data.status, id)
        .run();
      return json({ ok: true });
    }
    if (!["POST", "PUT"].includes(method))
      return json({ error: "Method not allowed" }, 405);
    const data = await body(),
      recordId = id || crypto.randomUUID();
    let expectedRevision;
    if (method === "PUT") {
      if (!id) fail("חסר מזהה.");
      const existing = await env.DB.prepare(
        `SELECT updated_at FROM ${table} WHERE id=?`,
      )
        .bind(id)
        .first();
      if (!existing) fail("הרשומה לא נמצאה.", 404);
      expectedRevision = existing.updated_at;
      if (data.revision && data.revision !== existing.updated_at)
        fail("הרשומה השתנתה. רעננו לפני שמירה.", 409);
    }
    let record, previousProduct;
    if (table === "products") {
      const price = Number(data.price),
        previous = Number(data.previousPrice || 0),
        stock = Number(data.stock);
      if (
        !str(data.he) ||
        !Number.isFinite(price) ||
        price < 0.01 ||
        price > 100000 ||
        !Number.isInteger(stock) ||
        stock < 0 ||
        stock > 100000
      )
        fail("יש להזין שם, מחיר חיובי ומלאי תקין.");
      if (!Number.isFinite(previous) || previous < 0)
        fail("מחיר קודם אינו תקין.");
      const sizes = Array.isArray(data.sizes)
        ? data.sizes
            .map((s) => str(s, 20))
            .filter(Boolean)
            .slice(0, 30)
        : [];
      if (new Set(sizes).size !== sizes.length) fail("יש להסיר מידות כפולות.");
      if (!sizes.length) fail("יש להזין לפחות מידה אחת.");
      const imageUrl = str(data.imageUrl, 500);
      const image = str(data.image, 100);
      if (image && !/^[a-zA-Z0-9_-]+$/.test(image))
        fail("Invalid image reference");
      if (data.active !== false && !imageUrl && !image)
        fail("יש להעלות תמונת מוצר לפני הצגתו בחנות.");
      if (
        imageUrl &&
        (!/^\/(assets|media)\/[a-zA-Z0-9_./-]+$/.test(imageUrl) ||
          imageUrl.includes(".."))
      )
        fail("Invalid image URL");
      const sizeStock =
        data.sizeStock && typeof data.sizeStock === "object"
          ? Object.fromEntries(
              sizes.map((size) => [size, Number(data.sizeStock[size] ?? 0)]),
            )
          : undefined;
      if (
        sizeStock &&
        Object.values(sizeStock).some(
          (n) => !Number.isInteger(n) || n < 0 || n > 100000,
        )
      )
        fail("מלאי לפי מידה אינו תקין.");
      if (
        sizeStock &&
        Object.values(sizeStock).reduce((a, b) => a + b, 0) !== stock
      )
        fail("מלאי כולל חייב להתאים לסכום המידות.");
      const sku = str(data.sku, 100);
      if (
        sku &&
        (await env.DB.prepare(
          "SELECT id FROM products WHERE json_extract(data, '$.sku')=? AND id<>?",
        )
          .bind(sku, recordId)
          .first())
      )
        fail('מק"ט זה כבר קיים.', 409);
      const variants = Array.isArray(data.variants)
        ? data.variants.slice(0, 200).map((v) => ({
            id: str(v.id, 100) || crypto.randomUUID(),
            size: str(v.size, 20),
            color: str(v.color, 50),
            sku: str(v.sku, 100),
            homeSku: str(v.homeSku, 100),
            stock: Number(v.stock),
          }))
        : [];
      if (
        variants.some(
          (v) =>
            !sizes.includes(v.size) ||
            !v.color ||
            !v.sku ||
            !Number.isInteger(v.stock) ||
            v.stock < 0,
        ) ||
        new Set(variants.map((v) => v.id)).size !== variants.length ||
        new Set(variants.map((v) => v.sku)).size !== variants.length ||
        new Set(variants.map((v) => v.size + ":" + v.color)).size !==
          variants.length
      )
        fail("גרסאות מוצר אינן תקינות או כפולות.");
      if (
        variants.length &&
        (variants.reduce((n, v) => n + v.stock, 0) !== stock ||
          sizes.some(
            (size) =>
              sizeStock &&
              sizeStock[size] !==
                variants
                  .filter((v) => v.size === size)
                  .reduce((n, v) => n + v.stock, 0),
          ))
      )
        fail("מלאי הגרסאות חייב להתאים למלאי הכולל והמידות.");
      for (const variant of variants) {
        const duplicate = await env.DB.prepare(
          "SELECT p.id FROM products p, json_each(p.data,'$.variants') v WHERE json_extract(v.value,'$.sku')=? AND p.id<>? LIMIT 1",
        )
          .bind(variant.sku, recordId)
          .first();
        if (duplicate) fail("מק״ט גרסה כבר משויך למוצר אחר.", 409);
      }
      const oldProduct =
        method === "PUT"
          ? await env.DB.prepare("SELECT data FROM products WHERE id=?")
              .bind(recordId)
              .first()
          : null;
      previousProduct = oldProduct ? JSON.parse(oldProduct.data) : null;
      if (oldProduct && JSON.parse(oldProduct.data).archived)
        fail("יש לשחזר מוצר מהארכיון לפני עריכה.", 409);
      const linked =
        isHome(env) && method === "PUT"
          ? await linkedGuard(env, recordId)
          : null;
      if (
        linked &&
        previousProduct &&
        (data.sku !== previousProduct.sku ||
          Number(data.price) !== previousProduct.price ||
          Number(data.previousPrice || 0) !==
            Number(previousProduct.previousPrice || 0) ||
          stock !== previousProduct.stock ||
          JSON.stringify(data.variants || []) !==
            JSON.stringify(previousProduct.variants || []))
      )
        fail(
          "מק״ט, מחיר וגרסאות של מוצר מקושר מנוהלים דרך SHEM. נתקו חיבור לפני שינוי.",
          409,
        );
      record = {
        id: recordId,
        sku,
        homeSku: str(data.homeSku, 100),
        homeProductId: str(data.homeProductId, 100),
        variants,
        sizeStock,
        description: str(data.description, 3000),
        material: str(data.material, 500),
        care: str(data.care, 500),
        demo: data.demo === true,
        he: str(data.he),
        en: str(data.en) || str(data.he),
        brand: str(data.brand),
        cat: ["clothing", "shoes", "accessories"].includes(data.cat)
          ? data.cat
          : "clothing",
        price: Math.round(price * 100) / 100,
        previousPrice: previous > price ? previous : undefined,
        stock,
        sizes,
        color: str(data.color, 50),
        image,
        imageUrl,
        active: data.active !== false,
      };
    } else if (table === "orders") {
      const status = data.status;
      if (
        !["new", "processing", "shipped", "completed", "cancelled"].includes(
          status,
        )
      )
        fail("Invalid order status");
      if (!id) fail("יצירת הזמנות תתאפשר לאחר חיבור מערכת ההזמנות.", 409);
      const existing = await env.DB.prepare(
        "SELECT data FROM orders WHERE id=?",
      )
        .bind(id)
        .first();
      if (!existing) fail("ההזמנה לא נמצאה.", 404);
      record = {
        ...JSON.parse(existing.data),
        status,
        tracking: str(data.tracking, 200),
        notes: str(data.notes, 2000),
      };
    } else {
      const discount = Number(data.discount);
      if (
        !str(data.code, 40) ||
        !Number.isFinite(discount) ||
        discount <= 0 ||
        discount > 100
      )
        fail("יש להזין קוד ואחוז הנחה בין 1 ל-100.");
      const code = str(data.code, 40).toUpperCase();
      if (!/^[A-Z0-9_-]+$/.test(code))
        fail("הקוד יכול להכיל אותיות באנגלית, מספרים ומקף.");
      if (
        await env.DB.prepare(
          "SELECT id FROM promotions WHERE json_extract(data, '$.code')=? AND id<>?",
        )
          .bind(code, recordId)
          .first()
      )
        fail("קוד זה כבר קיים.", 409);
      for (const date of [data.startsAt, data.endsAt])
        if (
          date &&
          (!/^\d{4}-\d{2}-\d{2}$/.test(date) || Number.isNaN(Date.parse(date)))
        )
          fail("תאריך לא תקין.");
      if (data.startsAt && data.endsAt && data.startsAt > data.endsAt)
        fail("תאריך הסיום קודם לתאריך ההתחלה.");
      const minSubtotal = Number(data.minSubtotal || 0);
      if (!Number.isFinite(minSubtotal) || minSubtotal < 0)
        fail("סכום מינימום אינו תקין.");
      record = {
        id: recordId,
        startsAt: str(data.startsAt, 30),
        minSubtotal,
        excludeSale: data.excludeSale === true,
        code: str(data.code, 40).toUpperCase(),
        discount,
        active: data.active === true,
        endsAt: str(data.endsAt, 30),
      };
    }
    const revision = new Date().toISOString();
    const write =
      method === "PUT"
        ? env.DB.prepare(
            `UPDATE ${table} SET data=?,updated_at=? WHERE id=? AND updated_at=?`,
          ).bind(JSON.stringify(record), revision, recordId, expectedRevision)
        : env.DB.prepare(
            `INSERT INTO ${table}(id,data,updated_at) VALUES(?,?,?)`,
          ).bind(recordId, JSON.stringify(record), revision);
    const statements = [write];
    if (
      table === "products" &&
      (!previousProduct ||
        JSON.stringify([
          previousProduct.stock,
          previousProduct.sizeStock,
          previousProduct.variants,
        ]) !==
          JSON.stringify([record.stock, record.sizeStock, record.variants]))
    ) {
      const movement = {
        id: crypto.randomUUID(),
        productId: recordId,
        delta: record.stock - (previousProduct?.stock || 0),
        reason: previousProduct ? "עריכת מלאי בכרטיס מוצר" : "מלאי פתיחה",
        createdAt: revision,
        before: previousProduct
          ? {
              stock: previousProduct.stock,
              sizeStock: previousProduct.sizeStock,
              variants: previousProduct.variants,
            }
          : null,
        after: {
          stock: record.stock,
          sizeStock: record.sizeStock,
          variants: record.variants,
        },
        system: true,
      };
      statements.push(
        env.DB.prepare(
          "INSERT INTO stock_movements(id,data,updated_at) SELECT ?,?,? WHERE EXISTS(SELECT 1 FROM products WHERE id=? AND updated_at=?)",
        ).bind(
          movement.id,
          JSON.stringify(movement),
          revision,
          recordId,
          revision,
        ),
      );
    }
    if (table === "products")
      statements.push(
        ...stockGuards(env, record, "", "edit:" + crypto.randomUUID()),
      );
    const result = await env.DB.batch(statements);
    if (!result[0].meta.changes) fail("הרשומה השתנתה. רעננו לפני שמירה.", 409);
    if (table === "products")
      await setSetting(env.DB, "catalogueInitialized", true);
    return json({ ...record, revision }, method === "POST" ? 201 : 200);
  } catch (e) {
    if (!e.status) console.error("SHEM API failure", e.message);
    return json(
      {
        error: e.status
          ? e.message
          : "לא ניתן להשלים את הפעולה כרגע. הנתונים לא אבדו, אפשר לנסות שוב.",
      },
      e.status || 503,
    );
  }
}

export async function api(request, env) {
  const channel = await channelAPI(request, env);
  if (channel) return channel;
  if (new URL(request.url).pathname === "/api/home/payment-callback")
    return paymentCallback(request, env);
  const result = await handleApi(request, env);
  if (
    request.method !== "GET" &&
    new URL(request.url).pathname.startsWith("/api/admin/") &&
    result.ok &&
    env.DB
  ) {
    try {
      await env.DB.prepare(
        "INSERT INTO audit(id,actor,action,created_at) VALUES(?,?,?,?)",
      )
        .bind(
          crypto.randomUUID(),
          request.headers.get("oai-authenticated-user-id") || "unknown",
          request.method + " " + new URL(request.url).pathname,
          new Date().toISOString(),
        )
        .run();
    } catch (e) {
      console.error("Audit write failed");
    }
  }
  await notifyChange(request, env, result);
  return result;
}
