<script setup>
import { ref, computed, onMounted } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { api } from '@/data/api'
import ReviewModal from '@/components/review/ReviewModal.vue'
import { downloadBookingIcs } from '@/utils/ics'

const bookingStore = useBookingStore()

const statusFilter = ref('All')
const statuses = ['All', 'Pending', 'Accepted', 'Completed', 'Cancelled']

// Review modal state (learners only)
const reviewingBooking = ref(null)

onMounted(async () => {
  // "My Bookings" is always the learner's view — what I booked — regardless
  // of which mode (hat) I'm currently wearing.
  await bookingStore.fetchAsLearner()
  promptForPendingReview()
})

// Forced review prompt (§6.1.5): once a session is Completed and hasn't
// been reviewed, automatically open the review modal for it.
function promptForPendingReview() {
  const needsReview = bookingStore.learnerBookings.find(
    (b) => b.status === 'Completed' && !b.review_id
  )
  if (needsReview) reviewingBooking.value = needsReview
}

function openReview(booking) {
  reviewingBooking.value = booking
}

function handleReviewSaved() {
  reviewingBooking.value = null
  bookingStore.fetchAsLearner()
}

async function deleteReview(booking) {
  if (!confirm('Delete your review for this session?')) return
  try {
    await api.deleteReview(booking.review_id)
    bookingStore.fetchAsLearner()
  } catch (err) {
    alert(err.message || 'Could not delete review.')
  }
}

const filteredBookings = computed(() => {
  if (statusFilter.value === 'All') return bookingStore.learnerBookings
  return bookingStore.learnerBookings.filter((b) => b.status === statusFilter.value)
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

    <div v-if="bookingStore.loadingLearner" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="filteredBookings.length" class="table-responsive">
      <table class="table align-middle bg-white shadow-sm rounded">
        <thead>
          <tr class="text-muted small">
            <th>Tutor</th>
            <th>Skill</th>
            <th>Date & Time</th>
            <th>Duration</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Review</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in filteredBookings" :key="b.booking_id">
            <td>{{ b.tutor_name }}</td>
            <td>
              {{ b.skill_name }}
              <template v-if="b.slot_mode && b.status !== 'Cancelled'">
                <span class="badge ms-1" :class="b.slot_mode === 'Online' ? 'bg-primary' : 'bg-success'">
                  <i :class="b.slot_mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ b.slot_mode }}
                </span>
                <a v-if="b.slot_mode === 'Online' && b.meeting_link" :href="b.meeting_link" target="_blank" rel="noopener" class="small d-block">
                  <i class="bi bi-box-arrow-up-right me-1"></i>Join meeting
                </a>
                <span v-else-if="b.slot_mode === 'Physical' && b.slot_location" class="small d-block text-muted">
                  <i class="bi bi-geo-alt me-1"></i>{{ b.slot_location }}
                </span>
                <span v-if="b.slot_outcomes" class="small d-block text-muted">
                  <i class="bi bi-bullseye me-1"></i>{{ b.slot_outcomes }}
                </span>
                <span v-if="b.slot_resources" class="small d-block text-muted">
                  <i class="bi bi-link-45deg me-1"></i>{{ b.slot_resources }}
                </span>
              </template>
            </td>
            <td class="small">
              {{ formatDate(b.booking_date) }}
              <button
                v-if="b.status === 'Accepted' || b.status === 'Completed'"
                class="btn btn-link btn-sm p-0 ms-1"
                title="Add to calendar (.ics)"
                @click="downloadBookingIcs(b)"
              >
                <i class="bi bi-calendar-plus"></i>
              </button>
            </td>
            <td>{{ b.duration }}h</td>
            <td>RM{{ b.total_amount.toFixed(2) }}</td>
            <td>
              <span :class="statusClass(b.status)">{{ b.status }}</span>
              <a
                v-if="b.recording_url"
                :href="b.recording_url"
                target="_blank"
                rel="noopener"
                class="d-block small mt-1"
                title="Watch session recording"
              >
                <i class="bi bi-camera-video me-1"></i>Watch recording
              </a>
            </td>
            <td>
              <!-- Reviews only make sense once a session is Completed -->
              <template v-if="b.status === 'Completed'">
                <div v-if="b.review_id" class="d-flex align-items-center gap-2">
                  <span class="text-warning small">
                    <i v-for="n in 5" :key="n" class="bi" :class="n <= b.review_rating ? 'bi-star-fill' : 'bi-star'"></i>
                  </span>
                  <button class="btn btn-sm btn-outline-secondary" @click="openReview(b)">Edit</button>
                  <button class="btn btn-sm btn-outline-danger" @click="deleteReview(b)">Delete</button>
                </div>
                <button v-else class="btn btn-sm btn-primary" @click="openReview(b)">
                  <i class="bi bi-star me-1"></i>Leave a review
                </button>
              </template>
              <span v-else class="text-muted small">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-calendar-x" style="font-size: 2rem"></i>
      <p class="mt-2">
        {{ statusFilter === 'All'
            ? "You haven't booked any sessions yet."
            : `No ${statusFilter.toLowerCase()} bookings.` }}
      </p>
      <router-link to="/marketplace" class="btn btn-primary btn-sm">
        Find a tutor
      </router-link>
    </div>

    <ReviewModal
      v-if="reviewingBooking"
      :booking="reviewingBooking"
      @close="reviewingBooking = null"
      @saved="handleReviewSaved"
    />
  </div>
</template>