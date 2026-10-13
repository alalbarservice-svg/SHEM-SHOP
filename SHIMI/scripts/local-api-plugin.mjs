import { Miniflare } from "miniflare";
import { readFile, readdir } from "node:fs/promises";
import path from "node:path";
export function localApi() {
  let mf;
  return {
    name: "shem-local-api",
    apply: "serve",
    async configureServer(server) {
      const root = server.config.root;
      mf = new Miniflare({
        modules: true,
        scriptPath: path.join(root, "dist/server/index.js"),
        compatibilityDate: "2026-08-06",
        bindings: {
          OWNER_PRIVATE_BOOTSTRAP: "true",
          ADMIN_CONTACT_EMAIL: "your.shem88@gmail.com",
          LOCAL_PREVIEW: "true",
        },
        d1Databases: ["DB"],
        r2Buckets: ["BUCKET"],
        d1Persist: path.join(root, ".sites-runtime/admin-d1"),
        r2Persist: path.join(root, ".sites-runtime/admin-r2"),
      });
      const db = await mf.getD1Database("DB");
      await db
        .prepare(
          "CREATE TABLE IF NOT EXISTS __local_migrations(name TEXT PRIMARY KEY)",
        )
        .run();
      for (const name of (await readdir(path.join(root, "drizzle")))
        .filter((n) => n.endsWith(".sql"))
        .sort()) {
        if (
          await db
            .prepare("SELECT name FROM __local_migrations WHERE name=?")
            .bind(name)
            .first()
        )
          continue;
        const sql = (
          await readFile(path.join(root, "drizzle", name), "utf8")
        ).replace(/--> statement-breakpoint/g, "");
        const statements = sql
          .split(";")
          .map((s) => s.trim())
          .filter(Boolean)
          .map((s) => db.prepare(s));
        await db.batch([
          ...statements,
          db
            .prepare("INSERT INTO __local_migrations(name) VALUES(?)")
            .bind(name),
        ]);
      }
      server.middlewares.use(async (req, res, next) => {
        if (!req.url?.startsWith("/api/") && !req.url?.startsWith("/media/"))
          return next();
        try {
          const url = `http://${req.headers.host}${req.url}`,
            headers = new Headers();
          for (const [k, v] of Object.entries(req.headers)) {
            if (!k.startsWith("oai-authenticated-") && v)
              headers.set(k, Array.isArray(v) ? v.join(",") : v);
          }
          headers.set("oai-authenticated-user-id", "local-shem-owner");
          headers.set(
            "oai-authenticated-user-email",
            "local-preview@sites.test",
          );
          const chunks = [];
          let size = 0;
          for await (const chunk of req) {
            size += chunk.length;
            if (size > 9 * 1024 * 1024) {
              res.statusCode = 413;
              res.end("Too large");
              return;
            }
            chunks.push(chunk);
          }
          const bytes = Buffer.concat(chunks);
          const response = await mf.dispatchFetch(url, {
            method: req.method,
            headers,
            body: ["GET", "HEAD"].includes(req.method) ? undefined : bytes,
          });
          res.statusCode = response.status;
          response.headers.forEach((v, k) => res.setHeader(k, v));
          res.end(Buffer.from(await response.arrayBuffer()));
        } catch (e) {
          console.error("Local API error", e);
          res.statusCode = 503;
          res.end(JSON.stringify({ error: "Local preview unavailable" }));
        }
      });
      server.httpServer?.once("close", () => mf.dispose());
    },
  };
}
