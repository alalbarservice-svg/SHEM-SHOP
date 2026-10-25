import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { localApi } from "./scripts/local-api-plugin.mjs";

export default defineConfig({
  define: { "import.meta.env.VITE_SHOP_ROLE": JSON.stringify("home") },
  build: {
    outDir: "dist/client",
    // Dynamic chunks (admin-only code) go to a path the WordPress theme serves to administrators only.
    rollupOptions: { output: { chunkFileNames: "admin-private/[name]-[hash].js" } },
  },
  optimizeDeps: {
    include: ["react", "react-dom/client"],
  },
  server: {
    host: "0.0.0.0",
    allowedHosts: ["terminal.local"],
    warmup: {
      clientFiles: ["./src/main.jsx"],
    },
  },
  plugins: [react(),localApi()],
});
