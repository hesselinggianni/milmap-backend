import { createRouter, createWebHistory } from 'vue-router'
import Dashboard from '../views/Dashboard.vue'
import Positions from '../views/Positions.vue'
import Trades from '../views/Trades.vue'
import Events from '../views/Events.vue'
import Strategies from '../views/Strategies.vue'

const routes = [
  { path: '/', name: 'dashboard', component: Dashboard },
  { path: '/positions', name: 'positions', component: Positions },
  { path: '/trades', name: 'trades', component: Trades },
  { path: '/events', name: 'events', component: Events },
  { path: '/strategies', name: 'strategies', component: Strategies },
]

export default createRouter({
  history: createWebHistory(),
  routes,
})
