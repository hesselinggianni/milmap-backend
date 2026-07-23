import { defineStore } from 'pinia'
import api from '../api'

export const useBotStore = defineStore('bot', {
  state: () => ({
    status: null,
    portfolio: null,
    positions: [],
    trades: [],
    orders: [],
    events: [],
    strategies: { available: [], configs: [] },
    loading: false,
    error: null,
  }),
  actions: {
    async loadStatus() {
      this.status = (await api.get('/status')).data
    },
    async loadPortfolio() {
      this.portfolio = (await api.get('/portfolio')).data
    },
    async loadPositions() {
      this.positions = (await api.get('/positions')).data
    },
    async loadTrades() {
      this.trades = (await api.get('/trades')).data
    },
    async loadOrders() {
      this.orders = (await api.get('/orders')).data
    },
    async loadEvents() {
      this.events = (await api.get('/events')).data
    },
    async loadStrategies() {
      this.strategies = (await api.get('/strategies')).data
    },
    async refreshAll() {
      this.loading = true
      this.error = null
      try {
        await Promise.all([
          this.loadStatus(),
          this.loadPortfolio(),
          this.loadPositions(),
          this.loadTrades(),
        ])
      } catch (e) {
        this.error = e.message
      } finally {
        this.loading = false
      }
    },
    async halt() {
      await api.post('/control/halt')
      await this.loadStatus()
    },
    async resume() {
      await api.post('/control/resume')
      await this.loadStatus()
    },
    async activateStrategy(id) {
      await api.post(`/strategies/${id}/activate`)
      await this.loadStrategies()
    },
  },
})
