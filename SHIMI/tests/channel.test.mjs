import test from "node:test";
import assert from "node:assert/strict";
import { Miniflare } from "miniflare";
import { readFileSync, readdirSync } from "node:fs";
import { api } from "../worker/api.js";
import { sign } from "../worker/management.js";
import { reserveStock, releaseStock, peerOrigins } from "../worker/channel.js";
const sourceOrigin = "https://shem.test",
  homeOrigin = "https://shimi.test",
  secret = "isolated-channel-secret",
  paySecret = "isolated-payment-secret",
  adapterSecret = "isolated-adapter-secret";
async function setup() {
  const mfs = [];
  const environments = {};
  for (const [host, role] of [
    [sourceOrigin, "source"],
    [homeOrigin, "home"],
  ]) {
    const mf = new Miniflare({
      modules: true,
      script: 'export default {fetch(){return new Response("test")}}',
      compatibilityDate: "2026-08-06",
      d1Databases: ["DB"],
      r2Buckets: ["BUCKET"],
    });
    mfs.push(mf);
    const DB = await mf.getD1Database("DB");
    for (const f of readdirSync("drizzle")
      .filter((f) => f.endsWith(".sql") && !f.startsWith("0004_"))
      .sort())
      for (const sql of readFileSync("drizzle/" + f, "utf8")
        .replaceAll("--> statement-breakpoint", "")
        .split(";")
        .map((s) => s.trim())
        .filter(Boolean))
        await DB.prepare(sql).run();
    environments[host] = {
      DB,
      BUCKET: await mf.getR2Bucket("BUCKET"),
      SHOP_ROLE: role,
      ADMIN_EMAILS: "your.shem88@gmail.com",
      OWNER_PRIVATE_BOOTSTRAP: "false",
      CHANNEL_SECRET: secret,
      HOME_PAYMENT_SECRET: paySecret,
      SHEM_ORIGIN: sourceOrigin,
      SHIMI_ORIGIN: homeOrigin,
      HOME_CHECKOUT_URL: homeOrigin + "/api/channel/checkout",
      PAYMENT_ADAPTER_URL: "https://adapter.test/create",
      PAYMENT_ADAPTER_SECRET: adapterSecret,
      PAYMENT_CHECKOUT_ORIGIN: "https://checkout.test",
    };
  }
  const fetchSaved = globalThis.fetch;
  let creations = 0,
    sourceReads = 0;
  globalThis.fetch = async (url, init) => {
    if(init?.redirect) assert.equal(init.redirect,"manual","Edge fetch must reject redirects manually");
    const u = new URL(
      typeof url === "string" || url instanceof URL ? url : url.url,
    );
    if (u.origin === "https://adapter.test") {
      creations++;
      return Response.json({ checkoutUrl: "https://checkout.test/session" });
    }
    if (u.origin === sourceOrigin && u.pathname === "/api/channel/sources")
      sourceReads++;
    const env = environments[u.origin];
    if (!env) throw Error("Unexpected external request " + u);
    return api(new Request(url, init), env);
  };
  const call = async (
    host,
    path,
    method = "GET",
    data,
    expected = 200,
    admin = true,
  ) => {
    const r = await api(
      new Request(host + path, {
        method,
        headers: {
          origin: host,
          "content-type": "application/json",
          ...(admin
            ? {
                "oai-authenticated-user-id": "manager",
                "oai-authenticated-user-email": "your.shem88@gmail.com",
              }
            : {}),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
      }),
      environments[host],
    );
    assert.equal(r.status, expected, await r.clone().text());
    return r.json();
  };
  const signed = async (host, path, data, key = secret, legacy = false) => {
    const raw = JSON.stringify(data),
      ts = String(Math.floor(Date.now() / 1000));
    return api(
      new Request(host + path, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          [legacy ? "x-shem-timestamp" : "x-channel-timestamp"]: ts,
          [legacy ? "x-shem-signature" : "x-channel-signature"]: await sign(
            key,
            legacy ? ts + "." + raw : [ts, "POST", path, raw].join("."),
          ),
        },
        body: raw,
      }),
      environments[host],
    );
  };
  return {
    environments,
    call,
    signed,
    get sourceReads() {
      return sourceReads;
    },
    get creations() {
      return creations;
    },
    close: async () => {
      globalThis.fetch = fetchSaved;
      for (const mf of mfs) await mf.dispose();
    },
  };
}
test("manual product links synchronize only SKU/prices, require identity, preserve copy, and honor central stock", async () => {
  const t = await setup();
  try {
    const { call, environments } = t;
    const s = await call(
      sourceOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "Source item",
        sku: "SOURCE-1",
        price: 100,
        previousPrice: 150,
        stock: 2,
        sizeStock: { M: 2 },
        sizes: ["M"],
        color: "black",
        active: true,
        image: "knit",
      },
      201,
    );
    const h = await call(
      homeOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "Independent copy",
        brand: "SHIMI",
        sku: "HOME-1",
        price: 50,
        stock: 0,
        sizeStock: { M: 0 },
        sizes: ["M"],
        color: "green",
        active: true,
        image: "shirt",
      },
      201,
    );
    await call(
      homeOrigin,
      "/api/admin/channel/status",
      "GET",
      undefined,
      401,
      false,
    );
    await call(
      homeOrigin,
      `/api/admin/channel/products/${h.id}/link`,
      "POST",
      { sourceUrl: "https://attacker.test/api/channel/source/" + s.id },
      400,
    );
    await call(homeOrigin, `/api/admin/channel/products/${h.id}/link`, "POST", {
      sourceUrl: sourceOrigin + "/api/channel/source/" + s.id,
      variantMap: {},
    });
    let hp = (await call(homeOrigin, "/api/admin/products")).find(
      (p) => p.id === h.id,
    );
    assert.equal(hp.sku, "SOURCE-1");
    assert.equal(hp.price, 100);
    assert.equal(hp.previousPrice, 150);
    assert.equal(hp.he, "Independent copy");
    assert.equal(hp.brand, "SHIMI");
    assert.equal(hp.color, "green");
    assert.equal(
      hp.stock,
      0,
      "home stock is not persisted as a copy of source stock",
    );
    await call(
      homeOrigin,
      "/api/admin/products/" + h.id,
      "PUT",
      { ...hp, price: 1 },
      409,
    );
    let sp = (await call(sourceOrigin, "/api/admin/products")).find(
      (p) => p.id === s.id,
    );
    await call(sourceOrigin, "/api/admin/products/" + s.id, "PUT", {
      ...sp,
      price: 80,
      previousPrice: 120,
    });
    hp = (await call(homeOrigin, "/api/admin/products")).find(
      (p) => p.id === h.id,
    );
    assert.equal(hp.price, 80);
    assert.equal(hp.previousPrice, 120);
    const catalogue = await call(homeOrigin, "/api/catalogue");
    assert.equal(catalogue.products[0].stock, 2);
    assert.ok(!JSON.stringify(catalogue).includes("sourceUrl"));
    assert.ok(!JSON.stringify(catalogue).includes("homeProductId"));
    await reserveStock(environments[sourceOrigin], "stock-test", [
      { productId: s.id, size: "M", variantId: "", qty: 2, priceCents: 8000 },
    ]);
    assert.equal(
      (await call(homeOrigin, "/api/catalogue")).products[0].stock,
      0,
    );
    await assert.rejects(() =>
      reserveStock(environments[sourceOrigin], "other-test", [
        { productId: s.id, size: "M", qty: 1 },
      ]),
    );
    await releaseStock(environments[sourceOrigin], "stock-test");
    assert.equal(
      (await call(homeOrigin, "/api/catalogue")).products[0].stock,
      2,
    );
    await call(
      homeOrigin,
      `/api/admin/channel/products/${h.id}/unlink`,
      "POST",
      {},
    );
    hp = (await call(homeOrigin, "/api/admin/products")).find(
      (p) => p.id === h.id,
    );
    assert.equal(hp.active, false);
    await call(homeOrigin, "/api/admin/products/" + h.id, "PUT", {
      ...hp,
      price: 60,
    });
  } finally {
    await t.close();
  }
});
test("SHEM final totals are authoritative; masked signed payment is idempotent; mixed SHIMI carts deduct central/local stock once", async () => {
  const t = await setup();
  try {
    const { call, signed, environments } = t;
    environments[homeOrigin].PAYMENT_ADAPTER_ENABLED = "true";
    await call(homeOrigin, "/api/admin/channel/config", "PUT", {
      provider: "test",
      merchantId: "test",
      collectorName: "Isolated merchant",
    });
    const s = await call(
      sourceOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "Source",
        sku: "S-1",
        price: 199,
        stock: 4,
        sizes: ["M"],
        sizeStock: { M: 4 },
        active: true,
        image: "knit",
      },
      201,
    );
    const h = await call(
      homeOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "Home",
        sku: "H-1",
        price: 50,
        stock: 0,
        sizes: ["M"],
        sizeStock: { M: 0 },
        active: true,
        image: "shirt",
      },
      201,
    );
    await call(homeOrigin, `/api/admin/channel/products/${h.id}/link`, "POST", {
      sourceUrl: sourceOrigin + "/api/channel/source/" + s.id,
      variantMap: {},
    });
    await call(sourceOrigin, "/api/admin/manage/settings/operations", "PUT", {
      checkoutEnabled: true,
      standardPrice: 49,
      expressPrice: 199,
    });
    await call(sourceOrigin, "/api/admin/manage/settings/home", "PUT", {
      enabled: true,
      siteUrl: homeOrigin,
    });
    await call(
      sourceOrigin,
      "/api/admin/promotions",
      "POST",
      { code: "SAVE10", discount: 10, active: true, minSubtotal: 0 },
      201,
    );
    const customer = {
      name: "Isolated customer",
      email: "qa@example.test",
      phone: "0500000000",
      city: "Test",
      street: "Test 1",
    };
    const checkout = await call(
      sourceOrigin,
      "/api/checkout-home",
      "POST",
      {
        items: [{ id: s.id, size: "M", qty: 1 }],
        shipping: "express",
        code: "SAVE10",
        customer,
        termsAccepted: true,
      },
      201,
      false,
    );
    const order = (await call(sourceOrigin, "/api/admin/orders")).find(
      (o) => o.id === checkout.orderId,
    );
    assert.equal(order.totalCents, 37810);
    const session = (await call(homeOrigin, "/api/admin/channel/status"))
      .sessions[0];
    assert.equal(session.amountCents, order.totalCents);
    assert.equal(session.discountCents, 1990);
    assert.equal(session.shippingCents, 19900);
    let r = await signed(
      homeOrigin,
      "/api/channel/provider-callback",
      {
        orderId: session.id,
        status: "paid",
        amountCents: 1,
        currency: "ILS",
        transactionId: "tx1",
      },
      adapterSecret,
      true,
    );
    assert.equal(r.status, 409);
    const event = {
      orderId: session.id,
      status: "paid",
      amountCents: 37810,
      currency: "ILS",
      transactionId: "tx1",
      paymentDetails: { method: "card", last4: "1234", cardholderName: "Test" },
    };
    r = await signed(
      homeOrigin,
      "/api/channel/provider-callback",
      event,
      adapterSecret,
      true,
    );
    assert.equal(r.status, 200, await r.text());
    r = await signed(
      homeOrigin,
      "/api/channel/provider-callback",
      event,
      adapterSecret,
      true,
    );
    assert.equal(r.status, 200, await r.text());
    assert.equal(
      (await call(sourceOrigin, "/api/admin/products")).find(
        (p) => p.id === s.id,
      ).stock,
      3,
    );
    assert.equal(
      (await call(sourceOrigin, "/api/admin/orders")).find(
        (o) => o.id === checkout.orderId,
      ).paymentDetails.last4,
      "1234",
    );
    const independent = await call(
      homeOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "Independent",
        sku: "I-1",
        price: 50,
        stock: 2,
        sizeStock: { M: 2 },
        sizes: ["M"],
        active: true,
        image: "shirt",
      },
      201,
    );
    await call(homeOrigin, "/api/admin/manage/settings/operations", "PUT", {
      checkoutEnabled: true,
      standardPrice: 49,
      expressPrice: 199,
    });
    const own = await call(
      homeOrigin,
      "/api/shimi/checkout",
      "POST",
      {
        items: [
          { id: h.id, size: "M", qty: 1 },
          { id: independent.id, size: "M", qty: 1 },
        ],
        shipping: "standard",
        customer,
        termsAccepted: true,
      },
      201,
      false,
    );
    const ownSession = (
      await call(homeOrigin, "/api/admin/channel/status")
    ).sessions.find((s) => s.orderId === own.orderId);
    assert.equal(ownSession.amountCents, 29800);
    r = await signed(
      homeOrigin,
      "/api/channel/provider-callback",
      {
        orderId: ownSession.id,
        status: "paid",
        amountCents: 29800,
        currency: "ILS",
        transactionId: "tx2",
      },
      adapterSecret,
      true,
    );
    assert.equal(r.status, 200, await r.text());
    r = await signed(
      homeOrigin,
      "/api/channel/provider-callback",
      {
        orderId: ownSession.id,
        status: "paid",
        amountCents: 29800,
        currency: "ILS",
        transactionId: "tx2",
      },
      adapterSecret,
      true,
    );
    assert.equal(r.status, 200, await r.text());
    assert.equal(
      (await call(sourceOrigin, "/api/admin/products")).find(
        (p) => p.id === s.id,
      ).stock,
      2,
    );
    assert.equal(
      (await call(homeOrigin, "/api/admin/products")).find(
        (p) => p.id === independent.id,
      ).stock,
      1,
    );
    assert.equal(
      (await call(homeOrigin, "/api/admin/products")).find((p) => p.id === h.id)
        .stock,
      0,
    );
    assert.equal(t.creations, 2);
    environments[homeOrigin].PAYMENT_ADAPTER_ENABLED = "false";
    await call(
      homeOrigin,
      "/api/shimi/checkout",
      "POST",
      {
        items: [{ id: independent.id, size: "M", qty: 1 }],
        shipping: "standard",
        customer,
        termsAccepted: true,
      },
      503,
      false,
    );
  } finally {
    await t.close();
  }
});

