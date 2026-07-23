<script setup>
import { RouterLink, RouterView } from 'vue-router'
import { onMounted } from 'vue'
import { useBotStore } from './stores/bot'

const bot = useBotStore()
onMounted(() => bot.loadStatus().catch(() => {}))
</script>

<template>
  <nav class="nav">
    <strong>🤖 Crypto Bot</strong>
    <RouterLink to="/">Dashboard</RouterLink>
    <RouterLink to="/positions">Posities</RouterLink>
    <RouterLink to="/trades">Trades</RouterLink>
    <RouterLink to="/events">Events</RouterLink>
    <RouterLink to="/strategies">Strategieën</RouterLink>
    <span style="flex:1"></span>
    <span v-if="bot.status" class="badge" :class="bot.status.mode">
      {{ bot.status.mode === 'live' ? 'LIVE' : 'PAPER MODE' }}
    </span>
  </nav>
  <div class="container">
    <RouterView />
  </div>
</template>
