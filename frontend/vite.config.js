import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

// Build output goes straight into the backend's public folder, next to /api.
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
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
        navigateFallbackDenylist: [/^\/api\//],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
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