test("persisted demo catalogue stays separate and its prepared link synchronizes actual source edits", async () => {
  const t = await setup();
  try {
    for (const [host, fixture] of [
      [sourceOrigin, "source"],
      [homeOrigin, "home"],
    ]) {
      const sqlFile = readFileSync(
        new URL("./fixtures/demo-" + fixture + ".sql", import.meta.url),
        "utf8",
      );
      for (let pass = 0; pass < 2; pass++)
        for (const sql of sqlFile
          .replaceAll("--> statement-breakpoint", "")
          .split(";")
          .map((s) => s.trim())
          .filter(Boolean))
          await t.environments[host].DB.prepare(sql).run();
    }
    const localFixture = readFileSync(
      new URL(
        "./fixtures/demo-home.sql",
        import.meta.url,
      ),
      "utf8",
    );
    assert.equal(
      readFileSync("drizzle/0004_demo_catalogue.sql", "utf8"),
      localFixture,
    );
    const { call } = t;
    const catalogue = await call(
      homeOrigin,
      "/api/catalogue",
      "GET",
      undefined,
      200,
      false,
    );
    assert.equal(catalogue.products.length, 3);
    assert.ok(
      catalogue.products.every(
        (p) =>
          p.demo &&
          p.brand === "SHIMI" &&
          p.stock === 0 &&
          p.imageUrl.startsWith("/assets/"),
      ),
    );
    assert.ok(!JSON.stringify(catalogue).includes("sourceUrl"));
    const sourceCatalogue = await call(
      sourceOrigin,
      "/api/catalogue",
      "GET",
      undefined,
      200,
      false,
    );
    assert.equal(sourceCatalogue.products.length, 0);
    assert.equal(sourceCatalogue.configured, false);
    await call(homeOrigin, "/api/admin/products", "GET", undefined, 401, false);
    const id = "demo-shimi-sync-knit",
      sid = "demo-shem-sync-knit";
    const initial = await call(
      homeOrigin,
      "/api/admin/channel/products/" + id + "/source",
    );
    assert.equal(initial.link.sourceId, sid);
    assert.equal(Object.keys(initial.link.variantMap).length, 2);
    const result = await call(
      homeOrigin,
      "/api/admin/channel/products/" + id + "/sync",
      "POST",
      {},
    );
    assert.equal(result.source.sku, "DEMO-SYNC-001");
    assert.equal(result.product.price, 179);
    const source = (await call(sourceOrigin, "/api/admin/products")).find(
      (p) => p.id === sid,
    );
    await call(sourceOrigin, "/api/admin/products/" + sid, "PUT", {
      ...source,
      sku: "DEMO-SYNC-002",
      price: 159,
      previousPrice: 239,
    });
    const home = (await call(homeOrigin, "/api/admin/products")).find(
      (p) => p.id === id,
    );
    assert.equal(home.sku, "DEMO-SYNC-002");
    assert.equal(home.price, 159);
    assert.equal(home.previousPrice, 239);
    assert.equal(home.he, "סריג בייסיק - הדגמת סנכרון");
    assert.equal(home.brand, "SHIMI");
    assert.equal(home.active, false);
    assert.equal(home.stock, 0);
    assert.equal(home.imageUrl, "/assets/knit.png");
    assert.equal(
      (await call(homeOrigin, "/api/catalogue", "GET", undefined, 200, false))
        .products.length,
      3,
    );
    const link = (
      await call(homeOrigin, "/api/admin/channel/products/" + id + "/source")
    ).link;
    assert.equal(link.status, "source-inactive");
    assert.ok(link.lastSyncAt);
  } finally {
    await t.close();
  }
});

