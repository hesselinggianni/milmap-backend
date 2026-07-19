import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  server: {
    host: 'localhost', // altijd op http://localhost bereikbaar
    port: 4000,
    strictPort: true,  // faal luid als 4000 bezet is i.p.v. stil te verschuiven
  },
})
