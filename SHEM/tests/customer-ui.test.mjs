import test from "node:test";
import assert from "node:assert/strict";
import { build } from "esbuild";
import { mkdirSync } from "node:fs";
import { createRequire } from "node:module";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
mkdirSync(".sites-runtime", { recursive: true });
await build({
  stdin: {
    contents:
      'export {HomeCheckout,fieldsInvalid,deliveryChoices} from "./src/components/HomeCheckout.jsx";export {SizeGuide,ProductGallery} from "./src/components/ProductExperience.jsx";export {OrderDetails} from "./src/components/CustomerArea.jsx";',
    resolveDir: process.cwd(),
  },
  outfile: ".sites-runtime/customer-ui.cjs",
  bundle: true,
  platform: "node",
  format: "cjs",
  jsx: "automatic",
  packages: "external",
});
const {
  HomeCheckout,
  fieldsInvalid,
  deliveryChoices,
  SizeGuide,
  ProductGallery,
  OrderDetails,
} = createRequire(import.meta.url)("../.sites-runtime/customer-ui.cjs");
const render = (component, props) =>
  renderToStaticMarkup(React.createElement(component, props));
test("guest checkout has an accessible short form, configured shipping choices and clear validation", () => {
  const errors = fieldsInvalid({}, false);
  assert.deepEqual(
    Object.keys(errors).sort(),
    ["name", "email", "phone", "city", "street", "terms"].sort(),
  );
  assert.deepEqual(
    fieldsInvalid(
      {
        name: "Test Buyer",
        email: "buyer@example.test",
        phone: "050-0000000",
        city: "Test",
        street: "Test 1",
      },
      true,
    ),
    {},
  );
  assert.equal(
    deliveryChoices({
      standardDays: 9,
      standardMinDays: 6,
      standardPrice: 59,
      expressDays: 2,
      expressPrice: 149,
    })[0][1],
    "שליח עד הבית - 6-9 ימי עסקים",
  );
  const html = render(HomeCheckout, {
    cart: [],
    shipping: "standard",
    code: "",
    customer: {},
    setCustomer: () => {},
    operations: { standardPrice: 49, expressPrice: 199 },
  });
  assert.match(html, /אפשר לרכוש ללא פתיחת חשבון/);
  assert.match(html, /autoComplete="shipping name"/);
  assert.match(html, /5-7 ימי עסקים/);
  assert.match(html, /יום עסקים אחד/);
  assert.match(html, /שלבי הרכישה/);
});
test("three-image gallery and product-specific size guide render the manager data without invented measurements", () => {
  const product = {
    id: "isolated",
    he: "Product",
    en: "Product",
    images: [
      { url: "/assets/knit.png", alt: "Front" },
      { url: "/assets/shirt.png", alt: "Back" },
      { url: "/assets/trousers.png", alt: "Detail" },
    ],
    sizeGuide: {
      columns: ["חזה", "אורך"],
      rows: [{ size: "M", values: ["104", "70"] }],
      instructions: "Use a tape measure",
    },
  };
  const gallery = render(ProductGallery, { product });
  assert.match(gallery, /Front/);
  assert.match(gallery, /aria-label="תמונה 3"/);
  const guide = render(SizeGuide, { product });
  assert.match(guide, /104/);
  assert.match(guide, /Use a tape measure/);
  assert.match(guide, /scope="col"/);
  const empty = render(SizeGuide, { product: {} });
  assert.match(empty, /טרם הוזנה טבלת מידות/);
  assert.equal(empty.includes("92-96"), false);
});
test("customer order displays confirmed status and prices, support exchanges, and hides invoices before payment", () => {
  const order = {
    id: "CX-ORDER",
    status: "shipped",
    paymentStatus: "paid",
    items: [
      {
        name: "Knit",
        sku: "SKU-M",
        size: "M",
        color: "black",
        qty: 2,
        priceCents: 14900,
      },
    ],
    customer: { name: "Buyer", city: "Test", street: "Test 1" },
    totalCents: 34700,
    shippingCents: 4900,
    tracking: "TRACK-1",
    trackingUrl: "https://carrier.test/track",
    invoiceReady: true,
  };
  const html = render(OrderDetails, { order });
  assert.match(html, /SKU-M/);
  assert.match(html, /TRACK-1/);
  assert.match(html, /תמיכה והחלפות/);
  assert.match(html, /\/api\/customer\/orders\/CX-ORDER\/invoice/);
  const unpaid = render(OrderDetails, {
    order: { ...order, paymentStatus: "unpaid", invoiceReady: false },
  });
  assert.equal(unpaid.includes("/invoice"), false);
  assert.match(unpaid, /לוודא את מצב העסקה לפני ניסיון נוסף/);
});