test("pre-payment review selects exact linked variants and mirrors discounts, sale prices, VAT and shipping without creating orders", async () => {
  const t = await setup();
  try {
    const { call, environments, signed } = t;
    const source = await call(
      sourceOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "סריג בדיקת איכות",
        brand: "SHEM",
        sku: "QA-KNIT",
        price: 200,
        stock: 10,
        sizes: ["M", "L"],
        sizeStock: { M: 6, L: 4 },
        variants: [
          {
            id: "qa-source-m",
            sku: "QA-KNIT-M",
            size: "M",
            color: "שחור",
            stock: 6,
          },
          {
            id: "qa-source-l",
            sku: "QA-KNIT-L",
            size: "L",
            color: "שחור",
            stock: 4,
          },
        ],
        active: true,
        image: "knit",
      },
      201,
    );
    const home = await call(
      homeOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "סריג SHIMI",
        brand: "SHIMI",
        sku: "HOME-KNIT",
        price: 50,
        stock: 0,
        sizes: ["M", "L"],
        sizeStock: { M: 0, L: 0 },
        variants: [
          {
            id: "qa-home-m",
            sku: "HOME-KNIT-M",
            size: "M",
            color: "שחור",
            stock: 0,
          },
          {
            id: "qa-home-l",
            sku: "HOME-KNIT-L",
            size: "L",
            color: "שחור",
            stock: 0,
          },
        ],
        active: true,
        image: "shirt",
      },
      201,
    );
    await call(
      homeOrigin,
      `/api/admin/channel/products/${home.id}/link`,
      "POST",
      {
        sourceUrl: sourceOrigin + "/api/channel/source/" + source.id,
        variantMap: { "qa-home-m": "qa-source-m", "qa-home-l": "qa-source-l" },
      },
    );
    const second = await call(
      sourceOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "חולצה בדיקת איכות",
        sku: "QA-SHIRT",
        price: 129.9,
        stock: 8,
        sizes: ["L"],
        sizeStock: { L: 8 },
        active: true,
        image: "shirt",
      },
      201,
    );
    const secondHome = await call(
      homeOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "חולצת SHIMI",
        sku: "HOME-SHIRT",
        price: 99,
        stock: 0,
        sizes: ["L"],
        sizeStock: { L: 0 },
        active: true,
        image: "shirt",
      },
      201,
    );
    await call(
      homeOrigin,
      `/api/admin/channel/products/${secondHome.id}/link`,
      "POST",
      {
        sourceUrl: sourceOrigin + "/api/channel/source/" + second.id,
        variantMap: {},
      },
    );
    const data = {
      items: [
        { id: source.id, variantId: "qa-source-m", size: "M", qty: 1 },
        { id: second.id, size: "L", qty: 2 },
      ],
      shipping: "standard",
      code: "",
      customer: {
        name: "בדיקת איכות",
        email: "qa@example.test",
        phone: "0500000000",
        city: "בדיקה",
        street: "בדיקה 1",
      },
      termsAccepted: true,
    };
    const rows = [];
    const review = async (name, body, expected) => {
      const start = performance.now();
      const d = await call(
        sourceOrigin,
        "/api/checkout-review",
        "POST",
        body,
        200,
        false,
      );
      assert.equal(d.verified, true);
      assert.deepEqual(d.source, d.home);
      assert.equal(d.source.totalCents, expected);
      assert.equal(d.source.items.length, body.items.length);
      for (const [index, line] of body.items.entries()) {
        const actual = d.source.items[index];
        assert.equal(
          actual.sku,
          line.id === source.id
            ? line.variantId === "qa-source-l"
              ? "QA-KNIT-L"
              : "QA-KNIT-M"
            : "QA-SHIRT",
        );
        assert.equal(actual.qty, line.qty);
        assert.equal(actual.size, line.size);
        if (line.id === second.id) assert.equal(actual.priceCents, 12990);
      }
      assert.ok(!JSON.stringify(d).includes("sourceUrl"));
      assert.ok(!JSON.stringify(d).includes(home.id));
      rows.push({
        name,
        source: d.source,
        home: d.home,
        elapsedMs: Math.round(performance.now() - start),
      });
      return d;
    };
    const regularReview = await review("מחיר רגיל ומשלוח רגיל", data, 50880);
    let fresh = (await call(sourceOrigin, "/api/admin/products")).find(
      (p) => p.id === source.id,
    );
    await call(sourceOrigin, "/api/admin/products/" + source.id, "PUT", {
      ...fresh,
      price: 179,
      previousPrice: 219,
    });
    await review("מחיר מבצע", data, 48780);
    const coupon = await call(
      sourceOrigin,
      "/api/admin/promotions",
      "POST",
      { code: "QA10", discount: 10, active: true, minSubtotal: 0 },
      201,
    );
    const discounted = await review(
      "מבצע וקופון 10%",
      { ...data, code: "QA10" },
      44392,
    );
    await review(
      "מבצע וקופון עם משלוח מהיר",
      { ...data, code: "QA10", shipping: "express" },
      59392,
    );
    await call(sourceOrigin, "/api/admin/promotions/" + coupon.id, "PUT", {
      ...coupon,
      excludeSale: true,
    });
    await review(
      "קופון אינו חל על פריטי מבצע",
      { ...data, code: "QA10" },
      46182,
    );
    await call(sourceOrigin, "/api/admin/manage/settings/operations", "PUT", {
      checkoutEnabled: true,
      standardPrice: 49,
      expressPrice: 199,
    });
    await call(sourceOrigin, "/api/admin/manage/settings/home", "PUT", {
      enabled: true,
      siteUrl: homeOrigin,
    });
    await call(
      sourceOrigin,
      "/api/checkout-home",
      "POST",
      { ...data, reviewToken: regularReview.reviewToken },
      409,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-home",
      "POST",
      { ...data, code: "QA10", reviewToken: discounted.reviewToken },
      409,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-home",
      "POST",
      { ...data, reviewToken: "1.invalid" },
      409,
      false,
    );
    const homeCatalogue = await call(
      homeOrigin,
      "/api/catalogue",
      "GET",
      undefined,
      200,
      false,
    );
    assert.equal(
      homeCatalogue.products.find((p) => p.id === home.id).price,
      179,
    );
    assert.equal(
      homeCatalogue.products
        .find((p) => p.id === home.id)
        .variants.find((v) => v.id === "qa-home-m").sku,
      "QA-KNIT-M",
    );
    await review(
      "מחיר וסכום מזויפים מהדפדפן נזנחים",
      {
        ...data,
        total: 1,
        discount: 9999,
        items: data.items.map((i) => ({ ...i, price: 1 })),
      },
      48780,
    );
    await review("הסרת פריט מהסל", { ...data, items: [data.items[0]] }, 22800);
    await review(
      "שינוי כמות",
      { ...data, items: [data.items[0], { ...data.items[1], qty: 1 }] },
      35790,
    );
    await review(
      "שתי מידות נפרדות באותו מוצר",
      {
        ...data,
        items: [
          data.items[0],
          { ...data.items[0], variantId: "qa-source-l", size: "L" },
          data.items[1],
        ],
      },
      66680,
    );
    fresh = (await call(sourceOrigin, "/api/admin/products")).find(
      (p) => p.id === source.id,
    );
    await call(sourceOrigin, "/api/admin/products/" + source.id, "PUT", {
      ...fresh,
      price: 199.99,
    });
    await call(sourceOrigin, "/api/admin/promotions/" + coupon.id, "PUT", {
      ...(await call(sourceOrigin, "/api/admin/promotions")).find(
        (p) => p.id === coupon.id,
      ),
      excludeSale: false,
    });
    await review("עיגול אגורות אחרי קופון", { ...data, code: "QA10" }, 46281);
    await call(
      sourceOrigin,
      "/api/admin/promotions",
      "POST",
      {
        code: "QAEXPIRED",
        discount: 10,
        active: true,
        minSubtotal: 0,
        startsAt: "2020-01-01",
        endsAt: "2020-12-31",
      },
      201,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, code: "QAEXPIRED" },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/admin/promotions",
      "POST",
      { code: "QAMIN", discount: 10, active: true, minSubtotal: 1000 },
      201,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, code: "QAMIN" },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, items: [{ ...data.items[0], qty: 7 }] },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, items: [data.items[0], data.items[0]] },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, items: [{ ...data.items[0], size: "XL" }] },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, code: "MISSING" },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, termsAccepted: false },
      400,
      false,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, customer: { ...data.customer, email: "invalid" } },
      400,
      false,
    );
    const unlinked = await call(
      sourceOrigin,
      "/api/admin/products",
      "POST",
      {
        he: "לא מקושר",
        sku: "QA-UNLINKED",
        price: 50,
        stock: 1,
        sizes: ["M"],
        sizeStock: { M: 1 },
        active: true,
        image: "knit",
      },
      201,
    );
    await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, items: [{ id: unlinked.id, size: "M", qty: 1 }] },
      409,
      false,
    );
    const tampered = { ...discounted.source, totalCents: 1 };
    assert.equal(
      (
        await signed(homeOrigin, "/api/channel/review", {
          protocol: "shem-review-v1",
          summary: tampered,
        })
      ).status,
      409,
    );
    const key = environments[sourceOrigin].CHANNEL_SECRET;
    environments[sourceOrigin].CHANNEL_SECRET = "incorrect-secret";
    await call(sourceOrigin, "/api/checkout-review", "POST", data, 502, false);
    environments[sourceOrigin].CHANNEL_SECRET = key;
    // The supported 100-line cart must not trigger a peer request for every item.
    const bulk = [],
      stamp = new Date().toISOString();
    for (let n = 0; n < 100; n++) {
      const sid = "qa-bulk-source-" + n,
        hid = "qa-bulk-home-" + n;
      const base = {
        he: "QA bulk",
        en: "QA bulk",
        sku: "QA-BULK-" + n,
        price: 1,
        stock: 1,
        sizeStock: { M: 1 },
        sizes: ["M"],
        variants: [],
        active: true,
        color: "black",
        image: "knit",
      };
      await environments[sourceOrigin].DB.prepare(
        "INSERT INTO products(id,data,updated_at) VALUES(?,?,?)",
      )
        .bind(sid, JSON.stringify({ ...base, id: sid }), stamp)
        .run();
      await environments[homeOrigin].DB.prepare(
        "INSERT INTO products(id,data,updated_at) VALUES(?,?,?)",
      )
        .bind(
          hid,
          JSON.stringify({
            ...base,
            id: hid,
            he: "SHIMI bulk",
            stock: 0,
            sizeStock: { M: 0 },
          }),
          stamp,
        )
        .run();
      await environments[homeOrigin].DB.prepare(
        "INSERT INTO product_links(id,data,updated_at) VALUES(?,?,?)",
      )
        .bind(
          hid,
          JSON.stringify({
            id: hid,
            sourceId: sid,
            sourceUrl: sourceOrigin + "/api/channel/source/" + sid,
            variantMap: {},
            status: "pending",
          }),
          stamp,
        )
        .run();
      bulk.push({ id: sid, size: "M", qty: 1 });
    }
    const reads = t.sourceReads,
      started = performance.now();
    const bulkReview = await call(
      sourceOrigin,
      "/api/checkout-review",
      "POST",
      { ...data, items: bulk },
      200,
      false,
    );
    assert.deepEqual(bulkReview.source, bulkReview.home);
    assert.equal(bulkReview.source.items.length, 100);
    assert.equal(bulkReview.source.totalCents, 14900);
    assert.equal(t.sourceReads - reads, 1);
    rows.push({
      name: "סל של 100 שורות",
      source: bulkReview.source,
      home: bulkReview.home,
      elapsedMs: Math.round(performance.now() - started),
      sourceBulkRequests: t.sourceReads - reads,
    });
    for (const host of [sourceOrigin, homeOrigin]) {
      const db = environments[host].DB;
      assert.equal(
        (await db.prepare("SELECT COUNT(*) n FROM orders").first()).n,
        0,
      );
      assert.equal(
        (
          await db
            .prepare("SELECT COUNT(*) n FROM channel_reservations")
            .first()
        ).n,
        0,
      );
      assert.equal(
        (await db.prepare("SELECT COUNT(*) n FROM payment_sessions").first()).n,
        0,
      );
    }
    assert.equal(t.creations, 0);
    if (process.env.QA_RESULTS_PATH)
      await import("node:fs/promises").then((fs) =>
        fs.writeFile(
          process.env.QA_RESULTS_PATH,
          JSON.stringify(
            {
              environment:
                "isolated Miniflare D1, real API handlers, signed inter-store requests, no browser, no live provider",
              scenarios: rows,
            },
            null,
            2,
          ),
        ),
      );
  } finally {
    await t.close();
  }
});

