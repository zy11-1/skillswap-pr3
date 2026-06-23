<script setup>
import { onMounted, ref } from 'vue'
import { useBookingStore } from '@/stores/booking'

const bookingStore = useBookingStore()
const updatingId = ref(null)

onMounted(() => {
  bookingStore.fetchBookings()
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

    <div v-if="bookingStore.loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="bookingStore.bookings.length" class="row g-3">
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

    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-inbox" style="font-size: 2rem"></i>
      <p class="mt-2">No booking requests yet.</p>
    </div>
  </div>
</template>
