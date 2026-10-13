import test from "node:test";
import assert from "node:assert/strict";
import { readdirSync, readFileSync } from "node:fs";
import { Miniflare } from "miniflare";
import { api } from "../worker/api.js";
import { confirmPayment } from "../worker/management.js";
import { productPresentation } from "../worker/customer-experience.js";
import {
  matchesSearch,
  matchesAvailability,
  lineColour,
} from "../src/experience.js";

test("search understands Hebrew/English aliases, a typo and exact available colour-size combinations", () => {
  const p = {
    he: "סריג צמר",
    en: "Premium knit",
    brand: "Loro Piana",
    sku: "KNIT-12",
    sizes: ["M", "L"],
    variants: [
      { id: "bm", size: "M", color: "black", stock: 0 },
      { id: "il", size: "L", color: "ivory", stock: 2 },
    ],
  };
  assert.ok(matchesSearch(p, "לואו פיאנה"));
  assert.ok(matchesSearch(p, "סוודר"));
  assert.ok(matchesSearch(p, "Lroo Piana"));
  assert.ok(matchesSearch(p, "KNIT-12"));
  assert.equal(matchesSearch(p, "Amiri"), false);
  assert.equal(lineColour(p, { variantId: "bm" }), "שחור");
  assert.equal(lineColour(p, { variantId: "il" }), "שנהב");
  assert.equal(matchesAvailability(p, "M", "black", true), false);
  assert.equal(matchesAvailability(p, "L", "ivory", true), true);
  assert.equal(matchesAvailability(p, "M", "ivory", true), false);
});
test("product presentation rejects external images, excessive photos, duplicate sizes and incomplete charts", () => {
  assert.throws(() =>
    productPresentation({
      images: [{ url: "https://untrusted.test/image.png" }],
    }),
  );
  assert.throws(() =>
    productPresentation({
      images: Array.from({ length: 4 }, () => ({ url: "/assets/knit.png" })),
    }),
  );
  assert.throws(() =>
    productPresentation({
      sizeGuide: { columns: ["חזה"], rows: [{ size: "M", values: [] }] },
    }),
  );
  assert.throws(() =>
    productPresentation({
      sizeGuide: {
        columns: ["חזה"],
        rows: [
          { size: "M", values: ["100"] },
          { size: "M", values: ["102"] },
        ],
      },
    }),
  );
  assert.throws(() =>
    productPresentation({ colors: [{ name: "שחור", hex: "javascript:bad" }] }),
  );
});
test("customer account, private guest order, unchanged A4 invoice, stock alerts, help CRUD and consent-based metrics work end to end", async () => {
  const mf = new Miniflare({
    modules: true,
    script:
      'export default {fetch(){return new Response("isolated customer QA")}}',
    compatibilityDate: "2026-08-06",
    d1Databases: ["DB"],
    r2Buckets: ["BUCKET"],
  });
  try {
    const DB = await mf.getD1Database("DB");
    for (const f of readdirSync("drizzle")
      .filter((f) => f.endsWith(".sql"))
      .sort())
      for (const sql of readFileSync("drizzle/" + f, "utf8")
        .replaceAll("--> statement-breakpoint", "")
        .split(";")
        .map((s) => s.trim())
        .filter(Boolean))
        await DB.prepare(sql).run();
    const env = {
      DB,
      BUCKET: await mf.getR2Bucket("BUCKET"),
      ADMIN_EMAILS: "your.shem88@gmail.com",
      OWNER_PRIVATE_BOOTSTRAP: "false",
      INVOICE_DELIVERY_MODE: "download",
    };
    const origin = "https://shem.test",
      admin = {
        "oai-authenticated-user-id": "admin-test",
        "oai-authenticated-user-email": "your.shem88@gmail.com",
      },
      user = {
        "oai-authenticated-user-id": "customer-one",
        "oai-authenticated-user-email": "buyer@example.test",
      },
      other = {
        "oai-authenticated-user-id": "customer-two",
        "oai-authenticated-user-email": "other@example.test",
      };
    const call = async (
      path,
      method = "GET",
      data,
      headers = {},
      status = 200,
    ) => {
      const r = await api(
        new Request(origin + path, {
          method,
          headers: { origin, "content-type": "application/json", ...headers },
          body: data === undefined ? undefined : JSON.stringify(data),
        }),
        env,
      );
      assert.equal(r.status, status, await r.clone().text());
      return r;
    };
    const get = async (path, headers = {}) =>
      (await call(path, "GET", undefined, headers)).json();
    const product = await (
      await call(
        "/api/admin/products",
        "POST",
        {
          he: "סריג לבדיקה מבודדת",
          sku: "CX-1",
          price: 149,
          stock: 2,
          sizes: ["M"],
          sizeStock: { M: 2 },
          image: "knit",
          active: true,
          images: [
            { url: "/assets/knit.png", alt: "חזית" },
            { url: "/assets/shirt.png", alt: "גב" },
            { url: "/assets/trousers.png", alt: "תקריב" },
          ],
          sizeGuide: {
            columns: ["חזה", "אורך"],
            rows: [{ size: "M", values: ["100", "70"] }],
            instructions: "מדדו מסביב לחזה",
          },
          colors: [{ name: "black", hex: "#111111" }],
        },
        admin,
        201,
      )
    ).json();
    const publicP = (await get("/api/catalogue")).products.find(
      (p) => p.id === product.id,
    );
    assert.equal(publicP.images.length, 3);
    assert.equal(publicP.sizeGuide.rows[0].values[1], "70");
    assert.equal(publicP.colors[0].hex, "#111111");
    await call(
      "/api/customer/watches",
      "POST",
      { productId: product.id, size: "M" },
      {},
      401,
    );
    await call(
      "/api/customer/watches",
      "POST",
      { productId: product.id, size: "L" },
      user,
      400,
    );
    await call(
      "/api/customer/watches",
      "POST",
      { productId: product.id, size: "M" },
      user,
      201,
    );
    assert.equal((await get("/api/customer/me", other)).watches.length, 0);
    assert.equal((await get("/api/customer/me", user)).notifications.length, 1);
    await call(
      "/api/customer/preferences",
      "PUT",
      { siteNotifications: false },
      user,
    );
    assert.equal((await get("/api/customer/me", user)).notifications.length, 0);
    await call(
      "/api/customer/preferences",
      "PUT",
      { siteNotifications: true },
      user,
    );
    await call(
      "/api/admin/manage/settings/invoice",
      "PUT",
      {
        legalName: "עסק בדיקה",
        registrationNumber: "TEST",
        address: "כתובת בדיקה",
        phone: "0500000000",
        email: "admin@example.test",
        vatMode: "included",
        vatRate: 18,
        numberPrefix: "QA-",
      },
      admin,
    );
    const order = await (
      await call(
        "/api/admin/manage/orders",
        "POST",
        {
          customer: {
            name: "לקוח בדיקה",
            email: "buyer@example.test",
            phone: "0500000000",
            city: "בדיקה",
            street: "בדיקה 1",
          },
          items: [{ productId: product.id, size: "M", qty: 1 }],
          shippingCost: 49,
          shippingMethod: "standard",
        },
        admin,
        201,
      )
    ).json();
    await DB.prepare(
      "UPDATE orders SET data=json_set(data,'$.viewToken',?) WHERE id=?",
    )
      .bind("isolated-private-token", order.id)
      .run();
    await call("/api/customer/orders/" + order.id, "GET", undefined, {}, 404);
    await call(
      "/api/customer/orders/" + order.id,
      "GET",
      undefined,
      other,
      404,
    );
    await call(
      "/api/customer/orders/" + order.id,
      "GET",
      undefined,
      { authorization: "Bearer wrong" },
      404,
    );
    const mine = await get("/api/customer/me", user);
    assert.equal(mine.orders.length, 1);
    assert.equal(mine.orders[0].invoiceReady, false);
    assert.equal("viewToken" in mine.orders[0], false);
    assert.equal("notes" in mine.orders[0], false);
    assert.equal((await get("/api/customer/me", other)).orders.length, 0);
    const guestHeaders = { authorization: "Bearer isolated-private-token" };
    assert.equal(
      (await get("/api/customer/orders/" + order.id, guestHeaders)).id,
      order.id,
    );
    await call(
      "/api/customer/orders/" + order.id + "/invoice",
      "GET",
      undefined,
      user,
      409,
    );
    await confirmPayment(env, order.id, "isolated-paid-proof", "test");
    assert.equal(
      (await get("/api/customer/me", user)).orders[0].invoiceReady,
      true,
    );
    const invoice = await call(
        "/api/customer/orders/" + order.id + "/invoice",
        "GET",
        undefined,
        user,
      ),
      legacy = await call(
        "/api/checkout-invoice/" + order.id + "?token=isolated-private-token",
      );
    assert.equal(invoice.headers.get("content-type"), "application/pdf");
    assert.deepEqual(
      new Uint8Array(await invoice.arrayBuffer()),
      new Uint8Array(await legacy.arrayBuffer()),
    );
    await call(
      "/api/customer/orders/" + order.id + "/invoice",
      "GET",
      undefined,
      other,
      404,
    );
    assert.equal(
      (
        await call(
          "/api/customer/orders/" + order.id + "/invoice?download=1",
          "GET",
          undefined,
          guestHeaders,
        )
      ).headers
        .get("content-disposition")
        .startsWith("attachment;"),
      true,
    );
    const old = (await get("/api/admin/manage/orders", admin)).find(
      (o) => o.id === order.id,
    );
    await call(
      "/api/admin/manage/orders/" + order.id,
      "PUT",
      {
        ...old,
        status: "shipped",
        tracking: "TRACK-123",
        carrier: "חברת בדיקה",
        trackingUrl: "https://carrier.test/tracking",
        estimatedDelivery: "2026-10-20",
        notes: "INTERNAL-ONLY",
      },
      admin,
    );
    const detail = await get("/api/customer/orders/" + order.id, guestHeaders);
    assert.equal(detail.status, "shipped");
    assert.equal(detail.tracking, "TRACK-123");
    assert.equal(detail.trackingUrl, "https://carrier.test/tracking");
    assert.ok(detail.shipmentHistory.some((h) => h.status === "shipped"));
    assert.equal("notes" in detail, false);
    assert.equal(detail.totalCents, 19800);
    const full = (await get("/api/admin/manage/orders", admin)).find(
      (o) => o.id === order.id,
    );
    await call(
      "/api/admin/manage/orders/" + order.id,
      "PUT",
      { ...full, trackingUrl: "javascript:alert(1)" },
      admin,
      400,
    );
    const help = await get("/api/help");
    assert.ok(help.length >= 8);
    await call(
      "/api/admin/experience",
      "PUT",
      { help, config: { analyticsEnabled: true, siteAlertsEnabled: true } },
      {},
      401,
    );
    await call(
      "/api/admin/experience",
      "PUT",
      {
        help: [
          {
            id: "size-help",
            title: "בחירת מידה",
            answer: "טבלת המידות בדף המוצר",
            category: "product",
            placement: "product",
            action: "shop",
            active: true,
          },
          { id: "hidden", title: "לא פורסם", answer: "טיוטה", active: false },
        ],
        config: { analyticsEnabled: true, siteAlertsEnabled: true },
      },
      admin,
    );
    assert.equal((await get("/api/help")).length, 1);
    assert.equal((await get("/api/help"))[0].placement, "product");
    await call(
      "/api/experience/events",
      "POST",
      {
        sessionId: "isolated-session-12345",
        event: "checkout_start",
        consent: false,
      },
      {},
      400,
    );
    await call(
      "/api/experience/events",
      "POST",
      {
        sessionId: "isolated-session-12345",
        event: "checkout_start",
        consent: true,
        route: "checkout",
        customerEmail: "PRIVATE-DO-NOT-STORE",
      },
      {},
      201,
    );
    const event = await DB.prepare("SELECT detail FROM cx_events").first();
    assert.equal(event.detail.includes("PRIVATE"), false);
    const report = await get("/api/admin/experience", admin);
    assert.equal(
      report.events.find((e) => e.event === "checkout_start").events,
      1,
    );
    assert.equal(report.externalMessagingConnected, false);
    await DB.prepare(
      "UPDATE products SET data=json_set(data,'$.stock',0,'$.sizeStock.M',0) WHERE id=?",
    )
      .bind(product.id)
      .run();
    assert.equal((await get("/api/customer/me", user)).notifications.length, 0);
    await call(
      "/api/admin/experience",
      "PUT",
      { help, config: { analyticsEnabled: false, siteAlertsEnabled: false } },
      admin,
    );
    assert.equal((await get("/api/customer/me", user)).notifications.length, 0);
    await call(
      "/api/customer/watches",
      "POST",
      { productId: product.id, size: "M" },
      user,
      409,
    );
    const watch = (await get("/api/customer/me", user)).watches[0];
    await call(
      "/api/customer/watches/" + encodeURIComponent(watch.id),
      "DELETE",
      {},
      other,
    );
    assert.equal((await get("/api/customer/me", user)).watches.length, 1);
    await call(
      "/api/customer/watches/" + encodeURIComponent(watch.id),
      "DELETE",
      {},
      user,
    );
    assert.equal((await get("/api/customer/me", user)).watches.length, 0);
    await call("/api/shipment-events", "POST", {}, {}, 503);
    env.SHIPPING_WEBHOOK_SECRET = "isolated-shipment-secret";
    const ship = async (event, sigValid = true) => {
      const raw = JSON.stringify(event),
        timestamp = String(Date.now()),
        key = await crypto.subtle.importKey(
          "raw",
          new TextEncoder().encode(env.SHIPPING_WEBHOOK_SECRET),
          { name: "HMAC", hash: "SHA-256" },
          false,
          ["sign"],
        );
      const signature = Buffer.from(
        await crypto.subtle.sign(
          "HMAC",
          key,
          new TextEncoder().encode(timestamp + "." + raw),
        ),
      ).toString("hex");
      return api(
        new Request(origin + "/api/shipment-events", {
          method: "POST",
          headers: {
            "content-type": "application/json",
            "x-shem-timestamp": timestamp,
            "x-shem-signature": sigValid ? signature : "0".repeat(64),
          },
          body: raw,
        }),
        env,
      );
    };
    const shipment = {
      eventId: "isolated-shipping-event-1",
      orderId: order.id,
      status: "completed",
      tracking: "TRACK-123",
      carrier: "חברת בדיקה",
      totalCents: 1,
    };
    assert.equal((await ship(shipment, false)).status, 401);
    assert.equal((await ship(shipment)).status, 200);
    assert.equal(
      (await get("/api/customer/orders/" + order.id, user)).status,
      "completed",
    );
    assert.equal(
      (await get("/api/customer/orders/" + order.id, user)).totalCents,
      19800,
    );
    assert.equal((await (await ship(shipment)).json()).duplicate, true);
    assert.equal(
      (
        await ship({
          ...shipment,
          eventId: "isolated-shipping-event-2",
          status: "processing",
        })
      ).status,
      200,
    );
    assert.equal(
      (await get("/api/customer/orders/" + order.id, user)).status,
      "completed",
    );
    const race = await Promise.all(
      ["race-1", "race-2"].map((eventId) =>
        ship({ ...shipment, eventId, tracking: eventId }),
      ),
    );
    assert.ok(race.some((r) => r.status === 200));
    assert.ok(race.every((r) => [200, 409].includes(r.status)));
    assert.equal(
      (await DB.prepare("SELECT COUNT(*) AS n FROM mail_jobs").first()).n,
      0,
    );
  } finally {
    await mf.dispose();
  }
});
