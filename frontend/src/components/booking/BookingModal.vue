<script setup>
import { ref, computed } from 'vue'
import { useBookingStore } from '@/stores/booking'

const props = defineProps({
  tutor: { type: Object, required: true },
  offering: { type: Object, required: true }
})

const emit = defineEmits(['close', 'booked'])

const bookingStore = useBookingStore()

const today = new Date().toISOString().split('T')[0]
const date = ref('')
const time = ref('')
const duration = ref(1)
const submitting = ref(false)
const error = ref('')

const totalAmount = computed(() => props.offering.hourly_rate * duration.value)

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
    // Note: we don't send learner_id or total_amount — the backend
    // reads learner_id from the JWT and recalculates total_amount
    // from the tutor's stored hourly rate, so a tampered client
    // value here could never be trusted anyway.
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
            <input v-model="date" type="date" class="form-control" :min="today" required />
          </div>
          <div class="mb-3">
            <label class="form-label small">Time</label>
            <input v-model="time" type="time" class="form-control" required />
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
