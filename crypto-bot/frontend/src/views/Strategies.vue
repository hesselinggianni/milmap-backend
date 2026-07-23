<script setup>
import { onMounted } from 'vue'
import { useBotStore } from '../stores/bot'

const bot = useBotStore()
onMounted(() => bot.loadStrategies())
</script>

<template>
  <h2>Strategieën</h2>
  <p class="muted">Beschikbaar: {{ bot.strategies.available.join(', ') }}</p>
  <div class="card">
    <table>
      <thead><tr><th>Strategie</th><th>Markt</th><th>Actief</th><th>Parameters</th><th></th></tr></thead>
      <tbody>
        <tr v-for="c in bot.strategies.configs" :key="c.id">
          <td>{{ c.strategy_key }}</td>
          <td>{{ c.market }}</td>
          <td><span class="badge" :class="c.is_active ? 'off' : ''">{{ c.is_active ? 'actief' : '—' }}</span></td>
          <td><code style="font-size:0.75rem">{{ JSON.stringify(c.params) }}</code></td>
          <td><button v-if="!c.is_active" class="btn ghost" @click="bot.activateStrategy(c.id)">Activeer</button></td>
        </tr>
        <tr v-if="!bot.strategies.configs.length"><td colspan="5" class="muted">Geen configs — draai <code>php artisan bot:seed-account</code>.</td></tr>
      </tbody>
    </table>
  </div>
</template>
