// WebP variants of an uploaded product image, produced in the admin browser (wasm encoder, lazy-loaded).
// Works the same in every browser: decoding and resizing use the canvas, encoding uses @jsquash/webp (Apache-2.0),
// so it does not depend on the browser's own canvas WebP encoder (missing in some Safari versions).
export const TARGET_WIDTHS = [480, 800, 1086];
export const WEBP_QUALITY = 80;

// Never upscale: widths are capped at the source width, duplicates removed.
export const variantWidths = (sourceWidth) => [
  ...new Set(TARGET_WIDTHS.map((w) => Math.min(w, sourceWidth))),
];

export async function makeVariants(file) {
  const [{ default: encode }, bitmap] = await Promise.all([
    import("@jsquash/webp/encode.js"),
    createImageBitmap(file),
  ]);
  try {
    const out = [];
    for (const width of variantWidths(bitmap.width)) {
      const height = Math.max(
        1,
        Math.round((bitmap.height * width) / bitmap.width),
      );
      const canvas = document.createElement("canvas");
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext("2d");
      ctx.imageSmoothingEnabled = true;
      ctx.imageSmoothingQuality = "high";
      ctx.drawImage(bitmap, 0, 0, width, height);
      const buffer = await encode(ctx.getImageData(0, 0, width, height), {
        quality: WEBP_QUALITY,
      });
      if (!buffer?.byteLength) throw new Error("קידוד WebP החזיר קובץ ריק");
      out.push({ width, blob: new Blob([buffer], { type: "image/webp" }) });
    }
    return out;
  } finally {
    bitmap.close?.();
  }
}

// Never throws: the original upload has already succeeded. Returns { imageSet } or { error } (Hebrew, for the admin UI).
export async function uploadVariants(file, originalUrl, api) {
  try {
    const src = String(originalUrl).replace(/^\/media\//, "");
    const imageSet = [];
    for (const { width, blob } of await makeVariants(file)) {
      const r = await api(
        `media/variant?src=${encodeURIComponent(src)}&w=${width}`,
        {
          method: "POST",
          body: blob,
          headers: { "content-type": "image/webp" },
        },
      );
      if (!r?.url) throw new Error("השרת לא אישר את הגרסה");
      imageSet.push(width);
    }
    return { imageSet };
  } catch (e) {
    return { error: e?.message || "שגיאה לא ידועה" };
  }
}
