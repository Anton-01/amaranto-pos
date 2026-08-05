import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

const apiTarget = process.env.VITE_API_URL || 'http://localhost:8000'

const apiProxy = {
  '/api': {
    target: apiTarget,
    changeOrigin: true,
  },
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 3000,
    host: true,
    proxy: apiProxy,
  },
  // `vite preview` sirve el bundle YA compilado. Compartir el proxy permite
  // validar contra la API el mismo artefacto que se despliega, en lugar de
  // fiarse solo del servidor de desarrollo (que tiene HMR y otro pipeline).
  preview: {
    port: 4173,
    proxy: apiProxy,
  },
})
