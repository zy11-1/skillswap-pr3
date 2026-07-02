<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useBookingStore } from '@/stores/booking'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/data/api'
import ReviewModal from '@/components/review/ReviewModal.vue'
import TipBanner from '@/components/TipBanner.vue'
import { downloadBookingIcs } from '@/utils/ics'

const bookingStore = useBookingStore()
const auth = useAuthStore()
const router = useRouter()

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

// Refresh when the tab regains focus, so e.g. a tutor who books elsewhere and
// comes back sees the new request without a manual reload.
function refreshOnFocus() {
  if (document.visibilityState === 'visible') load()
}
onMounted(() => {
  window.addEventListener('focus', refreshOnFocus)
  document.addEventListener('visibilitychange', refreshOnFocus)
})
onUnmounted(() => {
  window.removeEventListener('focus', refreshOnFocus)
  document.removeEventListener('visibilitychange', refreshOnFocus)
})

const filteredBookings = computed(() => {
  if (statusFilter.value === 'All') return list.value
  return list.value.filter((b) => b.status === statusFilter.value)
})

// Tutor cards: completed classes on the same slot collapse into ONE card
// listing everyone who took it; everything else stays one card per booking.
const tutorCards = computed(() => {
  const cards = []
  const groups = {}
  for (const b of filteredBookings.value) {
    if (b.status === 'Completed' && b.availability_id) {
      let g = groups[b.availability_id]
      if (!g) {
        g = { key: `slot-${b.availability_id}`, rep: b, students: [] }
        groups[b.availability_id] = g
        cards.push(g)
      }
      g.students.push(b)
    } else {
      cards.push({ key: `bk-${b.booking_id}`, rep: b, students: [b] })
    }
  }
  return cards
})

