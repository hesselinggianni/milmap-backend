<script setup>
import { onMounted } from 'vue'
import { useBotStore } from '../stores/bot'

const bot = useBotStore()
onMounted(() => bot.loadPositions())
</script>

<template>
  <h2>Posities</h2>
  <div class="card">
    <table>
      <thead>
        <tr><th>Markt</th><th>Aantal</th><th>Gem. entry</th><th>Stop</th><th>Take-profit</th><th>Status</th><th>Gerealiseerd P&L</th></tr>
      </thead>
      <tbody>
        <tr v-for="p in bot.positions" :key="p.id">
          <td>{{ p.market }}</td>
          <td>{{ p.quantity }}</td>
          <td>{{ p.avg_entry_price }}</td>
          <td>{{ p.stop_loss_price ?? '—' }}</td>
          <td>{{ p.take_profit_price ?? '—' }}</td>
          <td>{{ p.status }}</td>
          <td>{{ p.realized_pnl }}</td>
        </tr>
        <tr v-if="!bot.positions.length"><td colspan="7" class="muted">Geen posities.</td></tr>
      </tbody>
    </table>
  </div>
</template>
