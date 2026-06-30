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

const slots = ref([])
const loadingSlots = ref(false)
const selectedSlotId = ref(null)
const submitting = ref(false)
const error = ref('')
const confirmed = ref(false)
const confirming = ref(false)   // prepay confirmation step

const selectedSlot = computed(() => slots.value.find((s) => s.availability_id === selectedSlotId.value))

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
  if (!slot.bookable) return
  selectedSlotId.value = slot.availability_id
  confirming.value = false
}

function onBookClick() {
  error.value = ''
  if (!selectedSlotId.value) {
    error.value = 'Please choose an available slot.'
    return
  }
  // Every booking is prepay — confirm the charge first.
  if (!confirming.value) {
    confirming.value = true
    return
  }
  submitBooking()
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-MY', { weekday: 'short', day: 'numeric', month: 'short' })
}

async function submitBooking() {
  error.value = ''
  submitting.value = true
  try {
    await bookingStore.createBooking({
      availability_id: selectedSlotId.value,
      skill_id: props.offering.skill_id
    })
    confirmed.value = true
    setTimeout(() => emit('booked'), 1500)
  } catch (err) {
    error.value = err.message || 'Booking failed.'
    confirming.value = false
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
          <h5 class="fw-bold mb-0">Join a group class</h5>
          <button class="btn-close" @click="emit('close')"></button>
        </div>

        <p class="text-muted small">with <strong>{{ tutor.name }}</strong></p>

        <div v-if="confirmed" class="alert alert-success">
          <i class="bi bi-check-circle-fill me-2"></i>
          Request sent — the tutor will review and approve it. You'll be notified. Redirecting…
        </div>

        <template v-else>
          <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

          <label class="form-label small">Choose a session</label>

          <div v-if="loadingSlots" class="text-muted small py-2">Loading available slots…</div>
          <div v-else-if="!slots.length" class="text-muted small py-2">
            This tutor hasn't opened any sessions yet.
          </div>

          <div v-else class="slot-list mb-3">
            <button
              v-for="slot in slots"
              :key="slot.availability_id"
              type="button"
              class="slot-item w-100 mb-2 p-2 rounded border text-start"
              :class="{
                'border-primary bg-light': selectedSlotId === slot.availability_id,
                'slot-disabled text-muted': !slot.bookable
              }"
              :disabled="!slot.bookable"
              @click="selectSlot(slot)"
            >
              <div class="d-flex justify-content-between align-items-start">
                <span class="fw-semibold">
                  {{ formatDate(slot.available_date) }} · {{ slot.start_time.slice(0,5) }}–{{ slot.end_time.slice(0,5) }}
                </span>
                <span class="text-end">
                  <span class="fw-bold text-primary-ss">RM{{ slot.next_price.toFixed(2) }}</span>
                  <span class="d-block text-muted" style="font-size:.65rem">price drops as it fills</span>
                </span>
              </div>

              <!-- Topic state -->
              <div class="small mt-1">
                <template v-if="slot.topic">
                  <span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-bookmark-fill me-1"></i>{{ slot.topic }}</span>
                  <span v-if="slot.awaiting_syllabus" class="badge bg-warning text-dark ms-1">
                    <i class="bi bi-hourglass-split me-1"></i>Tutor finalising details — opens soon
                  </span>
                </template>
                <template v-else>
                  <span class="badge bg-success-subtle text-success-emphasis">
                    <i class="bi bi-stars me-1"></i>Open topic — you'd start it with “{{ offering.skill_name }}”
                  </span>
                </template>
              </div>

              <div v-if="slot.topics_covered" class="small text-muted mt-1">
                <i class="bi bi-card-text me-1"></i>{{ slot.topics_covered }}
              </div>

              <div class="small text-muted mt-1">
                <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ slot.mode }}
                <template v-if="slot.mode === 'Physical' && slot.location"> · {{ slot.location }}</template>
                <span class="ms-2"><i class="bi bi-people me-1"></i>{{ slot.seats_left }} of {{ slot.capacity }} seat(s) left</span>
                <span v-if="slot.i_have_priority" class="badge bg-warning text-dark ms-1">
                  <i class="bi bi-star-fill me-1"></i>Reserved for you
                </span>
              </div>
            </button>
          </div>

          <p class="text-muted small">
            <i class="bi bi-info-circle me-1"></i>
            The first student picks the topic; everyone else joins it. The price drops RM1 for each
            extra student (min RM10/hr) — you’ll be <strong>refunded the difference</strong> if it fills up.
            Every booking waits for the tutor's approval.
          </p>

          <!-- Prepay confirmation -->
          <div v-if="confirming && selectedSlot" class="alert alert-warning py-2 small">
            <i class="bi bi-wallet2 me-1"></i>
            <strong>RM{{ selectedSlot.next_price.toFixed(2) }}</strong> will be deducted from your wallet now
            (refunded if the tutor declines, and partly refunded if the class fills up).
          </div>

          <button
            type="button"
            class="btn btn-primary w-100"
            :disabled="submitting || !selectedSlotId"
            @click="onBookClick"
          >
            <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
            <template v-if="submitting">Booking…</template>
            <template v-else-if="confirming && selectedSlot">Confirm &amp; pay RM{{ selectedSlot.next_price.toFixed(2) }}</template>
            <template v-else-if="selectedSlot">Book — pay RM{{ selectedSlot.next_price.toFixed(2) }} now</template>
            <template v-else>Choose a session</template>
          </button>
          <button v-if="confirming" type="button" class="btn btn-link btn-sm w-100" @click="confirming = false">
            Back
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
  max-width: 440px;
  width: 100%;
}

.slot-list {
  max-height: 320px;
  overflow-y: auto;
}

.slot-item {
  background: #fff;
  cursor: pointer;
  transition: border-color 0.15s ease;
}

.slot-item.slot-disabled {
  cursor: not-allowed;
  background: #f8f9fa;
}
</style>
