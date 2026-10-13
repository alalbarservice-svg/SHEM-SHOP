import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { Miniflare } from "miniflare";
const origin = "https://shem.test";
test("protected persistent catalogue and club enforce identity, validation and same-origin writes", async () => {
  const mf = new Miniflare({
    modules: true,
    scriptPath: "dist/server/index.js",
    compatibilityDate: "2026-08-06",
    d1Databases: ["DB"],
    r2Buckets: ["BUCKET"],
    bindings: {
      OWNER_PRIVATE_BOOTSTRAP: "true",
      ADMIN_CONTACT_EMAIL: "your.shem88@gmail.com",
    },
  });
  try {
    const db = await mf.getD1Database("DB");
    for (const f of readdirSync("drizzle").filter((f) => f.endsWith(".sql")))
      for (const sql of readFileSync("drizzle/" + f, "utf8")
        .replace(/--> statement-breakpoint/g, "")
        .split(";")
        .map((x) => x.trim())
        .filter(Boolean))
        await db.prepare(sql).run();
    const call = async (path, method = "GET", data, uid = "owner") =>
      mf.dispatchFetch(origin + path, {
        method,
        headers: {
          origin: origin,
          "content-type": "application/json",
          ...(uid ? { "oai-authenticated-user-id": uid } : {}),
        },
        body: data ? JSON.stringify(data) : undefined,
      });
    assert.equal(
      (await call("/api/admin/products", "GET", null, null)).status,
      401,
    );
    assert.equal((await call("/api/admin/session")).status, 200);
    assert.equal(
      (await call("/api/admin/products", "GET", null, "other")).status,
      403,
    );
    assert.equal(
      (
        await mf.dispatchFetch(origin + "/api/admin/products", {
          method: "POST",
          headers: {
            origin: "https://evil.test",
            "content-type": "application/json",
            "oai-authenticated-user-id": "owner",
          },
          body: "{}",
        })
      ).status,
      403,
    );
    assert.equal(
      (
        await call("/api/admin/products", "POST", {
          he: "Invalid",
          price: -1,
          stock: 0,
        })
      ).status,
      400,
    );
    const item = {
      he: "מוצר בדיקה",
      en: "Test piece",
      brand: "SHEM",
      image: "knit",
      price: 149,
      previousPrice: 199,
      stock: 3,
      sizes: ["M"],
      active: true,
    };
    const response = await call("/api/admin/products", "POST", item);
    assert.equal(response.status, 201);
    const product = await response.json();
    const catalogue = await (await call("/api/catalogue")).json();
    assert.equal(catalogue.products[0].price, 149);
    assert.equal(catalogue.configured, true);
    await call("/api/admin/products/" + product.id, "PUT", {
      ...product,
      active: false,
    });
    assert.equal(
      (await (await call("/api/catalogue")).json()).products.some(
        (p) => p.id === product.id,
      ),
      false,
    );
    assert.equal(
      (
        await call("/api/club", "POST", {
          email: "qa@example.com",
          consent: true,
        })
      ).status,
      409,
    );
    assert.equal(
      (
        await call("/api/admin/settings", "PUT", {
          key: "club",
          value: { enabled: true, title: "Club", privacyUrl: "" },
        })
      ).status,
      400,
    );
    assert.equal(
      (
        await call("/api/admin/settings", "PUT", {
          key: "club",
          value: {
            enabled: true,
            title: "Club",
            privacyUrl: "https://example.com/privacy",
          },
        })
      ).status,
      200,
    );
    assert.equal(
      (
        await call("/api/club", "POST", {
          email: "qa@example.com",
          consent: false,
        })
      ).status,
      400,
    );
    assert.equal(
      (
        await call("/api/club", "POST", {
          email: "qa@example.com",
          consent: true,
        })
      ).status,
      200,
    );
    assert.equal(
      (
        await call("/api/club", "POST", {
          email: "qa@example.com",
          consent: true,
        })
      ).status,
      200,
    );
    assert.equal((await (await call("/api/admin/members")).json()).length, 1);
    assert.equal(
      (
        await call("/api/admin/settings", "PUT", {
          key: "adminPrincipal",
          value: "other",
        })
      ).status,
      400,
    );
    const uploaded = await mf.dispatchFetch(origin + "/api/admin/media", {
      method: "POST",
      headers: {
        origin,
        "content-type": "image/png",
        "oai-authenticated-user-id": "owner",
      },
      body: readFileSync("public/assets/knit.png"),
    });
    assert.equal(uploaded.status, 201);
    const media = await uploaded.json();
    const asset = await mf.dispatchFetch(origin + media.imageUrl);
    assert.equal(asset.status, 200);
    assert.equal(asset.headers.get("content-type"), "image/png");
    await db.prepare("DELETE FROM settings WHERE key='adminPrincipal'").run();
    await mf.setOptions({
      modules: true,
      scriptPath: "dist/server/index.js",
      compatibilityDate: "2026-08-06",
      d1Databases: ["DB"],
      r2Buckets: ["BUCKET"],
      bindings: {
        OWNER_PRIVATE_BOOTSTRAP: "false",
        ADMIN_USER_IDS: "approved-user",
      },
    });
    assert.equal(
      (await call("/api/admin/session", "GET", null, "intruder")).status,
      403,
    );
    assert.equal(
      (await call("/api/admin/session", "GET", null, "approved-user")).status,
      200,
    );
    const self = await (
      await call("/api/session", "GET", null, "approved-user")
    ).json();
    assert.equal(self.userId, "approved-user");
    await mf.setOptions({
      modules: true,
      scriptPath: "dist/server/index.js",
      compatibilityDate: "2026-08-06",
      d1Databases: ["DB"],
      r2Buckets: ["BUCKET"],
      bindings: {
        OWNER_PRIVATE_BOOTSTRAP: "false",
        ADMIN_EMAILS: "your.shem88@gmail.com",
        ADMIN_CONTACT_EMAIL: "contact-only@example.com",
      },
    });
    const asEmail = (
      email,
      uid = "manager",
      path = "/api/admin/session",
      method = "GET",
      data,
    ) =>
      mf.dispatchFetch(origin + path, {
        method,
        headers: {
          origin,
          "content-type": "application/json",
          "oai-authenticated-user-email": email,
          ...(uid ? { "oai-authenticated-user-id": uid } : {}),
        },
        body: data ? JSON.stringify(data) : undefined,
      });
    assert.equal((await asEmail("your.shem88@gmail.com", null)).status, 401);
    assert.equal((await asEmail("other@example.com")).status, 403);
    assert.equal((await asEmail("contact-only@example.com")).status, 403);
    const managerSession = await asEmail(" Your.Shem88@gmail.com ");
    assert.equal(managerSession.status, 200);
    assert.equal((await managerSession.json()).authorized, true);
    assert.equal(
      (await asEmail("your.shem88@gmail.com", "manager", "/api/admin/products"))
        .status,
      200,
    );
    assert.equal(
      (
        await asEmail(
          "your.shem88@gmail.com",
          "manager",
          "/api/admin/settings",
          "PUT",
          { key: "store", value: { heroSubtitle: "שמירה מסונכרנת" } },
        )
      ).status,
      200,
    );
    const synced = await (
      await asEmail("your.shem88@gmail.com", "manager", "/api/admin/settings")
    ).json();
    assert.equal(synced.store.heroSubtitle, "שמירה מסונכרנת");
    assert.equal(
      (await asEmail("other@example.com", "manager", "/api/admin/settings"))
        .status,
      403,
    );
  } finally {
    await mf.dispose();
  }
});
