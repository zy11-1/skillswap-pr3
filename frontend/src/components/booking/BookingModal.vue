<script setup>
import { ref, onMounted } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { api } from '@/data/api'

const props = defineProps({
  tutor: { type: Object, required: true },
  offering: { type: Object, required: true }
})

const emit = defineEmits(['close', 'booked'])

const bookingStore = useBookingStore()

const slots = ref([])
const loadingSlots = ref(false)
const selectedSlotId = ref(null)
const submitting = ref(false)
const error = ref('')
const confirmed = ref(false)

async function loadSlots() {
  loadingSlots.value = true
  try {
    const res = await api.getTutorAvailability(props.tutor.user_id)
    slots.value = res.data || []
  } catch (err) {
    console.error('Failed to load availability:', err)
    slots.value = []
  } finally {
    loadingSlots.value = false
  }
}

function selectSlot(slot) {
  if (slot.is_full) return
  selectedSlotId.value = slot.availability_id
}

function slotHours(slot) {
  const [sh, sm] = slot.start_time.split(':').map(Number)
  const [eh, em] = slot.end_time.split(':').map(Number)
  return Math.max(1, Math.round((eh * 60 + em - (sh * 60 + sm)) / 60))
}

function slotPrice(slot) {
  return (props.offering.hourly_rate * slotHours(slot)).toFixed(2)
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-MY', { weekday: 'short', day: 'numeric', month: 'short' })
}

async function submitBooking() {
  error.value = ''
  if (!selectedSlotId.value) {
    error.value = 'Please choose an available slot.'
    return
  }

  submitting.value = true
  try {
    // Slot-based booking: the backend derives time/duration from the slot
    // and auto-accepts if seats remain.
    await bookingStore.createBooking({
      availability_id: selectedSlotId.value,
      skill_id: props.offering.skill_id
    })
    confirmed.value = true
    setTimeout(() => emit('booked'), 900)
  } catch (err) {
    error.value = err.message || 'Booking failed.'
  } finally {
    submitting.value = false
  }
}

onMounted(loadSlots)
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

        <div v-if="confirmed" class="alert alert-success">
          <i class="bi bi-check-circle-fill me-2"></i>Booked &amp; confirmed! Redirecting…
        </div>

        <template v-else>
          <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

          <label class="form-label small">Choose an available slot</label>

          <div v-if="loadingSlots" class="text-muted small py-2">Loading available slots…</div>
          <div v-else-if="!slots.length" class="text-muted small py-2">
            This tutor hasn't set any availability yet.
          </div>

          <div v-else class="slot-list mb-3">
            <button
              v-for="slot in slots"
              :key="slot.availability_id"
              type="button"
              class="slot-item d-flex justify-content-between align-items-center w-100 mb-2 p-2 rounded border"
              :class="{
                'border-primary bg-light': selectedSlotId === slot.availability_id,
                'slot-full text-muted': slot.is_full
              }"
              :disabled="slot.is_full"
              @click="selectSlot(slot)"
            >
              <span class="text-start">
                <span class="d-block fw-semibold">
                  {{ formatDate(slot.available_date) }} · {{ slot.start_time.slice(0,5) }}–{{ slot.end_time.slice(0,5) }}
                </span>
                <span class="small">
                  <i :class="slot.type === 'Group' ? 'bi bi-people-fill text-info' : 'bi bi-person-fill text-secondary'" class="me-1"></i>
                  {{ slot.type }}
                  <template v-if="slot.type === 'Group'"> · {{ slot.seats_left }} seat(s) left</template>
                  <template v-else-if="slot.is_full"> · taken</template>
                  <span class="ms-1" :class="slot.mode === 'Online' ? 'text-primary' : 'text-success'">
                    · <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'"></i> {{ slot.mode }}
                  </span>
                </span>
                <span v-if="slot.mode === 'Physical' && slot.location" class="small d-block text-muted">
                  <i class="bi bi-geo-alt me-1"></i>{{ slot.location }}
                </span>
                <span v-if="slot.outcomes" class="small d-block text-muted">
                  <i class="bi bi-bullseye me-1"></i>{{ slot.outcomes }}
                </span>
              </span>
              <span class="fw-bold text-primary-ss">RM{{ slotPrice(slot) }}</span>
            </button>
          </div>

          <p class="text-muted small">
            <i class="bi bi-lightning-charge me-1"></i>
            Booking a slot with free seats is <strong>confirmed instantly</strong>.
          </p>

          <button
            type="button"
            class="btn btn-primary w-100"
            :disabled="submitting || !selectedSlotId"
            @click="submitBooking"
          >
            <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
            {{ submitting ? 'Booking…' : 'Book this slot' }}
          </button>
        </template>
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

.slot-list {
  max-height: 280px;
  overflow-y: auto;
}

.slot-item {
  background: #fff;
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.slot-item.slot-full {
  cursor: not-allowed;
  background: #f8f9fa;
}
</style>
