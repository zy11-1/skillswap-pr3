<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/data/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const upcomingClasses = ref([])
const loading = ref(true)
const error = ref(null)

// "Upcoming classes" board: classes another student already started (topic set
// + tutor published what they'll cover). Anyone can join; hide the viewer's own.
const joinableClasses = computed(() =>
  upcomingClasses.value.filter((c) => c.tutor_id !== auth.user?.user_id)
)

// Join flow (prepay confirmation in a small modal).
const joinTarget = ref(null)
const joining = ref(false)
const joinError = ref('')
const joinDone = ref(false)

function openJoin(c) {
  joinTarget.value = c
  joinError.value = ''
  joinDone.value = false
}

async function confirmJoin() {
  if (!joinTarget.value) return
  joining.value = true
  joinError.value = ''
  try {
    await api.createBooking({ availability_id: joinTarget.value.availability_id })
    joinDone.value = true
    // Refresh the board so the booked-count / price reflect this join.
    const res = await api.getUpcomingClasses()
    upcomingClasses.value = res.data || []
    setTimeout(() => { joinTarget.value = null }, 1400)
  } catch (err) {
    joinError.value = err.message || 'Could not join this class.'
  } finally {
    joining.value = false
  }
}

function classWhen(c) {
  return new Date(`${c.available_date}T${c.start_time}`).toLocaleString('en-MY', {
    weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
  })
}

async function loadData() {
  loading.value = true
  error.value = null
  try {
    const res = await api.getUpcomingClasses()
    upcomingClasses.value = res.data || []
  } catch (err) {
    error.value = 'Failed to load upcoming classes. Please check that the backend is running.'
    console.error('Failed to load upcoming classes:', err)
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <div class="container py-4">
    <div class="mb-4">
      <h3 class="fw-bold"><i class="bi bi-calendar-event text-primary-ss me-2"></i>Upcoming classes</h3>
      <p class="text-muted">Classes other students started — join in, and the price drops RM1 for every extra person who books.</p>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
      <p class="text-muted mt-2">Loading classes...</p>
    </div>

    <!-- Error message -->
    <div v-else-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Board -->
    <template v-else>
      <div v-if="joinableClasses.length" class="row g-3">
        <div v-for="c in joinableClasses" :key="'cls-' + c.availability_id" class="col-md-4">
          <div class="card border-0 shadow-sm h-100 upcoming-card">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-bookmark-fill me-1"></i>{{ c.topic }}</span>
                <span class="badge" :class="c.mode === 'Online' ? 'bg-primary' : 'bg-success'">
                  <i :class="c.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ c.mode }}
                </span>
              </div>
              <p class="small text-muted mb-1">with <strong>{{ c.tutor_name }}</strong></p>
              <p class="small mb-1"><i class="bi bi-calendar3 me-1"></i>{{ classWhen(c) }} · {{ c.hours }}h</p>
              <p v-if="c.topics_covered" class="small text-muted mb-2 text-truncate-2">
                <i class="bi bi-card-text me-1"></i>{{ c.topics_covered }}
              </p>
              <div class="mt-auto d-flex justify-content-between align-items-center">
                <span>
                  <span class="fw-bold text-primary-ss">RM{{ Number(c.next_price).toFixed(2) }}</span>
                  <span class="d-block text-muted" style="font-size:.65rem">
                    {{ c.booked_count }} booked · final if you join: RM{{ Number(c.projected_price_join).toFixed(2) }}
                  </span>
                </span>
                <button class="btn btn-primary btn-sm" @click="openJoin(c)">
                  <i class="bi bi-plus-circle me-1"></i>Join
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div v-else class="text-center py-5 text-muted">
        <i class="bi bi-calendar-x" style="font-size: 2rem"></i>
        <p class="mt-2">No upcoming classes to join yet. Check back soon, or book a tutor from the Marketplace to start one.</p>
      </div>
    </template>

    <!-- Join-a-class confirmation (prepay) -->
    <div v-if="joinTarget" class="modal-backdrop-custom" @click.self="joinTarget = null">
      <div class="card join-modal shadow-lg">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="fw-bold mb-0">Join this class?</h5>
            <button class="btn-close" @click="joinTarget = null"></button>
          </div>

          <div v-if="joinDone" class="alert alert-success mb-0">
            <i class="bi bi-check-circle-fill me-2"></i>Request sent — the tutor will approve it. You'll be notified.
          </div>
          <template v-else>
            <p class="text-muted small mb-2">
              <strong>{{ joinTarget.topic }}</strong> with {{ joinTarget.tutor_name }}<br />
              {{ classWhen(joinTarget) }} · {{ joinTarget.hours }}h
            </p>
            <div v-if="joinError" class="alert alert-danger py-2 small">{{ joinError }}</div>
            <div class="alert alert-warning py-2 small">
              <i class="bi bi-wallet2 me-1"></i>
              <strong>RM{{ Number(joinTarget.next_price).toFixed(2) }}</strong> will be deducted from your wallet now
              (refunded in full if the tutor declines). The price drops RM1 for every student in the class —
              after it runs, everyone is refunded down to the final price (projected: RM{{ Number(joinTarget.projected_price_join).toFixed(2) }}).
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary" :disabled="joining" @click="confirmJoin">
                <span v-if="joining" class="spinner-border spinner-border-sm me-2"></span>
                {{ joining ? 'Booking…' : `Confirm & pay RM${Number(joinTarget.next_price).toFixed(2)}` }}
              </button>
              <button class="btn btn-light" @click="joinTarget = null">Cancel</button>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.text-truncate-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.upcoming-card {
  border-top: 3px solid var(--ss-primary) !important;
}
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
.join-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}
</style>
