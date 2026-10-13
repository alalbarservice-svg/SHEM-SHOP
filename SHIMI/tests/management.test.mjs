import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { Miniflare } from "miniflare";
import { PDFDocument } from "pdf-lib";
import { sign, processMail } from "../worker/management.js";
const origin = "https://shem.test",
  secret = "isolated-test-secret";
test("CRUD, archive, size/color stock, manual paid order, immutable invoice, signed callback and loyalty are real and isolated", async () => {
  const mf = new Miniflare({
    modules: true,
    scriptPath: "dist/server/index.js",
    compatibilityDate: "2026-08-06",
    d1Databases: ["DB"],
    r2Buckets: ["BUCKET"],
    bindings: {
      ADMIN_EMAILS: "your.shem88@gmail.com",
      OWNER_PRIVATE_BOOTSTRAP: "false",
      HOME_PAYMENT_SECRET: secret,
    },
  });
  try {
    const db = await mf.getD1Database("DB");
    for (const f of readdirSync("drizzle")
      .filter((f) => f.endsWith(".sql"))
      .sort())
      for (const sql of readFileSync("drizzle/" + f, "utf8")
        .replaceAll("--> statement-breakpoint", "")
        .split(";")
        .map((s) => s.trim())
        .filter(Boolean))
        await db.prepare(sql).run();
    const call = async (
      path,
      method = "GET",
      data,
      expected = 200,
      admin = true,
    ) => {
      const r = await mf.dispatchFetch(origin + "/api/" + path, {
        method,
        headers: {
          origin,
          "content-type": "application/json",
          ...(admin
            ? {
                "oai-authenticated-user-id": "owner",
                "oai-authenticated-user-email": "your.shem88@gmail.com",
              }
            : {}),
        },
        body: data === undefined ? undefined : JSON.stringify(data),
      });
      assert.equal(r.status, expected, await r.clone().text());
      return r;
    };
    const get = async (path) => (await call(path)).json();
    await call("admin/manage/orders", "GET", undefined, 401, false);
    const product = await (
      await call(
        "admin/products",
        "POST",
        {
          he: "Test knit",
          sku: "123405",
          homeSku: "123405",
          price: 199.9,
          stock: 6,
          sizes: ["S", "M"],
          sizeStock: { S: 3, M: 3 },
          variants: [
            {
              id: "black-s",
              size: "S",
              color: "black",
              sku: "123405-S-B",
              homeSku: "123405-S-B",
              stock: 3,
            },
            {
              id: "ivory-m",
              size: "M",
              color: "ivory",
              sku: "123405-M-I",
              homeSku: "123405-M-I",
              stock: 3,
            },
          ],
          color: "black",
          active: true,
          image: "knit",
        },
        201,
      )
    ).json();
    await call(
      "admin/products/" + product.id,
      "PUT",
      {
        ...product,
        variants: [
          { id: "bad", size: "XX", color: "black", sku: "BAD", stock: 6 },
        ],
      },
      400,
    );
    const col = await (
      await call(
        "admin/manage/collections",
        "POST",
        { title: "Summer", productIds: [product.id], active: true },
        201,
      )
    ).json();
    await call("admin/manage/collections/" + col.id + "/archive", "POST", {
      revision: col.revision,
    });
    assert.equal((await get("collections")).some(c=>c.id===col.id), false);
    await call("admin/manage/collections/" + col.id + "/restore", "POST", {});
    assert.equal((await get("collections")).some(c=>c.id===col.id), true);
    await call(
      "admin/manage/collections/" + col.id,
      "PUT",
      { ...col, title: "Stale", revision: "outdated" },
      409,
    );
    const customer = {
      name: "QA customer",
      email: "qa@example.test",
      phone: "0500000000",
      city: "Test city",
      street: "Test 1",
    };
    const c = await (
      await call("admin/manage/customers", "POST", customer, 201)
    ).json();
    await call("admin/manage/customers", "POST", customer, 409);
    const card = await (
      await call(
        "admin/manage/cards",
        "POST",
        { ...customer, tier: "standard", status: "active" },
        201,
      )
    ).json();
    assert.ok(card.cardNumber);
    await call(
      "admin/manage/cards",
      "POST",
      { ...customer, tier: "standard" },
      409,
    );
    await call("admin/manage/settings/loyalty", "PUT", {
      enabled: true,
      earnPercent: 3,
      maxRedeemPercent: 10,
      vipSpend: 300,
      vipOrders: 2,
      pendingDays: 14,
      expiryDays: 365,
      grossMarginPercent: 20,
    });
    await call(
      "admin/manage/loyalty-adjust",
      "POST",
      { memberId: card.id, amount: -5, reason: "invalid" },
      400,
    );
    await call(
      "admin/manage/loyalty-adjust",
      "POST",
      { memberId: card.id, amount: 5, reason: "manual QA" },
      201,
    );
    const updated = await (
      await call("admin/manage/inventory", "POST", {
        productId: product.id,
        variantId: "black-s",
        size: "S",
        delta: 2,
        reason: "Received stock",
        revision: product.revision,
      })
    ).json();
    assert.equal(updated.product.stock, 8);
    assert.equal(updated.product.variants[0].stock, 5);
    await call(
      "admin/manage/inventory",
      "POST",
      {
        productId: product.id,
        variantId: "black-s",
        size: "S",
        delta: -10,
        reason: "too much",
      },
      400,
    );
    await call(
      "admin/manage/inventory",
      "POST",
      {
        productId: product.id,
        variantId: "black-s",
        size: "S",
        delta: 1,
        reason: "stale",
        revision: product.revision,
      },
      409,
    );
    const req = await (
      await call(
        "admin/manage/requests",
        "POST",
        {
          ...customer,
          orderNumber: "MANUAL",
          type: "return",
          status: "new",
          message: "Return please",
        },
        201,
      )
    ).json();
    await call("admin/manage/requests/" + req.id, "PUT", {
      ...req,
      status: "reviewing",
      notes: "QA review",
    });
    const activity = await (
      await call(
        "admin/manage/activities",
        "POST",
        { title: "Call customer", reason: "Service", status: "open" },
        201,
      )
    ).json();
    await call(
      "admin/manage/activities/" + activity.id + "/archive",
      "POST",
      {},
    );
    await call("admin/manage/settings/invoice", "PUT", {
      displayName: "SHEM",
      legalName: "Example business",
      registrationNumber: "TEST-ONLY",
      address: "Test address",
      phone: "0500000000",
      email: "support@example.test",
      vatMode: "included",
      vatRate: 18,
      numberPrefix: "TEST-",
      documentTitle: "חשבונית עסקה",
      documentFooter: "Testing",
    });
    const invoiceSettings = await get("admin/manage/settings/invoice");
    assert.equal(invoiceSettings.vatRate, 18);
    const payload = {
      customer,
      items: [
        { productId: product.id, variantId: "black-s", size: "S", qty: 2 },
        { productId: product.id, variantId: "ivory-m", size: "M", qty: 1 },
      ],
      shippingCost: 49,
      discount: 0,
      status: "draft",
    };
    const order = await (
      await call("admin/manage/orders", "POST", { ...payload, total: 1 }, 201)
    ).json();
    assert.equal(order.totalCents, 64870);
    assert.equal(order.paymentStatus, "unpaid");
    await call(
      "admin/manage/orders/" + order.id + "/paid",
      "POST",
      { reference: "" },
      400,
    );
    const paid = await (
      await call("admin/manage/orders/" + order.id + "/paid", "POST", {
        reference: "EXTERNAL-TEST-1",
        paymentDetails: {
          method: "card",
          last4: "1234",
          cardholderName: "QA Holder",
          brand: "Visa",
          installments: 1,
          authorizationCode: "TEST-APPROVAL",
          pan: "ignored",
          cvv: "ignored",
        },
      })
    ).json();
    assert.equal(paid.paymentStatus, "paid");
    assert.equal(paid.paymentDetails.last4, "1234");
    assert.equal(paid.paymentDetails.cardholderName, "QA Holder");
    assert.equal(paid.paymentDetails.pan, undefined);
    assert.equal(paid.paymentDetails.cvv, undefined);
    assert.equal(paid.vatItemsCents, Math.round((59970 * 18) / 118));
    assert.equal((await get("admin/products/" + product.id)).stock, 5);
    await call("admin/manage/orders/" + order.id + "/paid", "POST", {
      reference: "EXTERNAL-TEST-1",
    });
    assert.equal((await get("admin/products/" + product.id)).stock, 5);
    const docs = await get("admin/manage/documents");
    assert.equal(docs.length, 1);
    assert.equal(docs[0].totalCents, 64870);
    assert.equal(docs[0].customer.name, customer.name);
    assert.equal(docs[0].number, "TEST-000001");
    assert.equal((await get("admin/manage/mail"))[0].status, "pending");
    const html = await (
      await call("admin/manage/documents/" + order.id + "/html")
    ).text();
    assert.ok(html.includes("QA customer"));
    assert.ok(html.includes("123405-S-B"));
    assert.ok(!html.includes("<script"));
    const pdfResponse = await call(
      "admin/manage/documents/" + order.id + "/pdf",
    );
    assert.equal(pdfResponse.headers.get("content-type"), "application/pdf");
    const pdfBytes = new Uint8Array(await pdfResponse.arrayBuffer());
    const pdf = await PDFDocument.load(pdfBytes);
    assert.equal(pdf.getPageCount(), 1);
    assert.ok(Math.abs(pdf.getPage(0).getWidth() - 595.28) < 0.01);
    assert.ok(Math.abs(pdf.getPage(0).getHeight() - 841.89) < 0.01);
    const repeatPDF = await call("admin/manage/documents/" + order.id + "/pdf");
    assert.deepEqual(new Uint8Array(await repeatPDF.arrayBuffer()), pdfBytes);
    await call(
      "admin/manage/documents/" + order.id + "/pdf",
      "GET",
      undefined,
      401,
      false,
    );
    await call("admin/manage/orders/" + order.id + "/order-pdf");
    await call(
      "admin/manage/orders/" + order.id + "/payment-details",
      "POST",
      { paymentDetails: { method: "card", last4: "1234567812345678" } },
      400,
    );
    await call("admin/manage/orders/" + order.id + "/payment-details", "POST", {
      paymentDetails: {
        method: "card",
        last4: "4321",
        cardholderName: "Updated holder",
      },
    });
    assert.equal(
      (await get("admin/manage/orders/" + order.id)).paymentDetails.last4,
      "4321",
    );
    assert.ok(
      (await get("admin/manage/mail")).some(
        (j) => j.kind === "manager-order" && j.orderId === order.id,
      ),
    );
    await call("admin/manage/orders/" + order.id + "/archive", "POST", {}, 409);
    await call(
      "admin/manage/documents/" + order.id + "/archive",
      "POST",
      {},
      409,
    );
    await call("admin/manage/orders/" + order.id, "PUT", {
      ...(await get("admin/manage/orders/" + order.id)),
      customer: { ...customer, name: "tampered" },
      total: 1,
      status: "shipped",
    });
    assert.equal(
      (await get("admin/manage/orders/" + order.id)).customer.name,
      customer.name,
    );
    assert.equal((await get("admin/manage/documents"))[0].totalCents, 64870);
    const rewards = await get("admin/manage/loyalty");
    assert.equal(rewards.filter((l) => l.type === "earned").length, 1);
    assert.equal(rewards.find((l) => l.type === "earned").deltaCents, 1799);
    const cards = await get("admin/manage/cards");
    assert.equal(cards[0].effectiveTier, "vip");
    await call(
      "checkout-home",
      "POST",
      { ...payload, termsAccepted: true },
      503,
      false,
    );
    const small = await (
      await call(
        "admin/manage/orders",
        "POST",
        {
          ...payload,
          items: [
            { productId: product.id, variantId: "black-s", size: "S", qty: 1 },
          ],
        },
        201,
      )
    ).json();
    await call(
      "admin/manage/orders/" + small.id + "/redeem",
      "POST",
      { memberId: card.id, amount: 6 },
      400,
    );
    const redeemed = await (
      await call("admin/manage/orders/" + small.id + "/redeem", "POST", {
        memberId: card.id,
        amount: 5,
      })
    ).json();
    assert.equal(redeemed.totalCents, small.totalCents - 500);
    assert.equal(
      (await get("admin/manage/loyalty")).filter((l) => l.type === "redeemed")
        .length,
      1,
    );
    await call(
      "admin/manage/orders/" + small.id + "/redeem",
      "POST",
      { memberId: card.id, amount: 1 },
      409,
    );
    const released = await (
      await call(
        "admin/manage/orders/" + small.id + "/release-credit",
        "POST",
        {},
      )
    ).json();
    assert.equal(released.totalCents, small.totalCents);
    await call(
      "admin/manage/orders/" + small.id + "/release-credit",
      "POST",
      {},
      409,
    );
    await call(
      "admin/manage/orders/" + small.id + "/issue-document",
      "POST",
      {},
      409,
    );
    const raw = JSON.stringify({
        orderId: small.id,
        status: "paid",
        currency: "ILS",
        amountCents: small.totalCents,
        transactionId: "HOME-TEST-1",
      }),
      timestamp = String(Math.floor(Date.now() / 1000)),
      signature = await sign(secret, timestamp + "." + raw);
    const callback = async (body, sig = signature, time = timestamp) =>
      mf.dispatchFetch(origin + "/api/home/payment-callback", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "x-shem-timestamp": time,
          "x-shem-signature": sig,
        },
        body,
      });
    assert.equal((await callback(raw, "a".repeat(64))).status, 401);
    assert.equal((await callback(raw, signature, "1")).status, 401);
    assert.equal((await callback(raw)).status, 200);
    assert.equal((await callback(raw)).status, 200);
    assert.equal((await get("admin/manage/documents")).length, 2);
    await call(
      "admin/manage/orders/" + small.id + "/issue-document",
      "POST",
      {},
    );
    assert.equal((await get("admin/manage/documents")).length, 2);
    assert.equal((await get("admin/products/" + product.id)).stock, 4);
    const wrong = JSON.stringify({ ...JSON.parse(raw), amountCents: 1 });
    assert.equal(
      (await callback(wrong, await sign(secret, timestamp + "." + wrong)))
        .status,
      409,
    );
    assert.ok((await get("admin/audit")).length > 10);
    await call("admin/manage/products/" + product.id + "/archive", "POST", {});
    assert.equal((await get("catalogue")).products.some(p=>p.id===product.id), false);
    await call("admin/manage/products/" + product.id + "/restore", "POST", {});
    assert.equal((await get("catalogue")).products.some(p=>p.id===product.id), true);
    await call("admin/manage/settings/content", "PUT", {
      heroTitle: "NEW",
      blocks: [
        {
          id: "one",
          title: "Content block",
          text: "Visible",
          active: true,
          position: 0,
          link: "#/shop",
          label: "Shop",
        },
      ],
    });
    assert.equal((await get("store")).blocks[0].title, "Content block");
    assert.equal((await get("store")).heroTitle, "NEW");
    await call("admin/manage/settings/operations", "PUT", {
      standardPrice: 59,
      expressPrice: 199,
      standardDays: 7,
      expressDays: 1,
      lowStockThreshold: 3,
    });
    const quote = await (
      await call(
        "quote",
        "POST",
        {
          items: [{ id: product.id, variantId: "black-s", size: "S", qty: 1 }],
          shipping: "standard",
        },
        200,
        false,
      )
    ).json();
    assert.equal(quote.shipping, 59);
    await call(
      "quote",
      "POST",
      { items: [{ id: product.id, variantId: "wrong", size: "S", qty: 1 }] },
      400,
      false,
    );
    assert.equal(quote.total, 199.9 + 59);
    assert.equal(quote.vatIncluded, Math.round((19990 * 18) / 118) / 100);
    assert.equal(quote.netSubtotal + quote.vatIncluded, 199.9);
    const captured = [],
      originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, options) => {
      if (String(url) !== "https://api.resend.com/emails")
        return originalFetch(url, options);
      captured.push(JSON.parse(options.body));
      return new Response(
        JSON.stringify({ id: "mock-mail-" + captured.length }),
        { status: 200, headers: { "content-type": "application/json" } },
      );
    };
    try {
      const result = await processMail({
        DB: db,
        BUCKET: await mf.getR2Bucket("BUCKET"),
        RESEND_API_KEY: "mock-only",
        MAIL_FROM: "qa@example.test",
      });
      assert.ok(result.sent >= 4);
      assert.ok(
        captured.some(
          (m) =>
            m.to[0] === "your.shem88@gmail.com" &&
            m.subject.includes("הזמנה ששולמה"),
        ),
      );
      const customerMail = captured.find(
        (m) =>
          m.attachments[0].filename === "SHIMI-invoice-" + order.id + ".pdf",
      );
      assert.ok(customerMail);
      assert.deepEqual(
        Buffer.from(customerMail.attachments[0].content, "base64"),
        Buffer.from(pdfBytes),
      );
      for (const email of captured) {
        const attachment = await PDFDocument.load(
          Buffer.from(email.attachments[0].content, "base64"),
        );
        for (const page of attachment.getPages())
          assert.ok(
            Math.abs(page.getWidth() - 595.28) < 0.01 &&
              Math.abs(page.getHeight() - 841.89) < 0.01,
          );
      }
    } finally {
      globalThis.fetch = originalFetch;
    }
  } finally {
    await mf.dispose();
  }
});
