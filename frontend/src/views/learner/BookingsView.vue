<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/data/api'
import ReviewModal from '@/components/review/ReviewModal.vue'
import { downloadBookingIcs } from '@/utils/ics'

const bookingStore = useBookingStore()
const auth = useAuthStore()

const statusFilter = ref('All')
const statuses = ['All', 'Pending', 'Accepted', 'Completed', 'Cancelled']

// "My Classes" is mode-aware: in Learner mode it's the sessions I booked;
// in Tutor mode it's the sessions I teach. The store keeps these in two
// separate collections so they never clash.
const list = computed(() => (auth.isTutorMode ? bookingStore.tutorBookings : bookingStore.learnerBookings))
const loading = computed(() => (auth.isTutorMode ? bookingStore.loadingTutor : bookingStore.loadingLearner))

function load() {
  return auth.isTutorMode ? bookingStore.fetchAsTutor() : bookingStore.fetchAsLearner()
}

onMounted(load)

// Re-fetch the right list whenever the user switches hat while on this page.
watch(() => auth.activeMode, load)

const filteredBookings = computed(() => {
  if (statusFilter.value === 'All') return list.value
  return list.value.filter((b) => b.status === statusFilter.value)
})

function slotType(b) {
  if (!b.slot_capacity) return null
  return b.slot_capacity > 1 ? 'Group' : 'Solo'
}

// A session is reviewable once its end time has passed (and it actually ran).
function sessionEnded(b) {
  const end = new Date(b.booking_date).getTime() + (b.duration || 1) * 3600 * 1000
  return end < Date.now()
}
function canReview(b) {
  return sessionEnded(b) && b.status !== 'Pending' && b.status !== 'Cancelled'
}

// ---- Tutor actions ----
const updatingId = ref(null)
async function respond(bookingId, status) {
  updatingId.value = bookingId
  try {
    await bookingStore.updateStatus(bookingId, status)
  } finally {
    updatingId.value = null
  }
}

const recordingDrafts = ref({})
const savingRecording = ref(null)
async function saveRecording(b) {
  const url = (recordingDrafts.value[b.booking_id] ?? b.recording_url ?? '').trim()
  savingRecording.value = b.booking_id
  try {
    await api.setBookingRecording(b.booking_id, url)
    await bookingStore.fetchAsTutor()
  } catch (err) {
    alert(err.message || 'Could not save recording link.')
  } finally {
    savingRecording.value = null
  }
}

// ---- Learner review ----
const reviewingBooking = ref(null)
function openReview(b) {
  reviewingBooking.value = b
}
function handleReviewSaved() {
  reviewingBooking.value = null
  bookingStore.fetchAsLearner()
}
async function deleteReview(b) {
  if (!confirm('Delete your review for this session?')) return
  try {
    await api.deleteReview(b.review_id)
    bookingStore.fetchAsLearner()
  } catch (err) {
    alert(err.message || 'Could not delete review.')
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
    <h3 class="fw-bold mb-1">My Classes</h3>
    <p class="text-muted">
      {{ auth.isTutorMode ? 'Sessions you teach' : 'Sessions you booked' }}
    </p>

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

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="filteredBookings.length" class="table-responsive">
      <table class="table align-middle bg-white shadow-sm rounded">
        <thead>
          <tr class="text-muted small">
            <th>{{ auth.isTutorMode ? 'Student' : 'Tutor' }}</th>
            <th>Skill</th>
            <th>Date & Time</th>
            <th>Duration</th>
            <th>Amount</th>
            <th>Status</th>
            <th>{{ auth.isTutorMode ? 'Manage' : 'Review' }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="b in filteredBookings" :key="b.booking_id">
            <td>{{ auth.isTutorMode ? b.learner_name : b.tutor_name }}</td>
            <td>
              {{ b.skill_name }}
              <span v-if="slotType(b)" class="badge ms-1" :class="slotType(b) === 'Group' ? 'bg-info text-dark' : 'bg-secondary'">
                <i :class="slotType(b) === 'Group' ? 'bi bi-people-fill' : 'bi bi-person-fill'" class="me-1"></i>{{ slotType(b) }}
              </span>
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

            <!-- Manage / Review column -->
            <td>
              <!-- TUTOR: drive the session through its lifecycle -->
              <template v-if="auth.isTutorMode">
                <div v-if="b.status === 'Pending'" class="d-flex gap-1">
                  <button class="btn btn-success btn-sm" :disabled="updatingId === b.booking_id" @click="respond(b.booking_id, 'Accepted')">Accept</button>
                  <button class="btn btn-outline-danger btn-sm" :disabled="updatingId === b.booking_id" @click="respond(b.booking_id, 'Cancelled')">Decline</button>
                </div>
                <button
                  v-else-if="b.status === 'Accepted'"
                  class="btn btn-primary btn-sm"
                  :disabled="updatingId === b.booking_id"
                  @click="respond(b.booking_id, 'Completed')"
                >
                  Mark completed
                </button>
                <div v-else-if="b.status === 'Completed'" class="input-group input-group-sm" style="min-width: 220px">
                  <input
                    :value="recordingDrafts[b.booking_id] ?? b.recording_url ?? ''"
                    type="url"
                    class="form-control"
                    placeholder="Recording link"
                    @input="recordingDrafts[b.booking_id] = $event.target.value"
                  />
                  <button class="btn btn-outline-primary" :disabled="savingRecording === b.booking_id" @click="saveRecording(b)">
                    {{ savingRecording === b.booking_id ? '...' : 'Save' }}
                  </button>
                </div>
                <span v-else class="text-muted small">—</span>
              </template>

              <!-- LEARNER: review once the session has ended -->
              <template v-else>
                <template v-if="canReview(b)">
                  <div v-if="b.review_id" class="d-flex align-items-center gap-2">
                    <span class="text-warning small">
                      <i v-for="n in 5" :key="n" class="bi" :class="n <= b.review_rating ? 'bi-star-fill' : 'bi-star'"></i>
                    </span>
                    <button class="btn btn-sm btn-outline-secondary" @click="openReview(b)">Edit</button>
                    <button class="btn btn-sm btn-outline-danger" @click="deleteReview(b)">Delete</button>
                  </div>
                  <button v-else class="btn btn-sm btn-primary" @click="openReview(b)">
                    <i class="bi bi-star me-1"></i>Review &amp; rate
                  </button>
                </template>
                <span v-else class="text-muted small">—</span>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-calendar-x" style="font-size: 2rem"></i>
      <p class="mt-2">
        <template v-if="statusFilter !== 'All'">No {{ statusFilter.toLowerCase() }} classes.</template>
        <template v-else-if="auth.isTutorMode">No one has booked your sessions yet. Add availability in your Tutor Dashboard.</template>
        <template v-else>You haven't booked any sessions yet.</template>
      </p>
      <router-link v-if="!auth.isTutorMode" to="/marketplace" class="btn btn-primary btn-sm">Find a tutor</router-link>
      <router-link v-else to="/tutor-dashboard" class="btn btn-primary btn-sm">Go to Tutor Dashboard</router-link>
    </div>

    <ReviewModal
      v-if="reviewingBooking"
      :booking="reviewingBooking"
      @close="reviewingBooking = null"
      @saved="handleReviewSaved"
    />
  </div>
</template>