// Quick chat with a student — jumps straight into the message thread.
function messageStudent(b) {
  router.push({ path: '/messages', query: { to: b.learner_id, name: b.learner_name } })
}
function openClassPage(b) {
  if (b.availability_id) router.push(`/class/${b.availability_id}`)
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

// Learner responds to a tutor's time change (accept = keep at new time, refund
// if shorter; reject = full refund + cancel).
async function respondTime(b, accept) {
  updatingId.value = b.booking_id
  try {
    await api.respondTimeChange(b.booking_id, accept)
    await load()
  } catch (err) {
    alert(err.message || 'Could not respond to the time change.')
  } finally {
    updatingId.value = null
  }
}
function newTimeLabel(b) {
  if (!b.slot_date) return ''
  return `${b.slot_date} ${(b.slot_start || '').slice(0, 5)}–${(b.slot_end || '').slice(0, 5)}`
}

// Tutor can refine the class topic / "what you'll cover" right from the card.
// Reuses the syllabus endpoint, which updates the shared slot for everyone.
const topicDrafts = ref({})
const savingTopic = ref(null)
async function saveTopic(b) {
  const text = (topicDrafts.value[b.booking_id] ?? b.slot_topics ?? '').trim()
  if (!text) return
  savingTopic.value = b.booking_id
  try {
    await api.setSyllabus(b.availability_id, text)
    await bookingStore.fetchAsTutor()
  } catch (err) {
    alert(err.message || 'Could not update the topic.')
  } finally {
    savingTopic.value = null
  }
}

const recordingDrafts = ref({})
const savingRecording = ref(null)
// Saves the recording link to every booking in the card (a grouped completed
// class shares one recording across all its students).
async function saveRecording(card) {
  const rep = card.rep
  const url = (recordingDrafts.value[rep.booking_id] ?? rep.recording_url ?? '').trim()
  savingRecording.value = rep.booking_id
  try {
    for (const s of card.students) {
      await api.setBookingRecording(s.booking_id, url)
    }
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

// ---- Dispute reporting ----
const disputingId = ref(null)      // which booking has the form open
const disputeReason = ref('')
const submittingDispute = ref(null)

function openDisputeForm(bookingId) {
  disputingId.value = bookingId
  disputeReason.value = ''
}
function cancelDispute() {
  disputingId.value = null
  disputeReason.value = ''
}
async function submitDispute(b) {
  if (!disputeReason.value.trim()) return
  submittingDispute.value = b.booking_id
  try {
    await api.submitDispute(b.booking_id, disputeReason.value.trim())
    b.dispute_status = 'open'
    disputingId.value = null
    disputeReason.value = ''
  } catch (err) {
    alert(err.message || 'Could not submit dispute.')
  } finally {
    submittingDispute.value = null
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

    <TipBanner v-if="auth.isTutorMode" tip-id="my-classes-tutor">
      Accept or decline requests here — declines refund the student instantly. Classes complete
      themselves when they end (refunds and your payout are automatic). Use the chat icon next to a
      student's name to message them, or "Class page" for topics, materials, and group messages.
    </TipBanner>
    <TipBanner v-else tip-id="my-classes-learner">
      Your booked classes live here. Once a class ends you can review the tutor, and if it filled up
      you're automatically refunded down to the final price. Use "Report issue" if something went wrong
      — an admin will step in.
    </TipBanner>

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

    <div v-else-if="filteredBookings.length">
      <!-- TUTOR view: roomy cards; completed classes group all their students -->
      <div v-if="auth.isTutorMode" class="d-flex flex-column gap-3">
        <div v-for="card in tutorCards" :key="card.key" class="card border-0 shadow-sm tutor-class-card">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-start mb-2">
              <div>
                <!-- One student per card while live; everyone together once completed -->
                <div v-for="s in card.students" :key="s.booking_id" class="d-flex align-items-center gap-2 mb-1">
                  <h6 class="mb-0 fw-bold"><i class="bi bi-person-circle me-1 text-primary-ss"></i>{{ s.learner_name }}</h6>
                  <button
                    class="btn btn-outline-primary btn-sm py-0 px-1"
                    title="Message this student"
                    @click="messageStudent(s)"
                  ><i class="bi bi-chat-dots"></i></button>
                </div>
                <span class="small text-muted">{{ card.rep.skill_name }}</span>
                <span v-if="card.students.length > 1" class="badge bg-info-subtle text-info-emphasis ms-1">
                  {{ card.students.length }} students
                </span>
              </div>
              <span :class="statusClass(card.rep.status)">{{ card.rep.status }}</span>
            </div>
            <div class="d-flex flex-wrap gap-3 small text-muted mb-3 align-items-center">
              <span><i class="bi bi-clock me-1"></i>{{ formatDate(card.rep.booking_date) }} · {{ card.rep.duration }}h</span>
              <span v-if="card.rep.slot_mode" :class="card.rep.slot_mode === 'Online' ? 'text-primary' : 'text-success'">
                <i :class="card.rep.slot_mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ card.rep.slot_mode }}
                <template v-if="card.rep.slot_mode === 'Physical' && card.rep.slot_location"> · {{ card.rep.slot_location }}</template>
              </span>
              <span class="fw-semibold text-dark"><i class="bi bi-wallet2 me-1"></i>RM{{ Number(card.rep.total_amount).toFixed(2) }}</span>
              <a v-if="card.rep.slot_mode === 'Online' && card.rep.meeting_link" :href="card.rep.meeting_link" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right me-1"></i>Join meeting
              </a>
              <button
                v-if="card.rep.status === 'Accepted' || card.rep.status === 'Completed'"
                class="btn btn-link btn-sm p-0"
                title="Add to calendar (.ics)"
                @click="downloadBookingIcs(card.rep)"
              ><i class="bi bi-calendar-plus me-1"></i>Add to calendar</button>
              <button
                v-if="card.rep.availability_id"
                class="btn btn-link btn-sm p-0"
                title="Open the class page"
                @click="openClassPage(card.rep)"
              ><i class="bi bi-box-arrow-up-right me-1"></i>Class page</button>
            </div>

            <!-- Lifecycle actions -->
            <div v-if="card.rep.status === 'Pending'" class="d-flex gap-2">
              <button class="btn btn-success btn-sm" :disabled="updatingId === card.rep.booking_id" @click="respond(card.rep.booking_id, 'Accepted')">
                <i class="bi bi-check-lg me-1"></i>Accept
              </button>
              <button class="btn btn-outline-danger btn-sm" :disabled="updatingId === card.rep.booking_id" @click="respond(card.rep.booking_id, 'Cancelled')">
                <i class="bi bi-x-lg me-1"></i>Decline
              </button>
            </div>
            <p v-else-if="card.rep.status === 'Accepted'" class="small text-muted mb-0">
              <i class="bi bi-magic me-1"></i>Completes automatically when the class ends — refunds and your payout settle themselves.
            </p>
            <div v-else-if="card.rep.status === 'Completed'">
              <label class="form-label small mb-1 text-muted">Session recording link (shared with everyone in this class)</label>
              <div class="input-group input-group-sm" style="max-width: 420px">
                <input
                  :value="recordingDrafts[card.rep.booking_id] ?? card.rep.recording_url ?? ''"
                  type="url"
                  class="form-control"
                  placeholder="https://… (Zoom/Meet recording)"
                  @input="recordingDrafts[card.rep.booking_id] = $event.target.value"
                />
                <button class="btn btn-outline-primary" :disabled="savingRecording === card.rep.booking_id" @click="saveRecording(card)">
                  {{ savingRecording === card.rep.booking_id ? '...' : 'Save' }}
                </button>
              </div>
              <a v-if="card.rep.recording_url" :href="card.rep.recording_url" target="_blank" rel="noopener" class="small d-inline-block mt-1">
                <i class="bi bi-camera-video me-1"></i>Watch current recording
              </a>
            </div>

            <!-- Edit the class topic / agenda inline (slot-based classes only) -->
            <div v-if="card.rep.availability_id && (card.rep.status === 'Accepted' || card.rep.status === 'Completed')" class="mt-3 pt-2 border-top">
              <label class="form-label small text-muted mb-1">
                <i class="bi bi-bookmark me-1"></i>Topic / what you'll cover
              </label>
              <div class="input-group input-group-sm" style="max-width: 520px">
                <input
                  :value="topicDrafts[card.rep.booking_id] ?? card.rep.slot_topics ?? ''"
                  type="text"
                  class="form-control"
                  placeholder="e.g. Vue.js — Part 2: components & props"
                  @input="topicDrafts[card.rep.booking_id] = $event.target.value"
                  @keyup.enter="saveTopic(card.rep)"
                />
                <button class="btn btn-outline-primary" :disabled="savingTopic === card.rep.booking_id" @click="saveTopic(card.rep)">
                  {{ savingTopic === card.rep.booking_id ? '...' : 'Update topic' }}
                </button>
              </div>
              <span class="text-muted" style="font-size:.7rem">Updates this class for everyone enrolled.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- LEARNER view: compact table -->
      <div v-else class="table-responsive">
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
            <td>RM{{ Number(b.total_amount).toFixed(2) }}</td>
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

              <!-- LEARNER: respond to a time change, else review once ended -->
              <template v-else>
                <div v-if="b.change_pending" class="time-change-box">
                  <div class="small text-warning mb-1">
                    <i class="bi bi-clock-history me-1"></i>Tutor moved this to <strong>{{ newTimeLabel(b) }}</strong>
                  </div>
                  <div class="d-flex gap-1">
                    <button class="btn btn-success btn-sm" :disabled="updatingId === b.booking_id" @click="respondTime(b, true)">Accept</button>
                    <button class="btn btn-outline-danger btn-sm" :disabled="updatingId === b.booking_id" @click="respondTime(b, false)">Reject &amp; refund</button>
                  </div>
                </div>
                <template v-else-if="canReview(b)">
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

                <!-- Dispute reporting (learner, non-cancelled bookings only) -->
                <template v-if="b.status !== 'Cancelled'">
                  <div v-if="!b.dispute_status || b.dispute_status === 'none'">
                    <div v-if="disputingId === b.booking_id" class="mt-2">
                      <textarea
                        v-model="disputeReason"
                        class="form-control form-control-sm mb-1"
                        rows="2"
                        maxlength="500"
                        placeholder="Briefly describe the issue (max 500 chars)"
                      ></textarea>
                      <div class="d-flex gap-1">
                        <button
                          class="btn btn-danger btn-sm"
                          :disabled="!disputeReason.trim() || submittingDispute === b.booking_id"
                          @click="submitDispute(b)"
                        >
                          {{ submittingDispute === b.booking_id ? 'Submitting…' : 'Submit' }}
                        </button>
                        <button class="btn btn-outline-secondary btn-sm" @click="cancelDispute">Cancel</button>
                      </div>
                    </div>
                    <button v-else class="btn btn-link btn-sm text-danger p-0 mt-1" @click="openDisputeForm(b.booking_id)">
                      <i class="bi bi-flag me-1"></i>Report issue
                    </button>
                  </div>
                  <span v-else-if="b.dispute_status === 'open'" class="badge bg-warning text-dark mt-1 d-block">
                    <i class="bi bi-clock me-1"></i>Dispute pending review
                  </span>
                  <span v-else-if="b.dispute_status === 'resolved_refund'" class="badge bg-success mt-1 d-block">
                    <i class="bi bi-check-circle me-1"></i>Resolved — refunded
                  </span>
                  <span v-else-if="b.dispute_status === 'resolved_closed'" class="badge bg-secondary mt-1 d-block">
                    <i class="bi bi-x-circle me-1"></i>Dispute closed
                  </span>
                </template>
              </template>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
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

<style scoped>
.tutor-class-card {
  border-left: 4px solid var(--ss-primary) !important;
  border-radius: 10px;
}
</style>
