import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
})

// Attach the static bearer token if configured.
api.interceptors.request.use((config) => {
  const token = import.meta.env.VITE_API_TOKEN
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

export default api
