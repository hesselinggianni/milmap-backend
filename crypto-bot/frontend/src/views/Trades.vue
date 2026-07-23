<script setup>
import { onMounted } from 'vue'
import { useBotStore } from '../stores/bot'

const bot = useBotStore()
onMounted(() => bot.loadTrades())
</script>

<template>
  <h2>Trades</h2>
  <div class="card">
    <table>
      <thead>
        <tr><th>Tijd</th><th>Markt</th><th>Kant</th><th>Prijs</th><th>Aantal</th><th>Fee</th></tr>
      </thead>
      <tbody>
        <tr v-for="t in bot.trades" :key="t.id">
          <td class="muted">{{ new Date(t.executed_at).toLocaleString() }}</td>
          <td>{{ t.market }}</td>
          <td :class="t.side === 'buy' ? 'pos' : 'neg'">{{ t.side }}</td>
          <td>{{ t.price }}</td>
          <td>{{ t.quantity }}</td>
          <td>{{ t.fee }} {{ t.fee_asset }}</td>
        </tr>
        <tr v-if="!bot.trades.length"><td colspan="6" class="muted">Nog geen trades.</td></tr>
      </tbody>
    </table>
  </div>
</template>
