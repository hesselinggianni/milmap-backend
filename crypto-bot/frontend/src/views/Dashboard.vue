<script setup>
import { onMounted, computed } from 'vue'
import { useBotStore } from '../stores/bot'
import EquityChart from '../components/EquityChart.vue'

const bot = useBotStore()
onMounted(() => bot.refreshAll())

const pnlClass = computed(() => {
  const v = Number(bot.portfolio?.unrealized_pnl ?? 0)
  return v >= 0 ? 'pos' : 'neg'
})
</script>

<template>
  <h2>Dashboard</h2>

  <div v-if="bot.status" class="card" style="margin-bottom:1rem; display:flex; gap:1rem; align-items:center; flex-wrap:wrap;">
    <span>Bot: <span class="badge" :class="bot.status.enabled ? 'off' : 'on'">{{ bot.status.enabled ? 'ACTIEF' : 'UIT' }}</span></span>
    <span>Kill-switch: <span class="badge" :class="bot.status.kill_switch ? 'on' : 'off'">{{ bot.status.kill_switch ? 'AAN' : 'uit' }}</span></span>
    <span>Circuit-breaker: <span class="badge" :class="bot.status.circuit_breaker ? 'on' : 'off'">{{ bot.status.circuit_breaker ? 'GETRIPT' : 'ok' }}</span></span>
    <span style="flex:1"></span>
    <button v-if="!bot.status.kill_switch" class="btn danger" @click="bot.halt()">🛑 Kill-switch</button>
    <button v-else class="btn ghost" @click="bot.resume()">Hervat</button>
  </div>

  <p v-if="bot.error" class="neg">Kan API niet bereiken: {{ bot.error }}</p>

  <div class="grid" style="margin-bottom:1rem;" v-if="bot.portfolio">
    <div class="card"><div class="label">Equity</div><div class="value">€{{ bot.portfolio.equity }}</div></div>
    <div class="card"><div class="label">Cash</div><div class="value">€{{ bot.portfolio.cash }}</div></div>
    <div class="card"><div class="label">Posities</div><div class="value">€{{ bot.portfolio.positions_value }}</div></div>
    <div class="card"><div class="label">Ongerealiseerd P&L</div><div class="value" :class="pnlClass">€{{ bot.portfolio.unrealized_pnl }}</div></div>
    <div class="card"><div class="label">Gerealiseerd P&L</div><div class="value">€{{ bot.portfolio.realized_pnl_cum }}</div></div>
  </div>

  <EquityChart v-if="bot.portfolio" :curve="bot.portfolio.equity_curve" />
</template>