test("authorized temporary QA fixtures are signed, bounded, idempotent and removable", async (context) => {
 context.mock.method(Date, 'now', () => Date.parse('2026-10-09T12:00:00Z'));
 const t=await setup(); const path='/api/channel/qa-fixtures';
 try {
  assert.equal((await t.signed(sourceOrigin,path,{action:'seed'})).status,404);
  for(const e of Object.values(t.environments))e.QA_FIXTURE_SECRET='temporary-isolated-qa';
  assert.equal((await t.signed(sourceOrigin,path,{action:'seed'})).status,401);
  for(const host of [sourceOrigin,homeOrigin])for(let n=0;n<2;n++)assert.equal((await t.signed(host,path,{action:'seed'},'temporary-isolated-qa')).status,200);
  assert.equal((await t.signed(homeOrigin,path,{action:'sale'},'temporary-isolated-qa')).status,400);
  assert.equal((await t.signed(sourceOrigin,path,{action:'sale'},'temporary-isolated-qa')).status,200);
  const data={items:[{id:'qa-shem-knit-20261009',variantId:'qa-shem-knit-20261009-M',size:'M',qty:1},{id:'qa-shem-shirt-20261009',variantId:'qa-shem-shirt-20261009-L',size:'L',qty:2}],shipping:'standard',code:'QAOCT09',termsAccepted:true,customer:{name:'QA',email:'qa@example.invalid',phone:'0500000000',city:'QA',street:'QA'}};
  const review=await t.call(sourceOrigin,'/api/checkout-review','POST',data,200,false);
  assert.deepEqual(review.source,review.home);assert.equal(review.source.totalCents,44392);
  const liveFetch=globalThis.fetch;globalThis.fetch=async()=>new Response(null,{status:302,headers:{location:'https://unexpected.test'}});
  try {await t.call(sourceOrigin,'/api/checkout-review','POST',data,502,false);}finally{globalThis.fetch=liveFetch;}
  delete t.environments[sourceOrigin].QA_FIXTURE_SECRET;
  assert.equal((await t.signed(sourceOrigin,path,{action:'seed'},'temporary-isolated-qa')).status,404);
  t.environments[sourceOrigin].QA_FIXTURE_SECRET='temporary-isolated-qa';
  Date.now.mock.mockImplementation(() => Date.parse('2026-10-11T00:00:00Z'));
  assert.equal((await t.signed(sourceOrigin,path,{action:'seed'},'temporary-isolated-qa')).status,404);
 } finally {await t.close();}
});

 test("custom storefront origin can link products and embed payment without accepting unconfigured origins", async () => {
  const t = await setup();
  try {
   const env = t.environments[homeOrigin];
   env.SHEM_PUBLIC_ORIGIN = "https://shem-shop.test";
   assert.deepEqual(peerOrigins(env), [sourceOrigin, "https://shem-shop.test"]);
   const source = await t.call(sourceOrigin, "/api/admin/products", "POST", {he:"Source",sku:"CUSTOM-1",price:125,stock:2,sizes:["M"],sizeStock:{M:2},active:true,image:"knit"},201);
   const home = await t.call(homeOrigin, "/api/admin/products", "POST", {he:"Home",sku:"HOME-CUSTOM",price:50,stock:0,sizes:["M"],sizeStock:{M:0},active:true,image:"shirt"},201);
   await t.call(homeOrigin, `/api/admin/channel/products/${home.id}/link`, "POST", {sourceUrl:"https://shem-shop.test/api/channel/source/"+source.id,variantMap:{}});
   await t.call(homeOrigin, `/api/admin/channel/products/${home.id}/link`, "POST", {sourceUrl:"https://unconfigured.test/api/channel/source/"+source.id,variantMap:{}},400);
   await env.DB.prepare("INSERT INTO payment_sessions(id,data,updated_at) VALUES(?,?,?)").bind("isolated-custom",JSON.stringify({id:"isolated-custom",token:"private-token",amountCents:12500,checkoutUrl:"https://checkout.test/session"}),new Date().toISOString()).run();
   const response = await api(new Request(homeOrigin+"/payment/isolated-custom?token=private-token"),env);
   assert.equal(response.status,200);
   assert.ok(response.headers.get("content-security-policy").includes("frame-ancestors 'self' "+sourceOrigin+" https://shem-shop.test"));
  } finally {await t.close();}
 });
