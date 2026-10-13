import { test } from "node:test";
import assert from "node:assert/strict";
import { addLine, subtotal, orderTotal, safeCart } from "../src/commerce.js";
const product = { id: "knit", price: 199, sizes: ["S", "M"] },
  catalogue = [product];
test("same product and size merge while different sizes remain separate", () => {
  let cart = addLine([], product, "M");
  cart = addLine(cart, product, "M");
  cart = addLine(cart, product, "S");
  assert.deepEqual(cart, [
    { id: "knit", size: "M", qty: 2 },
    { id: "knit", size: "S", qty: 1 },
  ]);
  assert.equal(subtotal(cart, catalogue), 597);
});
test("delivery totals use the specified rates and empty bag has no shipping charge", () => {
  const cart = [{ id: "knit", size: "M", qty: 2 }];
  assert.equal(orderTotal(cart, catalogue, "standard"), 447);
  assert.equal(orderTotal(cart, catalogue, "express"), 597);
  assert.equal(orderTotal([], catalogue, "express"), 0);
});
test("invalid size and delivery cannot enter checkout calculation", () => {
  assert.throws(() => addLine([], product, "XL"));
  assert.throws(() => orderTotal([], catalogue, "free"));
});
test("discard stale or malformed stored lines rather than breaking the app", () => {
  assert.deepEqual(
    safeCart(
      [
        { id: "gone", size: "M", qty: 1 },
        { id: "knit", size: "S", qty: -1 },
        { id: "knit", size: "M", qty: 1 },
      ],
      catalogue,
    ),
    [{ id: "knit", size: "M", qty: 1 }],
  );
  assert.deepEqual(safeCart(null, catalogue), []);
});

test("end-of-season cart uses the discounted SKU price", async () => {
  const products = [
    {
      id: "season-knit",
      brand: "SHIMI",
      sizes: ["M"],
      price: 149,
      previousPrice: 199,
    },
  ];
  const sale = products.find((p) => p.id === "season-knit");
  const cart = addLine([], sale, "M");
  assert.equal(subtotal(cart, products), 149);
  assert.equal(orderTotal(cart, products, "standard"), 198);
  assert.ok(sale.previousPrice > sale.price);
});
