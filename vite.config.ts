import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import sitemap from 'vite-plugin-sitemap'
// lol
const publicRoutes = [
  '/',
  '/services',
  '/products',
  '/portfolio',
  '/booking',
  '/blog',
  '/about',
  '/faq',
  '/contact',
  '/order',
  '/calendar',
]

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    sitemap({
      hostname: 'https://1625autolab.com',
      dynamicRoutes: publicRoutes,
      exclude: [
        '/admin',
        '/client',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/api',
        '/private',
        '/backend',
        '/config',
        '/vendor',
      ],
      changefreq: 'weekly',
      priority: 0.8,
      generateRobotsTxt: true,
    }),
  ],
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('react') || id.includes('react-dom') || id.includes('react-router-dom')) {
            return 'react'
          }

          if (id.includes('axios') || id.includes('date-fns') || id.includes('lucide-react')) {
            return 'vendor'
          }
        },
      },
    },
  },
})
