import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { rmSync, renameSync, existsSync } from 'node:fs'
import { resolve } from 'node:path'

const out = resolve(__dirname, '../backend/public')

/**
 * The SPA is built straight into Laravel's public/ dir: hashed files in /assets, the
 * service worker + manifest at the root, and index.html renamed to spa.html (Laravel's
 * fallback route serves it for every non-API URL, so deep links work on shared hosting).
 */
function laravelPublic(): Plugin {
  return {
    name: 'laravel-public',
    apply: 'build',
    buildStart() {
      rmSync(resolve(out, 'assets'), { recursive: true, force: true })
    },
    closeBundle() {
      if (existsSync(resolve(out, 'index.html'))) renameSync(resolve(out, 'index.html'), resolve(out, 'spa.html'))
    },
  }
}

export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    laravelPublic(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: false, // registered manually in main.ts after first paint
      manifest: {
        name: 'Duka Shop',
        short_name: 'Duka',
        description: 'Shop online, and the shop till/back office',
        theme_color: '#6f4e37',
        background_color: '#ffffff',
        display: 'standalone',
        start_url: '/',
        icons: [
          { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // Precache the app shell, storefront and POS (so the till opens with no connection), but
        // not back-office pages: shoppers on mobile data shouldn't download admin code. Those
        // chunks are cached on first use by the /assets/ runtime rule below.
        globPatterns: ['assets/**/*.{js,css,woff2}', 'icons/*.png'],
        manifestTransforms: [
          async (entries) => ({
            manifest: entries.filter((e) => !/assets\/(Dashboard|Products|ProductEdit|Categories|Inventory|StockAdjust|Supplier|Suppliers|PurchaseOrder|PurchaseOrders|PurchaseOrderEdit|ReceiveGoods|GoodsReceipts|Sales|Sale|Customers|Customer|Expenses|Reports|Shifts|Users|Settings|Conflicts|Audit|Ads)Page-/.test(e.url)),
            warnings: [],
          }),
        ],
        navigateFallback: '/spa.html',
        additionalManifestEntries: [{ url: '/spa.html', revision: String(Date.now()) }],
        navigateFallbackDenylist: [/^\/api\//, /^\/sanctum\//, /^\/storage\//, /^\/up$/],
        runtimeCaching: [
          {
            // Hashed build files never change: cache forever once fetched.
            urlPattern: ({ url }) => url.pathname.startsWith('/assets/'),
            handler: 'CacheFirst',
            options: { cacheName: 'app-chunks', expiration: { maxEntries: 120, maxAgeSeconds: 60 * 86400 } },
          },
          {
            // Public catalog JSON: show cached instantly, refresh in background.
            urlPattern: ({ url }) => url.pathname.startsWith('/api/shop/'),
            handler: 'StaleWhileRevalidate',
            options: { cacheName: 'catalog-api', expiration: { maxEntries: 200, maxAgeSeconds: 86400 } },
          },
          {
            urlPattern: ({ url }) => url.pathname.startsWith('/storage/'),
            handler: 'CacheFirst',
            options: { cacheName: 'images', expiration: { maxEntries: 400, maxAgeSeconds: 30 * 86400 } },
          },
        ],
      },
    }),
  ],
  resolve: { alias: { '@': resolve(__dirname, 'src') } },
  build: {
    outDir: out,
    emptyOutDir: false,
    assetsDir: 'assets',
    target: 'es2020',
    cssCodeSplit: true,
    reportCompressedSize: true,
    chunkSizeWarningLimit: 120,
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/sanctum': 'http://127.0.0.1:8000',
      '/storage': 'http://127.0.0.1:8000',
    },
  },
})
