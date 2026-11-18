import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { Miniflare } from "miniflare";
import { variantWidths, TARGET_WIDTHS } from "../src/webp-variants.js";
import {
  variantUrl,
  imgSrcSet,
  imageSetFor,
  imgVariants,
} from "../src/experience.js";

const origin = "https://shem.test";
const UUID = "0a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d";
const OTHER = "ffffffff-4e5f-6a7b-8c9d-0e1f2a3b4c5d";
// Minimal bytes that carry a RIFF....WEBP header (the worker validates the container signature, it does not decode).
const webp = (n = 400, fill = 7) => {
  const b = new Uint8Array(n).fill(fill);
  b.set([82, 73, 70, 70], 0);
  b.set([87, 69, 66, 80], 8);
  return b;
};
const png = () => {
  const b = new Uint8Array(300).fill(1);
  b.set([137, 80, 78, 71], 0);
  return b;
};

test("variant widths never upscale and are deduplicated", () => {
  assert.deepEqual(TARGET_WIDTHS, [480, 800, 1086]);
  assert.deepEqual(variantWidths(1086), [480, 800, 1086]);
  assert.deepEqual(variantWidths(2400), [480, 800, 1086]);
  assert.deepEqual(variantWidths(700), [480, 700]);
  assert.deepEqual(variantWidths(300), [300]);
});

