<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/data/api'

const bookings = ref([])
const loading = ref(true)
const cursor = ref(new Date())          // any date within the displayed month
const selectedDay = ref(null)           // 'YYYY-MM-DD'

async function load() {
  loading.value = true
  try {
    // One calendar always shows BOTH roles: sessions I teach and sessions
    // I booked, tagged so they can be colour-coded (blue vs green).
    const [learnerRes, tutorRes] = await Promise.all([
      api.getBookings('learner'),
      api.getBookings('tutor')
    ])
    const learner = (learnerRes.data || []).map((b) => ({ ...b, role: 'learner' }))
    const tutor = (tutorRes.data || []).map((b) => ({ ...b, role: 'tutor' }))
    bookings.value = [...learner, ...tutor].filter((b) => b.status !== 'Cancelled')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const monthLabel = computed(() =>
  cursor.value.toLocaleDateString('en-MY', { month: 'long', year: 'numeric' })
)

function ymd(d) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Sessions grouped by their calendar day (YYYY-MM-DD).
const byDay = computed(() => {
  const map = {}
  for (const b of bookings.value) {
    const key = ymd(new Date(b.booking_date))
    ;(map[key] ||= []).push(b)
  }
  return map
})

// Build the 6x7 grid of dates for the displayed month.
const weeks = computed(() => {
  const year = cursor.value.getFullYear()
  const month = cursor.value.getMonth()
  const first = new Date(year, month, 1)
  const start = new Date(first)
  start.setDate(first.getDate() - first.getDay()) // back to Sunday
  const grid = []
  const day = new Date(start)
  for (let w = 0; w < 6; w++) {
    const row = []
    for (let d = 0; d < 7; d++) {
      row.push(new Date(day))
      day.setDate(day.getDate() + 1)
    }
    grid.push(row)
  }
  return grid
})

const todayKey = ymd(new Date())
const weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

function inMonth(d) {
  return d.getMonth() === cursor.value.getMonth()
}
function sessionsOn(d) {
  return byDay.value[ymd(d)] || []
}
function prevMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() - 1, 1)
}
function nextMonth() {
  cursor.value = new Date(cursor.value.getFullYear(), cursor.value.getMonth() + 1, 1)
}
function today() {
  cursor.value = new Date()
}
function timeOf(b) {
  return new Date(b.booking_date).toLocaleTimeString('en-MY', { hour: '2-digit', minute: '2-digit' })
}

const selectedSessions = computed(() => (selectedDay.value ? byDay.value[selectedDay.value] || [] : []))
</script>

<template>
  <div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h3 class="fw-bold mb-0">Calendar</h3>
        <p class="text-muted mb-0 small">
          Your full schedule —
          <span class="badge cal-teach text-dark"><i class="bi bi-easel me-1"></i>Teaching</span>
          <span class="badge cal-learn text-dark ms-1"><i class="bi bi-mortarboard me-1"></i>Learning</span>
        </p>
      </div>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-secondary" @click="prevMonth"><i class="bi bi-chevron-left"></i></button>
        <button class="btn btn-outline-secondary" @click="today">Today</button>
        <button class="btn btn-outline-secondary" @click="nextMonth"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>

    <h5 class="fw-bold mb-3">{{ monthLabel }}</h5>

    <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary-ss"></div></div>

    <div v-else class="card border-0 shadow-sm">
      <div class="card-body p-2">
        <div class="cal-grid text-center text-muted small fw-semibold mb-1">
          <div v-for="w in weekdayNames" :key="w">{{ w }}</div>
        </div>
        <div v-for="(week, wi) in weeks" :key="wi" class="cal-grid">
          <div
            v-for="(d, di) in week"
            :key="di"
            class="cal-cell p-1"
            :class="{ 'text-muted bg-light': !inMonth(d), 'cal-today': ymd(d) === todayKey }"
            @click="selectedDay = ymd(d)"
          >
            <div class="small fw-semibold">{{ d.getDate() }}</div>
            <div
              v-for="b in sessionsOn(d).slice(0, 3)"
              :key="b.role + b.booking_id"
              class="cal-pill text-truncate"
              :class="b.role === 'tutor' ? 'cal-teach' : 'cal-learn'"
              :title="(b.role === 'tutor' ? 'Teaching: ' : 'Learning: ') + b.skill_name"
            >
              {{ timeOf(b) }} {{ b.skill_name }}
            </div>
            <div v-if="sessionsOn(d).length > 3" class="small text-muted">+{{ sessionsOn(d).length - 3 }} more</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected-day detail -->
    <div v-if="selectedDay && selectedSessions.length" class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white fw-bold">{{ selectedDay }}</div>
      <div class="list-group list-group-flush">
        <div v-for="b in selectedSessions" :key="b.role + b.booking_id" class="list-group-item d-flex justify-content-between align-items-center">
          <span>
            <span class="badge me-2" :class="b.role === 'tutor' ? 'cal-teach text-dark' : 'cal-learn text-dark'">
              {{ b.role === 'tutor' ? 'Teaching' : 'Learning' }}
            </span>
            <strong>{{ timeOf(b) }}</strong> · {{ b.skill_name }}
            <span class="text-muted small">· {{ b.role === 'tutor' ? b.learner_name : b.tutor_name }}</span>
          </span>
          <span :class="`status-pill status-${b.status.toLowerCase()}`">{{ b.status }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 4px;
}
.cal-cell {
  min-height: 92px;
  border: 1px solid #eef1f6;
  border-radius: 8px;
  cursor: pointer;
  overflow: hidden;
}
.cal-cell:hover { background: #f6f8fc; }
.cal-today { border-color: var(--ss-primary); border-width: 2px; }
.cal-pill {
  font-size: 0.7rem;
  border-radius: 4px;
  padding: 1px 4px;
  margin-top: 2px;
}
/* Fixed role colours, independent of the navbar mode theme. */
.cal-teach { background: #d8eaff; color: #14529c; }   /* teaching = blue */
.cal-learn { background: #d8f5e6; color: #15824b; }   /* learning = green */
</style>
