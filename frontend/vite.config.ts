import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { fileURLToPath, URL } from 'node:url'
import path from 'node:path'
import { PWA_NAVIGATE_FALLBACK_DENYLIST } from './src/lib/pwaServerPaths'

function originFromUrl(value: string, fallback: string): string {
  try {
    return new URL(value).origin
  } catch {
    return fallback
  }
}

function portFromUrl(value: string, fallback: number): number {
  try {
    const port = new URL(value).port
    return port ? Number(port) : fallback
  } catch {
    return fallback
  }
}

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, path.resolve(__dirname, '..'), '')
  const appUrl = env.APP_URL || 'http://127.0.0.1:8000'
  const frontendUrl = env.FRONTEND_URL || 'http://127.0.0.1:5173'
  const apiOrigin = originFromUrl(appUrl, 'http://127.0.0.1:8000')
  const vitePort = Number(env.VITE_PORT) || portFromUrl(frontendUrl, 5173)

  return {
    base: '/',
    plugins: [
      vue(),
      tailwindcss(),
      VitePWA({
        registerType: 'autoUpdate',
        // Register from src/pwa.ts so localhost/127.0.0.1 never keeps a SW.
        injectRegister: false,
        includeAssets: ['recordplus_logo_app.png'],
        manifest: {
          name: 'RecordPlus',
          short_name: 'RecordPlus',
          description: 'Just Upload. We Handle the Filing.',
          theme_color: '#2D6BFF',
          background_color: '#F8F9FB',
          display: 'standalone',
          start_url: '/',
          icons: [
            {
              src: '/recordplus_logo_app.png',
              sizes: '192x192',
              type: 'image/png',
            },
            {
              src: '/recordplus_logo_app.png',
              sizes: '512x512',
              type: 'image/png',
            },
          ],
        },
        workbox: {
          // injectRegister:false skips plugin auto-inject; set these so open tabs activate+claim.
          skipWaiting: true,
          clientsClaim: true,
          navigateFallback: '/index.html',
          navigateFallbackDenylist: PWA_NAVIGATE_FALLBACK_DENYLIST,
          runtimeCaching: [
            {
              urlPattern: ({ url }) =>
                url.pathname.startsWith('/api') ||
                url.pathname.startsWith('/auth') ||
                url.pathname.startsWith('/sanctum'),
              handler: 'NetworkOnly',
            },
          ],
        },
      }),
    ],
    resolve: {
      alias: {
        '@': fileURLToPath(new URL('./src', import.meta.url)),
      },
    },
    server: {
      port: vitePort,
      proxy: {
        '/api': {
          target: apiOrigin,
          changeOrigin: true,
        },
        '/sanctum': {
          target: apiOrigin,
          changeOrigin: true,
        },
        '/auth': {
          target: apiOrigin,
          changeOrigin: true,
        },
      },
    },
    build: {
      outDir: '../public',
      emptyOutDir: false,
    },
  }
})
