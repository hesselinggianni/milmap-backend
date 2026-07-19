import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    port: 5180,
    strictPort: false, // val terug op een vrije poort als 5180 bezet is
  },
})
