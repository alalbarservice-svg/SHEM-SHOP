import { api } from "./api.js";
export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname.startsWith("/api/") || url.pathname.startsWith("/payment/")) return api(request, env);
    if (url.pathname.startsWith("/media/")) {
      const key = url.pathname.slice(7);
      if (
        !/^products\/[a-zA-Z0-9.-]+$/.test(key) ||
        !["GET", "HEAD"].includes(request.method)
      )
        return new Response("Not found", { status: 404 });
      const object = await env.BUCKET?.get(key);
      if (!object) return new Response("Not found", { status: 404 });
      return new Response(request.method === "HEAD" ? null : object.body, {
        headers: {
          "content-type":
            object.httpMetadata?.contentType || "application/octet-stream",
          "cache-control": "public,max-age=86400",
          "x-content-type-options": "nosniff",
        },
      });
    }
    const response = await env.ASSETS.fetch(request);
    const acceptsHtml = request.headers.get("accept")?.includes("text/html");

    if (
      response.status !== 404 ||
      !acceptsHtml ||
      !["GET", "HEAD"].includes(request.method)
    ) {
      return response;
    }

    const indexUrl = new URL(request.url);
    indexUrl.pathname = "/index.html";
    indexUrl.search = "";
    return env.ASSETS.fetch(new Request(indexUrl, request));
  },
};
