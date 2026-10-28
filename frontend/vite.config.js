import { readFileSync, readdirSync, rmSync, existsSync } from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// Build output goes straight into the backend's public folder, next to /api.
// The version shown under Settings (and compared with the server's).
const version = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8')).version

/**
 * The output folder also holds the API (api/, .htaccess), so Vite may not empty it. Remove the previous build's
 * files ourselves: otherwise old assets pile up and the service worker stores all of them on every device.
 */
function cleanPreviousBuild() {
  const out = new URL('../backend/public/', import.meta.url)
  const keep = new Set(['api', '.htaccess', 'install.php', 'app-path.php', 'robots.txt'])
  return {
    name: 'postvak-clean-previous-build',
    apply: 'build',
    buildStart() {
      if (!existsSync(out)) return
      for (const name of readdirSync(out)) {
        if (!keep.has(name)) rmSync(new URL(name, out), { recursive: true, force: true })
      }
    },
  }
}

export default defineConfig({
  define: { __APP_VERSION__: JSON.stringify(version) },
  plugins: [
    cleanPreviousBuild(),
    vue(),
    tailwindcss(),
    VitePWA({
      // Registered in src/appUpdate.js: checks for updates when the app comes to the foreground.
      registerType: 'prompt',
      injectRegister: false,
      includeAssets: ['icon.svg', 'badge-96.png', 'push-sw.js'],
      manifest: {
        name: 'Postvak',
        short_name: 'Postvak',
        description: 'Webmail for your own mailboxes',
        theme_color: '#3646D9',
        background_color: '#F2F4F7',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: 'icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icon.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any maskable' },
        ],
      },
      workbox: {
        // Never cache API calls: mail must always come from the server.
        navigateFallbackDenylist: [/^\/api\//, /^\/dav\//, /^\/\.well-known\//, /^\/install\.php/],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
        // The PDF viewer's worker (1.3 MB) is fetched when a PDF is opened, not stored ahead on every device.
        globIgnores: ['**/pdf.worker*.js', 'demo/**'],
        // Push notifications: handled in public/push-sw.js.
        importScripts: ['push-sw.js'],
      },
    }),
  ],
  test: {
    include: ['src/**/*.test.js'],
    environment: 'node',
  },
  build: {
    outDir: '../backend/public',
    emptyOutDir: false, // keep api/ and .htaccess
  },
  server: {
    port: 5173,
    // Run the PHP API locally with:  php -S localhost:8000 -t backend/public
    proxy: { '/api': 'http://localhost:8000' },
  },
})
