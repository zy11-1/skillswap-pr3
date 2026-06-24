<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useBookingStore } from '@/stores/booking'

const auth = useAuthStore()
const bookingStore = useBookingStore()

const statusFilter = ref('All')
const statuses = ['All', 'Pending', 'Accepted', 'Completed', 'Cancelled']

onMounted(() => {
  bookingStore.fetchBookings()
})

const filteredBookings = computed(() => {
  if (statusFilter.value === 'All') return bookingStore.bookings
  return bookingStore.bookings.filter((b) => b.status === statusFilter.value)
})

function statusClass(status) {
  return `status-pill status-${status.toLowerCase()}`
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleString('en-MY', {
    dateStyle: 'medium',
    timeStyle: 'short'
  })
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">My Bookings</h3>
    <p class="text-muted">Track the status of your tutoring sessions</p>

    <div class="d-flex flex-wrap gap-2 mb-4">
      <button
        v-for="s in statuses"
        :key="s"
        class="btn btn-sm"
        :class="statusFilter === s ? 'btn-primary' : 'btn-outline-secondary'"
        @click="statusFilter = s"
      >
        {{ s }}
      </button>
    </div>

    <div v-if="bookingStore.loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="filteredBookings.length" class="table-responsive">
      <table class="table align-middle bg-white shadow-sm rounded">
        <thead>
          <tr class="text-muted small">
            <th>{{ auth.isTutor ? 'Learner' : 'Tutor' }}</th>
            <th>Skill</th>
            <th>Date & Time</th>
            <th>Duration</th>
            <th>Amount</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in filteredBookings" :key="b.booking_id">
            <td>{{ auth.isTutor ? b.learner_name : b.tutor_name }}</td>
            <td>{{ b.skill_name }}</td>
            <td class="small">{{ formatDate(b.booking_date) }}</td>
            <td>{{ b.duration }}h</td>
            <td>RM{{ b.total_amount.toFixed(2) }}</td>
            <td><span :class="statusClass(b.status)">{{ b.status }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-calendar-x" style="font-size: 2rem"></i>
      <p class="mt-2">No bookings in this category yet.</p>
      <router-link to="/marketplace" class="btn btn-primary btn-sm">
        Browse Tutors
      </router-link>
    </div>
  </div>
</template>