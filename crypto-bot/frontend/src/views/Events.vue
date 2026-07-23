<script setup>
import { onMounted } from 'vue'
import { useBotStore } from '../stores/bot'

const bot = useBotStore()
onMounted(() => bot.loadEvents())

const levelClass = (l) => (l === 'critical' ? 'neg' : l === 'warning' ? '' : 'muted')
</script>

<template>
  <h2>Event-log (audit trail)</h2>
  <div class="card">
    <table>
      <thead><tr><th>Tijd</th><th>Type</th><th>Level</th><th>Bericht</th></tr></thead>
      <tbody>
        <tr v-for="e in bot.events" :key="e.id">
          <td class="muted">{{ new Date(e.created_at).toLocaleString() }}</td>
          <td>{{ e.type }}</td>
          <td :class="levelClass(e.level)">{{ e.level }}</td>
          <td>{{ e.message }}</td>
        </tr>
        <tr v-if="!bot.events.length"><td colspan="4" class="muted">Nog geen events.</td></tr>
      </tbody>
    </table>
  </div>
</template>
