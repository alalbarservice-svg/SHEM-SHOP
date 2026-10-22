import test from "node:test";
import assert from "node:assert/strict";
import {
  hasStock,
  isListed,
  visibleDesigners,
  visibleProductTypes,
  designers,
} from "../src/designers.js";

const p = (o) => ({ id: o.id || "x", brand: "Dior", active: true, stock: 0, ...o });

test("stock rule: variants, then sizeStock, then stock", () => {
  assert.equal(hasStock(p({ variants: [{ stock: 0 }, { stock: 2 }] })), true);
  assert.equal(hasStock(p({ variants: [{ stock: 0 }], stock: 9 })), false);
  assert.equal(hasStock(p({ sizeStock: { M: 0, L: 1 } })), true);
  assert.equal(hasStock(p({ sizeStock: { M: 0, L: 0 }, stock: 5 })), false);
  assert.equal(hasStock(p({ stock: 3 })), true);
  assert.equal(hasStock(p({})), false);
  assert.equal(hasStock({ id: "n", brand: "Dior" }), false);
});

test("listed needs active, not archived and stock", () => {
  assert.equal(isListed(p({ stock: 1 })), true);
  assert.equal(isListed(p({ stock: 1, active: false })), false);
  assert.equal(isListed(p({ stock: 1, archived: true })), false);
  assert.equal(isListed(p({ stock: 0 })), false);
});

test("brand disappears when all stock is gone and returns when restocked", () => {
  const items = [
    p({ id: "a", brand: "Dior", stock: 2 }),
    p({ id: "b", brand: "Amiri", stock: 0 }),
  ];
  assert.deepEqual(visibleDesigners(items).map((d) => d.slug), ["dior"]);
  items[1].stock = 4;
  assert.deepEqual(visibleDesigners(items).map((d) => d.slug), ["dior", "amiri"]);
  items[0].stock = 0;
  assert.deepEqual(visibleDesigners(items).map((d) => d.slug), ["amiri"]);
});

test("brand aliases match; unknown brands never appear; order follows the brand list", () => {
  const items = [
    p({ brand: "ralph lauren", stock: 1 }),
    p({ brand: "Essentials Fear Of God", stock: 1 }),
    p({ brand: "Unknown Label", stock: 1 }),
  ];
  const slugs = visibleDesigners(items).map((d) => d.slug);
  assert.deepEqual(slugs, ["essentials", "ralph-lauren"]);
  assert.ok(slugs.every((s) => designers.some((d) => d.slug === s)));
});

test("product type appears only with a displayed piece in that type", () => {
  const dior = designers.find((d) => d.slug === "dior");
  const items = [
    p({ id: "s1", productType: "shoes", stock: 0 }),
    p({ id: "k1", productType: "knitwear", stock: 2 }),
    p({ id: "t1", productType: "shirts", stock: 1, archived: true }),
  ];
  assert.deepEqual(visibleProductTypes(items, dior).map(([k]) => k), ["knitwear"]);
  items[0].stock = 1;
  assert.deepEqual(visibleProductTypes(items, dior).map(([k]) => k), ["knitwear", "shoes"]);
  items[0].stock = 0;
  assert.deepEqual(visibleProductTypes(items, dior, false).map(([k]) => k), ["knitwear", "shoes"]);
});
