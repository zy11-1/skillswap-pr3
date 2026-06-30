<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/data/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const slot = ref(null)
const loading = ref(true)
const error = ref('')
const selectedSkillId = ref('')
const booking = ref(false)
const confirmed = ref(false)

const token = route.params.token

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getSlotByToken(token)
    slot.value = res.data
    if (slot.value.offerings?.length) {
      selectedSkillId.value = slot.value.offerings[0].skill_id
    }
  } catch (err) {
    error.value = err.message || 'This invite link is invalid.'
  } finally {
    loading.value = false
  }
}

onMounted(load)

const selectedOffering = computed(() =>
  slot.value?.offerings?.find((o) => o.skill_id === Number(selectedSkillId.value))
)

function hours() {
  if (!slot.value) return 1
  const [sh, sm] = slot.value.start_time.split(':').map(Number)
  const [eh, em] = slot.value.end_time.split(':').map(Number)
  return Math.max(1, Math.round((eh * 60 + em - (sh * 60 + sm)) / 60))
}

const price = computed(() => (selectedOffering.value ? (selectedOffering.value.hourly_rate * hours()).toFixed(2) : '0.00'))

function formatDate(d) {
  return new Date(d).toLocaleDateString('en-MY', { weekday: 'long', day: 'numeric', month: 'long' })
}

async function reserve() {
  if (!auth.isLoggedIn) {
    // Send them to login, then back to this invite.
    router.push({ name: 'login', query: { redirect: route.fullPath } })
    return
  }
  if (!selectedSkillId.value) {
    error.value = 'Please choose what you want to be taught.'
    return
  }
  booking.value = true
  error.value = ''
  try {
    await api.createBooking({
      availability_id: slot.value.availability_id,
      skill_id: Number(selectedSkillId.value),
      share_token: token
    })
    confirmed.value = true
    setTimeout(() => router.push('/bookings'), 1200)
  } catch (err) {
    error.value = err.message || 'Could not book this session.'
  } finally {
    booking.value = false
  }
}
</script>

<template>
  <div class="container py-5" style="max-width: 540px">
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="error && !slot" class="alert alert-danger">
      <i class="bi bi-exclamation-triangle me-2"></i>{{ error }}
    </div>

    <div v-else-if="slot" class="card border-0 shadow-sm">
      <div class="card-body p-4">
        <span class="badge bg-dark mb-2"><i class="bi bi-lock-fill me-1"></i>Private invite</span>
        <h4 class="fw-bold mb-1">You're invited to a session</h4>
        <p class="text-muted mb-3">with <strong>{{ slot.tutor_name }}</strong></p>

        <ul class="list-unstyled small mb-3">
          <li class="mb-1"><i class="bi bi-calendar3 me-2"></i>{{ formatDate(slot.available_date) }}</li>
          <li class="mb-1"><i class="bi bi-clock me-2"></i>{{ slot.start_time.slice(0,5) }}–{{ slot.end_time.slice(0,5) }}</li>
          <li class="mb-1">
            <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-2"></i>
            {{ slot.mode }}<template v-if="slot.location"> · {{ slot.location }}</template>
          </li>
          <li class="mb-1">
            <i :class="slot.type === 'Group' ? 'bi bi-people-fill' : 'bi bi-person-fill'" class="me-2"></i>
            {{ slot.type }}<template v-if="slot.type === 'Group'"> · {{ slot.seats_left }} seat(s) left</template>
          </li>
          <li v-if="slot.outcomes" class="mb-1"><i class="bi bi-bullseye me-2"></i>{{ slot.outcomes }}</li>
        </ul>

        <div v-if="confirmed" class="alert alert-success">
          <i class="bi bi-check-circle-fill me-2"></i>Booked &amp; confirmed! Taking you to your bookings…
        </div>
        <template v-else>
          <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

          <div v-if="slot.is_full" class="alert alert-warning py-2 small">This session is full.</div>
          <template v-else>
            <div class="mb-3">
              <label class="form-label small">What do you want to be taught?</label>
              <select v-model="selectedSkillId" class="form-select">
                <option v-for="o in slot.offerings" :key="o.skill_id" :value="o.skill_id">
                  {{ o.skill_name }} — RM{{ o.hourly_rate.toFixed(2) }}/hr ({{ o.level }})
                </option>
              </select>
              <p v-if="!slot.offerings.length" class="text-danger small mt-1">This tutor hasn't listed any skills yet.</p>
            </div>

            <div class="d-flex justify-content-between align-items-center py-2 border-top border-bottom mb-3">
              <span class="text-muted small">Total</span>
              <span class="fw-bold text-primary-ss">RM{{ price }}</span>
            </div>

            <button class="btn btn-primary w-100" :disabled="booking || !slot.offerings.length" @click="reserve">
              <span v-if="booking" class="spinner-border spinner-border-sm me-2"></span>
              {{ auth.isLoggedIn ? (booking ? 'Booking…' : 'Reserve my seat') : 'Log in to reserve' }}
            </button>
          </template>
        </template>
      </div>
    </div>
  </div>
</template>
