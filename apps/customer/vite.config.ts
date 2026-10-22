import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";
import flowbiteReact from "flowbite-react/plugin/vite";

export default defineConfig({
  base: "./", // relative asset URLs: the WebView serves from capacitor://localhost or https://localhost
  server: { port: 5174, strictPort: true, host: true },
  plugins: [react(), tailwindcss(), flowbiteReact()],
});