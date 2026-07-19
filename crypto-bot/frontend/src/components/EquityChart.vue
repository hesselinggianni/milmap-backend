<script setup>
import { onMounted, ref, watch } from 'vue'
import {
  Chart, LineController, LineElement, PointElement, LinearScale, TimeScale, CategoryScale, Tooltip,
} from 'chart.js'

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, TimeScale, Tooltip)

const props = defineProps({ curve: { type: Array, default: () => [] } })
const canvas = ref(null)
let chart = null

function render() {
  if (!canvas.value) return
  const labels = props.curve.map((p) => new Date(p.captured_at).toLocaleString())
  const data = props.curve.map((p) => Number(p.equity_eur))

  if (chart) chart.destroy()
  chart = new Chart(canvas.value, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Equity (€)',
        data,
        borderColor: '#58a6ff',
        backgroundColor: 'rgba(88,166,255,0.12)',
        fill: true,
        tension: 0.25,
        pointRadius: 0,
      }],
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { ticks: { color: '#8b93a7', maxTicksLimit: 6 }, grid: { display: false } },
        y: { ticks: { color: '#8b93a7' }, grid: { color: '#2a3346' } },
      },
    },
  })
}

onMounted(render)
watch(() => props.curve, render, { deep: true })
</script>

<template>
  <div class="card">
    <div class="label">Equity curve</div>
    <canvas ref="canvas" height="110"></canvas>
    <p v-if="!curve.length" class="muted">Nog geen snapshots — draai <code>bot:tick</code> een paar keer.</p>
  </div>
</template>
