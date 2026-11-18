#!/usr/bin/env node
// One-time conversion of EXISTING product images to WebP variants (products/<uuid>-<w>.webp) + imageSet in the catalogue.
//
//   node scripts/convert-product-images.mjs --catalogue <file.json|https://.../api/catalogue> --originals <dir|https://base> [--limit N] [--log file]
//        -> DRY RUN (default): reads only, writes nothing, prints what would be created and the estimated size.
//   SHEM_ALLOW_APPLY=yes node scripts/convert-product-images.mjs --apply --local-state <dir> [--limit N] [--log file] [--rollback file]
//        -> writes ONLY into a LOCAL Miniflare state dir (<dir>/r2, <dir>/d1). This script has no code path that writes to a
//           live R2 / D1 / site: remote inputs are fetched with GET only, and --apply refuses anything that is not a local directory.
//
// Safety: only new files are written (an existing key is verified and reused, never overwritten); no original is touched or deleted;
// imageSet is the list of widths, e.g. [480, 800, 1086] (the client derives the URLs from the original's uuid); it is saved only after every file of that product was created and read back (size > 0, RIFF/WEBP header); safe to re-run.
import fs from "node:fs";
import path from "node:path";
import sharp from "sharp";
import { variantWidths, WEBP_QUALITY } from "../src/webp-variants.js";

const args = process.argv.slice(2);
const opt = (name) => {
  const i = args.indexOf("--" + name);
  return i >= 0 ? args[i + 1] : undefined;
};
const APPLY = args.includes("--apply");
const LIMIT = opt("limit") ? Number(opt("limit")) : Infinity;
const LOG = opt("log") || "convert-product-images.log.jsonl";
const ROLLBACK = opt("rollback") || "convert-product-images.rollback.json";
const log = (o) => {
  const line = JSON.stringify({ t: new Date().toISOString(), ...o });
  fs.appendFileSync(LOG, line + "\n");
  console.log(line);
};
const ORIGINAL = /^\/media\/products\/([a-f0-9-]{36})\.(png|jpg|webp)$/;

async function readText(src) {
  if (/^https:\/\//.test(src)) {
    const r = await fetch(src, { method: "GET" }); // read only
    if (!r.ok) throw new Error(`GET ${src} -> ${r.status}`);
    return r.text();
  }
  return fs.readFileSync(src, "utf8");
}
async function readOriginal(base, url) {
  if (/^https:\/\//.test(base)) {
    const r = await fetch(base.replace(/\/$/, "") + url, { method: "GET" });
    if (!r.ok) throw new Error(`GET ${url} -> ${r.status}`);
    return Buffer.from(await r.arrayBuffer());
  }
  return fs.readFileSync(path.join(base, path.basename(url)));
}
async function variantsOf(buf) {
  const meta = await sharp(buf).metadata();
  const out = [];
  for (const w of variantWidths(meta.width))
    out.push({
      width: w,
      data: await sharp(buf)
        .resize({ width: w, withoutEnlargement: true })
        .webp({ quality: WEBP_QUALITY })
        .toBuffer(),
    });
  return { meta, out };
}
// Every original referenced by a product that has no imageSet yet.
function workOf(product) {
  const items = [];
  if (ORIGINAL.test(product.imageUrl || "") && !Array.isArray(product.imageSet))
    items.push({ field: "imageSet", url: product.imageUrl });
  (product.images || []).forEach((im, i) => {
    if (ORIGINAL.test(im.url || "") && !Array.isArray(im.imageSet))
      items.push({ field: `images[${i}].imageSet`, url: im.url, index: i });
  });
  // One entry per unique original (the primary image is usually also images[0]).
  const byUrl = new Map();
  for (const it of items) {
    if (!byUrl.has(it.url)) byUrl.set(it.url, { url: it.url, fields: [] });
    byUrl.get(it.url).fields.push({ field: it.field, index: it.index });
  }
  return [...byUrl.values()];
}

