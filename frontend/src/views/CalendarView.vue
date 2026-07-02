<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/data/api'
import { useAuthStore } from '@/stores/auth'
import TipBanner from '@/components/TipBanner.vue'

const auth = useAuthStore()
const router = useRouter()

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

// Clicking a session opens its class page (topic, students, materials,
// links — everything in one place). Legacy non-slot bookings fall back
// to My Classes.
function openSession(b) {
  if (b && b.availability_id) {
    router.push(`/class/${b.availability_id}`)
  } else {
    router.push('/bookings')
  }
}

// ---- Add a class straight from a calendar day (tutor only) ----
const showAdd = ref(false)
const addForm = ref({ start_time: '09:00', end_time: '11:00', base_price: 20, mode: 'Physical', location: '', meeting_link: '', visibility: 'Public' })
const addError = ref('')
const addingClass = ref(false)

function isPast(dayKey) {
  return dayKey < todayKey
}

function openAdd() {
  addError.value = ''
  addForm.value = { start_time: '09:00', end_time: '11:00', base_price: 20, mode: 'Physical', location: '', meeting_link: '', visibility: 'Public' }
  showAdd.value = true
}

async function submitAdd() {
  addError.value = ''
  if (addForm.value.end_time <= addForm.value.start_time) {
    addError.value = 'End time must be after start time.'
    return
  }
  if (Number(addForm.value.base_price) < 10) {
    addError.value = 'Base price must be at least RM10 per hour.'
    return
  }
  addingClass.value = true
  try {
    await api.addAvailability({
      available_date: selectedDay.value,
      start_time: addForm.value.start_time,
      end_time: addForm.value.end_time,
      base_price: Number(addForm.value.base_price),
      mode: addForm.value.mode,
      location: addForm.value.location,
      meeting_link: addForm.value.meeting_link,
      visibility: addForm.value.visibility
    })
    showAdd.value = false
    await load()
  } catch (err) {
    addError.value = err.message || 'Could not add the class.'
  } finally {
    addingClass.value = false
  }
}
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
        <p class="text-muted mb-0 small mt-1">
          <i class="bi bi-hand-index me-1"></i>Tap a day to see its sessions<template v-if="auth.isTutorMode"> or add a class</template>; tap a session to open its class page.
        </p>
      </div>
      <div class="btn-group btn-group-sm">
        <button class="btn btn-outline-secondary" @click="prevMonth"><i class="bi bi-chevron-left"></i></button>
        <button class="btn btn-outline-secondary" @click="today">Today</button>
        <button class="btn btn-outline-secondary" @click="nextMonth"><i class="bi bi-chevron-right"></i></button>
      </div>
    </div>

    <TipBanner tip-id="calendar-class-page">
      Click any class in the calendar to open its full page — topic, students, materials, meeting
      link, and messaging all live there.<template v-if="auth.isTutorMode"> As a tutor you can also tap any future day to open a new class slot on it.</template>
    </TipBanner>

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
              :title="(b.role === 'tutor' ? 'Teaching: ' : 'Learning: ') + b.skill_name + ' — open the class page'"
              @click.stop="openSession(b)"
            >
              {{ timeOf(b) }} {{ b.skill_name }}
            </div>
            <div v-if="sessionsOn(d).length > 3" class="small text-muted">+{{ sessionsOn(d).length - 3 }} more</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Selected-day detail -->
    <div v-if="selectedDay" class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-bold">{{ selectedDay }}</span>
        <button
          v-if="auth.isTutorMode && !isPast(selectedDay)"
          class="btn btn-sm btn-primary"
          @click="openAdd"
        >
          <i class="bi bi-plus-lg me-1"></i>Add a class this day
        </button>
      </div>
      <div v-if="selectedSessions.length" class="list-group list-group-flush">
        <button
          v-for="b in selectedSessions"
          :key="b.role + b.booking_id"
          class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
          @click="openSession(b)"
        >
          <span>
            <span class="badge me-2" :class="b.role === 'tutor' ? 'cal-teach text-dark' : 'cal-learn text-dark'">
              {{ b.role === 'tutor' ? 'Teaching' : 'Learning' }}
            </span>
            <strong>{{ timeOf(b) }}</strong> · {{ b.skill_name }}
            <span class="text-muted small">· {{ b.role === 'tutor' ? b.learner_name : b.tutor_name }}</span>
          </span>
          <span :class="`status-pill status-${b.status.toLowerCase()}`">{{ b.status }}</span>
        </button>
      </div>
      <div v-else class="card-body text-muted small">
        No sessions on this day.
        <template v-if="auth.isTutorMode && !isPast(selectedDay)"> Use “Add a class this day” to open one.</template>
      </div>
    </div>

    <!-- Quick add-a-class modal (tutor) -->
    <div v-if="showAdd" class="modal-backdrop-custom" @click.self="showAdd = false">
      <div class="card add-modal shadow-lg">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="fw-bold mb-0">New class · {{ selectedDay }}</h5>
            <button class="btn-close" @click="showAdd = false"></button>
          </div>
          <div v-if="addError" class="alert alert-danger py-2 small">{{ addError }}</div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small">Start</label>
              <input v-model="addForm.start_time" type="time" class="form-control form-control-sm" />
            </div>
            <div class="col-6">
              <label class="form-label small">End</label>
              <input v-model="addForm.end_time" type="time" class="form-control form-control-sm" />
            </div>
            <div class="col-6">
              <label class="form-label small">Base price /hr</label>
              <input v-model.number="addForm.base_price" type="number" min="10" step="1" class="form-control form-control-sm" />
            </div>
            <div class="col-6">
              <label class="form-label small">Mode</label>
              <select v-model="addForm.mode" class="form-select form-select-sm">
                <option value="Physical">Physical</option>
                <option value="Online">Online</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small">{{ addForm.mode === 'Online' ? 'Meeting link' : 'Location' }}</label>
              <input v-if="addForm.mode === 'Online'" v-model="addForm.meeting_link" type="url" class="form-control form-control-sm" placeholder="https://meet.google.com/..." />
              <input v-else v-model="addForm.location" type="text" class="form-control form-control-sm" placeholder="e.g. Library room 3" />
            </div>
            <div class="col-12">
              <label class="form-label small">Visibility</label>
              <select v-model="addForm.visibility" class="form-select form-select-sm">
                <option value="Public">Public (anyone can find &amp; book)</option>
                <option value="Private">Private (invite link only)</option>
              </select>
            </div>
          </div>
          <button class="btn btn-primary w-100 mt-3" :disabled="addingClass" @click="submitAdd">
            <span v-if="addingClass" class="spinner-border spinner-border-sm me-2"></span>
            {{ addingClass ? 'Adding…' : 'Add class' }}
          </button>
          <p class="text-muted small mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>The first student to book sets the topic and everyone pays the same price;
            the final price drops RM1 per student (never below RM10) and is auto-refunded after the class.
          </p>
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
  cursor: pointer;
}
.cal-pill:hover { filter: brightness(0.95); }
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(15, 20, 35, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1050;
  padding: 1rem;
}
.add-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}
/* Fixed role colours, independent of the navbar mode theme. */
.cal-teach { background: #d8eaff; color: #14529c; }   /* teaching = blue */
.cal-learn { background: #d8f5e6; color: #15824b; }   /* learning = green */
</style>
