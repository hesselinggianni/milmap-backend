import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 3000,
    strictPort: false, // val terug op een vrije poort als 3000 bezet is
  },
})
