import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";

test("production app embeds all brand logos and the monogram without separate logo image URLs", () => {
  const bundled = readdirSync("dist/client/assets")
    .filter((f) => f.endsWith(".js"))
    .map((f) => readFileSync("dist/client/assets/" + f, "utf8"))
    .join("\n");
  const files = readdirSync("src/assets/embedded-logos").filter((f) =>
    f.endsWith(".webp"),
  );
  assert.equal(files.length, 47);
  for (const file of files) {
    const bytes = readFileSync("src/assets/embedded-logos/" + file);
    assert.equal(bytes.subarray(0, 4).toString(), "RIFF");
    assert.ok(
      bundled.includes("data:image/webp;base64," + bytes.toString("base64")),
      file,
    );
  }
  assert.equal(bundled.includes("/assets/designers/"), false);
  assert.equal(bundled.includes("/assets/logo-monogram.png"), false);
});
