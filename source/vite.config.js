import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import { resolve } from 'path'

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: false,
      filename: 'sw.js',
      // Only the intranet entry gets the PWA treatment
      includeAssets: ['favicon.ico', 'pwa-icons/*.png'],
      manifest: {
        name: 'CAMPO Intranet',
        short_name: 'CAMPO',
        description: 'Camp attendee intranet',
        theme_color: '#f59e0b',
        background_color: '#111009',
        display: 'standalone',
        orientation: 'portrait',
        start_url: '/intranet/',
        scope: '/intranet/',
        icons: [
          { src: '/pwa-icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: '/pwa-icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: '/pwa-icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' }
        ]
      },
      workbox: {
        navigateFallback: '/intranet/',
        navigateFallbackDenylist: [/^\/api/, /^\/admin/],
        runtimeCaching: [
          {
            urlPattern: /^https?.*\/api\/public\/intranet/,
            handler: 'NetworkFirst',
            options: {
              cacheName: 'intranet-api',
              expiration: { maxAgeSeconds: 86400 },
              networkTimeoutSeconds: 5
            }
          }
        ]
      }
    })
  ],
  resolve: {
    alias: { '@': resolve(__dirname, 'src') }
  },
  build: {
    outDir: 'dist',
    rollupOptions: {
      input: {
        main: resolve(__dirname, 'index.html'),
        intranet: resolve(__dirname, 'intranet.html')
      }
    }
  }
})