async function main() {
  if (APPLY) {
    if (process.env.SHEM_ALLOW_APPLY !== "yes")
      throw new Error("--apply requires SHEM_ALLOW_APPLY=yes. Stopped.");
    const state = opt("local-state");
    if (!state || /^[a-z]+:\/\//i.test(state) || !fs.existsSync(state + "/d1"))
      throw new Error(
        "--apply needs --local-state <local Miniflare dir with d1/ and r2/>. Stopped.",
      );
    return apply(path.resolve(state), opt("originals"));
  }
  const cat = JSON.parse(
    await readText(
      opt("catalogue") ||
        (() => {
          throw new Error("--catalogue required");
        })(),
    ),
  );
  const originals = opt("originals");
  if (!originals) throw new Error("--originals required");
  let files = 0,
    bytesBefore = 0,
    bytesAfter = 0,
    products = 0;
  const seen = new Set();
  log({ mode: "dry-run", note: "no writes" });
  for (const p of cat.products) {
    if (products >= LIMIT) break;
    const work = workOf(p).filter((w) => !seen.has(w.url));
    if (!work.length) continue;
    products++;
    for (const w of work) {
      seen.add(w.url);
      const buf = await readOriginal(originals, w.url);
      const { meta, out } = await variantsOf(buf);
      files += out.length;
      bytesBefore += buf.length;
      bytesAfter += out.reduce((n, v) => n + v.data.length, 0);
      log({
        product: p.id,
        fields: w.fields.map((f) => f.field),
        original: w.url,
        would_set_imageSet: out.map((v) => v.width),
        px: `${meta.width}x${meta.height}`,
        originalBytes: buf.length,
        would_create: out.map(
          (v) =>
            `products/${w.url.match(ORIGINAL)[1]}-${v.width}.webp (${v.data.length} B)`,
        ),
      });
    }
  }
  log({
    summary: {
      products,
      originals: seen.size,
      filesToCreate: files,
      originalBytes: bytesBefore,
      variantBytes: bytesAfter,
      note: "originals are never modified or deleted",
    },
  });
}

async function apply(state, originals) {
  const { Miniflare } = await import("miniflare");
  const mf = new Miniflare({
    modules: true,
    script: "export default{fetch(){return new Response('')}}",
    d1Databases: ["DB"],
    r2Buckets: ["BUCKET"],
    d1Persist: state + "/d1",
    r2Persist: state + "/r2",
  });
  const db = await mf.getD1Database("DB"),
    r2 = await mf.getR2Bucket("BUCKET");
  const rollback = fs.existsSync(ROLLBACK)
    ? JSON.parse(fs.readFileSync(ROLLBACK, "utf8"))
    : [];
  const rows = (
    await db.prepare("SELECT id,data,updated_at FROM products").all()
  ).results;
  let done = 0;
  log({ mode: "apply", target: "LOCAL " + state });
  for (const row of rows) {
    if (done >= LIMIT) break;
    const p = JSON.parse(row.data),
      work = workOf(p);
    if (!work.length) continue;
    const created = [],
      added = [],
      sets = {};
    try {
      for (const w of work) {
        const uuid = w.url.match(ORIGINAL)[1];
        const orig = await r2.get("products/" + path.basename(w.url));
        const buf = orig
          ? Buffer.from(await orig.arrayBuffer())
          : originals
            ? await readOriginal(originals, w.url)
            : null;
        if (!buf) throw new Error("original not found: " + w.url);
        const { out } = await variantsOf(buf);
        const set = [];
        for (const v of out) {
          const key = `products/${uuid}-${v.width}.webp`;
          if (!(await r2.head(key))) {
            await r2.put(key, v.data, {
              httpMetadata: { contentType: "image/webp" },
            });
            created.push(key);
          }
          const back = await r2.get(key); // read back + verify
          const bb = back
            ? Buffer.from(await back.arrayBuffer())
            : Buffer.alloc(0);
          if (!(
            bb.length > 0 &&
            bb.subarray(0, 4).toString() === "RIFF" &&
            bb.subarray(8, 12).toString() === "WEBP"
          ))
            throw new Error("verification failed: " + key);
          set.push(v.width);
        }
        for (const f of w.fields) sets[f.field] = { set, index: f.index };
      }
      for (const [field, { set, index }] of Object.entries(sets)) {
        if (field === "imageSet") p.imageSet = set;
        else p.images[index].imageSet = set;
        added.push(field);
      }
      // updated_at is left unchanged on purpose: the catalogue order depends on it.
      await db
        .prepare("UPDATE products SET data=? WHERE id=?")
        .bind(JSON.stringify(p), row.id)
        .run();
      rollback.push({
        productId: row.id,
        addedFields: added,
        createdKeys: created,
      });
      fs.writeFileSync(ROLLBACK, JSON.stringify(rollback, null, 1));
      log({ product: row.id, ok: true, added, created: created.length });
      done++;
    } catch (e) {
      // Files already created for this product stay listed in the rollback file (they are new, unreferenced WebP files).
      if (created.length) {
        rollback.push({
          productId: row.id,
          addedFields: [],
          createdKeys: created,
          failed: true,
        });
        fs.writeFileSync(ROLLBACK, JSON.stringify(rollback, null, 1));
      }
      log({
        product: row.id,
        ok: false,
        error: String(e.message),
        note: "imageSet NOT saved for this product",
      });
    }
  }
  log({ summary: { productsUpdated: done } });
  await mf.dispose();
}
main().catch((e) => {
  log({ fatal: String(e.message) });
  process.exit(1);
});
