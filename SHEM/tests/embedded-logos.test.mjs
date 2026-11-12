import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

// Design v3 (12.11.2026): brand logos are flat SVG files loaded as asset URLs (no drop-shadow, no baked halo).
// Only the monogram stays an inlined WebP data URI. The old WebP logos stay in the repo unused (no deletion).
test("production app inlines the monogram and ships every brand logo as a separate SVG file", () => {
  const bundled = readdirSync("dist/client/assets")
    .filter((f) => f.endsWith(".js"))
    .map((f) => readFileSync("dist/client/assets/" + f, "utf8"))
    .join("\n");
  const embedded = readdirSync("src/assets/embedded-logos");
  const monogram = readFileSync("src/assets/embedded-logos/logo-monogram.webp");
  assert.equal(monogram.subarray(0, 4).toString(), "RIFF");
  assert.ok(
    bundled.includes("data:image/webp;base64," + monogram.toString("base64")),
    "logo-monogram.webp must stay inlined",
  );
  const svgEmbedded = embedded.filter((f) => f.endsWith(".svg"));
  assert.equal(svgEmbedded.length, 12);
  const svgLater = readdirSync("src/assets/brand-logos").filter((f) =>
    f.endsWith(".svg"),
  );
  assert.equal(svgLater.length, 34);
  const emitted = readdirSync("dist/client/assets").filter((f) =>
    f.endsWith(".svg"),
  );
  for (const file of [...svgEmbedded, ...svgLater]) {
    const text = readFileSync(
      (svgEmbedded.includes(file)
        ? "src/assets/embedded-logos/"
        : "src/assets/brand-logos/") + file,
      "utf8",
    );
    assert.ok(text.trimStart().startsWith("<svg"), file + " must be an SVG");
    assert.equal(/<filter|drop-shadow/i.test(text), false, file + " has no filter or shadow");
    assert.ok(
      emitted.some((f) => f.startsWith(file.replace(".svg", "-"))),
      file + " must be emitted as a separate file",
    );
  }
  assert.equal(bundled.includes("/assets/designers/"), false);
  assert.equal(bundled.includes("/assets/logo-monogram.png"), false);
});
