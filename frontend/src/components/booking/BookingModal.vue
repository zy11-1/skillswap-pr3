<script setup>
import { ref, computed, onMounted } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { api } from '@/data/api'

const props = defineProps({
  tutor: { type: Object, required: true },
  offering: { type: Object, required: true }
})

const emit = defineEmits(['close', 'booked'])

const bookingStore = useBookingStore()

// ====== Compute the date 7 days from now ======
const today = new Date()
const todayStr = today.toISOString().split('T')[0]
const maxDate = new Date()
maxDate.setDate(maxDate.getDate() + 7)
const maxDateStr = maxDate.toISOString().split('T')[0]

const date = ref('')
const time = ref('')
const duration = ref(1)
const submitting = ref(false)
const error = ref('')
const availableSlots = ref([])
const loadingSlots = ref(false)

const totalAmount = computed(() => props.offering.hourly_rate * duration.value)

async function loadAvailability() {
  loadingSlots.value = true
  try {
    const res = await api.getTutorAvailability(props.tutor.user_id)
    availableSlots.value = res.data || []
  } catch (err) {
    console.error('Failed to load availability:', err)
    availableSlots.value = []
  } finally {
    loadingSlots.value = false
  }
}

// Build the available time options for the selected date (matching the exact date)
const timeOptions = computed(() => {
  if (!date.value || !availableSlots.value.length) return []
  
  // ====== Match the exact selected date ======
  const slots = availableSlots.value.filter(s => s.available_date === date.value)
  
  const options = []
  slots.forEach(slot => {
    const start = parseInt(slot.start_time.split(':')[0])
    const end = parseInt(slot.end_time.split(':')[0])
    for (let h = start; h < end; h++) {
      const hourStr = String(h).padStart(2, '0')
      options.push(`${hourStr}:00`)
    }
  })
  return options
})

function onDateChange() {
  time.value = ''
}

async function submitBooking() {
  error.value = ''

  if (!date.value || !time.value) {
    error.value = 'Please choose a date and time.'
    return
  }

  const bookingDateTime = new Date(`${date.value}T${time.value}`)
  if (bookingDateTime < new Date()) {
    error.value = 'Please choose a future date and time.'
    return
  }

  submitting.value = true
  try {
    await bookingStore.createBooking({
      tutor_id: props.tutor.user_id,
      skill_id: props.offering.skill_id,
      booking_date: bookingDateTime.toISOString(),
      duration: duration.value
    })
    emit('booked')
  } catch (err) {
    error.value = err.message || 'Booking failed.'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadAvailability()
})
</script>

<template>
  <div class="modal-backdrop-custom" @click.self="emit('close')">
    <div class="card booking-modal shadow-lg">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h5 class="fw-bold mb-0">Book a session</h5>
          <button class="btn-close" @click="emit('close')"></button>
        </div>

        <p class="text-muted small">
          with <strong>{{ tutor.name }}</strong> — {{ offering.skill_name }}
        </p>

        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

        <form @submit.prevent="submitBooking">
          <div class="mb-3">
            <label class="form-label small">Date</label>
            <input
              v-model="date"
              type="date"
              class="form-control"
              :min="todayStr"
              :max="maxDateStr"
              @change="onDateChange"
              required
            />
            <!-- ====== Added a hint ====== -->
            <div class="form-text text-muted small">
              <i class="bi bi-info-circle me-1"></i>
              Only availability within the <strong>next 7 days</strong> is shown.
            </div>
            <!-- ====== End of hint ====== -->
          </div>

          <div class="mb-3">
            <label class="form-label small">Time</label>
            <div v-if="loadingSlots" class="text-muted small">Loading available times...</div>
            <div v-else-if="!date" class="text-muted small">Please select a date first.</div>
            <div v-else-if="!timeOptions.length" class="text-muted small">No available slots for this day.</div>
            <select v-else v-model="time" class="form-select" required>
              <option value="" disabled>Select a time</option>
              <option v-for="opt in timeOptions" :key="opt" :value="opt">
                {{ opt }}
              </option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small">Duration (hours)</label>
            <select v-model="duration" class="form-select">
              <option :value="1">1 hour</option>
              <option :value="2">2 hours</option>
              <option :value="3">3 hours</option>
            </select>
          </div>

          <div class="d-flex justify-content-between align-items-center py-2 border-top border-bottom mb-3">
            <span class="text-muted small">Total</span>
            <span class="fw-bold text-primary-ss">RM{{ totalAmount.toFixed(2) }}</span>
          </div>

          <button type="submit" class="btn btn-primary w-100" :disabled="submitting">
            <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
            {{ submitting ? 'Sending request...' : 'Send Booking Request' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
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

.booking-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}
</style>