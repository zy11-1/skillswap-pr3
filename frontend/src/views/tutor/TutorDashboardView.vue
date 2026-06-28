<script setup>
import { onMounted, ref } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/data/api'

const bookingStore = useBookingStore()
const auth = useAuthStore()
const updatingId = ref(null)

// ---- Availability ----
const availability = ref([])
const newSlot = ref({ 
  available_date: new Date().toISOString().split('T')[0], 
  start_time: '09:00', 
  end_time: '17:00' 
})
const saving = ref(false)
const loadingAvailability = ref(false)

async function loadAvailability() {
  loadingAvailability.value = true
  try {
    const res = await api.getTutorAvailability(auth.user.user_id)
    availability.value = res.data || []
  } catch (err) {
    console.error('Failed to load availability:', err)
    availability.value = []
  } finally {
    loadingAvailability.value = false
  }
}

async function addSlot() {
  if (!auth.user) {
    console.error('No user logged in')
    return
  }
  saving.value = true
  try {
    const res = await api.addAvailability(newSlot.value)
    availability.value.push({ ...newSlot.value, availability_id: res.data.availability_id })
    // Reset the form
    newSlot.value = { 
      available_date: new Date().toISOString().split('T')[0], 
      start_time: '09:00', 
      end_time: '17:00' 
    }
  } catch (err) {
    console.error('Failed to add slot:', err)
  } finally {
    saving.value = false
  }
}

function removeSlot(id) {
  availability.value = availability.value.filter(s => s.availability_id !== id)
}

onMounted(() => {
  bookingStore.fetchBookings()
  loadAvailability()
})

async function respond(bookingId, status) {
  updatingId.value = bookingId
  try {
    await bookingStore.updateStatus(bookingId, status)
  } finally {
    updatingId.value = null
  }
}

function statusClass(status) {
  return `status-pill status-${status.toLowerCase()}`
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">Tutor Dashboard</h3>
    <p class="text-muted">Manage your incoming session requests</p>

    <!-- Bookings -->
    <div v-if="bookingStore.loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="bookingStore.bookings.length" class="row g-3 mb-4">
      <div v-for="b in bookingStore.bookings" :key="b.booking_id" class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <h6 class="mb-0">{{ b.learner_name }}</h6>
              <span :class="statusClass(b.status)">{{ b.status }}</span>
            </div>
            <p class="small text-muted mb-1">{{ b.skill_name }}</p>
            <p class="small mb-1"><i class="bi bi-calendar3 me-1"></i>{{ formatDate(b.booking_date) }}</p>
            <p class="small mb-3">
              <i class="bi bi-clock me-1"></i>{{ b.duration }}h —
              <span class="fw-semibold">RM{{ b.total_amount.toFixed(2) }}</span>
            </p>

            <div v-if="b.status === 'Pending'" class="d-flex gap-2">
              <button
                class="btn btn-success btn-sm flex-fill"
                :disabled="updatingId === b.booking_id"
                @click="respond(b.booking_id, 'Accepted')"
              >
                Accept
              </button>
              <button
                class="btn btn-outline-danger btn-sm flex-fill"
                :disabled="updatingId === b.booking_id"
                @click="respond(b.booking_id, 'Cancelled')"
              >
                Decline
              </button>
            </div>

            <button
              v-else-if="b.status === 'Accepted'"
              class="btn btn-primary btn-sm w-100"
              :disabled="updatingId === b.booking_id"
              @click="respond(b.booking_id, 'Completed')"
            >
              Mark as Completed
            </button>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-3 text-muted">
      <i class="bi bi-inbox" style="font-size: 2rem"></i>
      <p class="mt-2">No booking requests yet.</p>
    </div>

    <!-- ============================================================ -->
    <!-- Tutor Availability Settings -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-clock me-2"></i>My Availability
      </div>
      <div class="card-body">
        <div class="alert alert-info py-1 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Availability only applies to the <strong>next 7 days</strong>.
          Students will only see and book within this window.
        </div>

        <div v-if="loadingAvailability" class="text-center py-2">
          <div class="spinner-border spinner-border-sm text-primary-ss"></div>
          <span class="ms-2 text-muted small">Loading...</span>
        </div>

        <!-- Existing slots -->
        <div v-if="availability.length" class="mb-3">
          <div
            v-for="slot in availability"
            :key="slot.availability_id"
            class="d-flex align-items-center justify-content-between bg-light p-2 rounded mb-1"
          >
            <span>
              <strong>{{ slot.available_date }}</strong>
              {{ slot.start_time.slice(0,5) }} – {{ slot.end_time.slice(0,5) }}
            </span>
            <button
              class="btn btn-sm btn-outline-danger"
              @click="removeSlot(slot.availability_id)"
            >
              <i class="bi bi-x"></i>
            </button>
          </div>
        </div>
        <p v-else class="text-muted small">No availability set yet.</p>

        <!-- Add new slot -->
        <div class="row g-2 align-items-end">
          <div class="col-4">
            <label class="form-label small">Date</label>
            <input v-model="newSlot.available_date" type="date" class="form-control form-control-sm" />
          </div>
          <div class="col-3">
            <label class="form-label small">Start</label>
            <input v-model="newSlot.start_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-3">
            <label class="form-label small">End</label>
            <input v-model="newSlot.end_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-2">
            <button
              class="btn btn-primary btn-sm w-100"
              :disabled="saving"
              @click="addSlot"
            >
              {{ saving ? '...' : 'Add' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>