test("webp variant upload, immutable cache for variants only, imageSet sanitising", async () => {
  const mf = new Miniflare({
    modules: true,
    scriptPath: "dist/server/index.js",
    compatibilityDate: "2026-08-06",
    d1Databases: ["DB"],
    r2Buckets: ["BUCKET"],
    bindings: { OWNER_PRIVATE_BOOTSTRAP: "true" },
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
    const bucket = await mf.getR2Bucket("BUCKET");
    await bucket.put(`products/${UUID}.png`, png(), {
      httpMetadata: { contentType: "image/png" },
    });
    const send = (path, method, body, type, uid = "owner") =>
      mf.dispatchFetch(origin + path, {
        method,
        headers: {
          origin,
          ...(type ? { "content-type": type } : {}),
          ...(uid ? { "oai-authenticated-user-id": uid } : {}),
        },
        body,
      });
    const variant = (src, w, body, type = "image/webp", uid) =>
      send(
        `/api/admin/media/variant?src=${encodeURIComponent(src)}&w=${w}`,
        "POST",
        body,
        type,
        uid,
      );
    const src = `products/${UUID}.png`;
    assert.equal(
      (await send("/api/admin/session", "GET", undefined, undefined)).status,
      200,
    ); // binds the first user as owner

    assert.equal(
      (await variant(src, 480, webp(), "image/webp", null)).status,
      401,
    );
    assert.equal(
      (await variant(src, 480, webp(), "image/webp", "other")).status,
      403,
    );
    assert.equal(
      (await variant("products/x.png", 480, webp())).status,
      400,
      "bad source name",
    );
    assert.equal(
      (await variant("../" + src, 480, webp())).status,
      400,
      "path traversal",
    );
    assert.equal(
      (await variant(src, 50, webp())).status,
      400,
      "width too small",
    );
    assert.equal(
      (await variant(src, 2000, webp())).status,
      400,
      "width above the 1086 maximum",
    );
    assert.equal(
      (await variant(src, 480, webp(), "image/png")).status,
      400,
      "must be image/webp",
    );
    assert.equal(
      (await variant(src, 480, png())).status,
      400,
      "content is not WebP",
    );
    assert.equal(
      (await variant(src, 480, webp(2 * 1024 * 1024))).status,
      413,
      "too large",
    );
    assert.equal(
      (await variant(`products/${OTHER}.png`, 480, webp())).status,
      404,
      "missing original",
    );
    assert.equal(
      await bucket.head(`products/${UUID}-480.webp`),
      null,
      "nothing stored after rejections",
    );

    const ok = await variant(src, 480, webp(400, 7));
    assert.equal(ok.status, 201);
    assert.deepEqual(await ok.json(), {
      url: `/media/products/${UUID}-480.webp`,
      width: 480,
    });
    // New files only: a second upload of the same name does not overwrite.
    assert.equal((await variant(src, 480, webp(500, 9))).status, 201);
    assert.equal(
      (await (await bucket.get(`products/${UUID}-480.webp`)).arrayBuffer())
        .byteLength,
      400,
    );
    assert.equal((await variant(src, 800, webp())).status, 201);
    assert.equal((await variant(src, 1086, webp())).status, 201);
    // The original is untouched.
    assert.equal((await bucket.get(src)).size, 300);

    const v = await mf.dispatchFetch(
      `${origin}/media/products/${UUID}-480.webp`,
    );
    assert.equal(v.status, 200);
    assert.equal(v.headers.get("content-type"), "image/webp");
    assert.equal(
      v.headers.get("cache-control"),
      "public,max-age=31536000,immutable",
    );
    const o = await mf.dispatchFetch(`${origin}/media/products/${UUID}.png`);
    assert.equal(
      o.headers.get("cache-control"),
      "public,max-age=86400",
      "originals keep the old cache policy",
    );

    // Product save: imageSet is only the list of widths (also per gallery image); anything else is dropped; no imageSet = unchanged.
    const set = [480, 800, 1086];
    const base = {
      he: "מוצר WebP",
      brand: "SHEM",
      price: 100,
      stock: 1,
      sizes: ["M"],
      active: true,
      imageUrl: `/media/products/${UUID}.png`,
    };
    const post = (data) =>
      send(
        "/api/admin/products",
        "POST",
        JSON.stringify(data),
        "application/json",
      );
    const good = await (
      await post({
        ...base,
        imageSet: set,
        images: [{ url: base.imageUrl, alt: "a", imageSet: set }],
      })
    ).json();
    assert.deepEqual(good.imageSet, set);
    assert.deepEqual(good.images[0].imageSet, set);
    const unsorted = await (
      await post({ ...base, imageSet: [1086, 480, 480, 800] })
    ).json();
    assert.deepEqual(
      unsorted.imageSet,
      [480, 800, 1086],
      "sorted and de-duplicated",
    );
    for (const bad of [
      { 480: `/media/products/${UUID}-480.webp` }, // old URL-map format
      [50, 480], // width below 100
      [480, 2000], // above the 1086 maximum
      [480.5],
      ["480"],
      [480, 600, 700, 800, 900],
      [],
      "480",
    ]) {
      const r = await (await post({ ...base, imageSet: bad })).json();
      assert.equal("imageSet" in r, false, "dropped: " + JSON.stringify(bad));
    }
    const notUpload = await (
      await post({
        ...base,
        imageUrl: "/assets/knit.png",
        imageSet: set,
        images: [{ url: "/assets/knit.png", alt: "a", imageSet: set }],
      })
    ).json();
    assert.equal(
      "imageSet" in notUpload,
      false,
      "an original that is not a product upload has no set",
    );
    assert.equal("imageSet" in notUpload.images[0], false);
    const plain = await (await post({ ...base })).json();
    assert.equal(
      "imageSet" in plain,
      false,
      "no imageSet -> product stays exactly as before",
    );
    const cat = await (
      await mf.dispatchFetch(origin + "/api/catalogue")
    ).json();
    assert.deepEqual(
      cat.products.find((p) => p.id === good.id).imageSet,
      set,
      "public catalogue exposes imageSet",
    );
  } finally {
    await mf.dispose();
  }
});

test("client derives variant URLs from the original's uuid", () => {
  const url = `/media/products/${UUID}.png`;
  assert.equal(variantUrl(url, 800), `/media/products/${UUID}-800.webp`);
  assert.equal(
    imgSrcSet(url, [1086, 480, 800]),
    `/media/products/${UUID}-480.webp 480w, /media/products/${UUID}-800.webp 800w, /media/products/${UUID}-1086.webp 1086w`,
  );
  assert.equal(imgSrcSet(url, undefined), undefined);
  assert.equal(imgSrcSet(url, []), undefined);
  assert.equal(
    imgSrcSet("/assets/knit.png", [480]),
    undefined,
    "only product uploads",
  );
  const p = {
    imageUrl: url,
    imageSet: [480],
    images: [
      { url, imageSet: [480, 800] },
      { url: "/media/products/" + OTHER + ".jpg" },
    ],
  };
  assert.deepEqual(
    imageSetFor(p, url),
    [480],
    "product level first for the primary image",
  );
  assert.deepEqual(imageSetFor({ images: p.images }, url), [480, 800]);
  assert.equal(imageSetFor(p, "/media/products/" + OTHER + ".jpg"), null);
  assert.deepEqual(
    imgVariants({ imageUrl: url }, url, "100vw"),
    {},
    "no imageSet -> no attributes",
  );
  assert.equal(imgVariants(p, url, "100vw").sizes, "100vw");
  // an old URL-map value is ignored by the client
  assert.deepEqual(
    imgVariants({ imageUrl: url, imageSet: { 480: "x" } }, url, "1px"),
    {},
  );
});
