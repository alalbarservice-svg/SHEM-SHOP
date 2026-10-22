import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  designers,
  designerFor,
  designerProducts,
  productType,
} from "../src/designers.js";

test("brand collections isolate brands, normalize supported labels and exclude unpublished products", () => {
  const items = [
    { id: 1, brand: " DIOR " },
    { id: 2, brand: "Givenchy" },
    { id: 3, brand: "Dior", active: false },
    { id: 4, brand: "Dior", archived: true },
    { id: 5, brand: "Essential" },
    { id: 6, brand: "Unknown" },
  ];
  assert.deepEqual(
    designerProducts(items, designerFor("Dior")).map((p) => p.id),
    [1],
  );
  assert.deepEqual(
    designerProducts(items, designerFor("Essentials")).map((p) => p.id),
    [5],
  );
  assert.deepEqual(designerProducts(items, designerFor("Amiri")), []);
  assert.equal(designerFor("Unknown"), undefined);
});
test("manager product type takes precedence and unknown items stay in a neutral category", () => {
  assert.equal(
    productType({ productType: "jackets", image: "knit" }),
    "jackets",
  );
  assert.equal(productType({ cat: "shoes", image: "other" }), "shoes");
  assert.equal(productType({ image: "shirt" }), "shirts");
  assert.equal(
    productType({
      productType: "invalid",
      image: "/media/custom.png",
      he: "חולצה",
    }),
    "clothing",
  );
});
const originalSlugs = [
  "amiri",
  "dior",
  "essentials",
  "givenchy",
  "loro-piana",
  "ralph-lauren",
];
test("the six original brand pages keep original high-resolution black and white PNG assets", () => {
  for (const slug of originalSlugs) {
    const d = designers.find((x) => x.slug === slug);
    assert.ok(d, slug);
    for (const tone of ["black", "white"]) {
      const bytes = readFileSync(
        new URL(
          `../public/assets/designers/${d.slug}-${tone}.png`,
          import.meta.url,
        ),
      );
      assert.equal(bytes.subarray(1, 4).toString(), "PNG");
      assert.equal(bytes.readUInt32BE(16), d.width || 2172);
      assert.equal(bytes.readUInt32BE(20), d.height || 724);
    }
  }
});
test("every brand has a unique slug and an embedded black and white logo", () => {
  const src = readFileSync(
    new URL("../src/brand-logo-assets.js", import.meta.url),
    "utf8",
  );
  assert.equal(designers.length, 23);
  assert.equal(new Set(designers.map((d) => d.slug)).size, designers.length);
  for (const d of designers)
    for (const tone of ["black", "white"])
      assert.ok(src.includes(`"${d.slug}-${tone}":`), `${d.slug}-${tone}`);
});
