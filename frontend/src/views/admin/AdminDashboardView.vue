<script setup>
import { ref, onMounted } from 'vue'
import { api, apiBaseUrl } from '@/data/api'

// ── data ──────────────────────────────────────────────────────────────────────
const stats          = ref(null)
const pendingTutors  = ref([])
const allUsers       = ref([])
const docRequests    = ref([])
const meritRequests  = ref([])
const allReviews     = ref([])
const disputes       = ref([])
const allBookings    = ref([])
const loading        = ref(true)

// ── active tab ────────────────────────────────────────────────────────────────
const activeTab = ref('overview')

// ── per-action busy flags ─────────────────────────────────────────────────────
const verifyingId      = ref(null)
const reviewingId      = ref(null)
const updatingUserId   = ref(null)
const deletingUserId   = ref(null)
const deletingReviewId = ref(null)
const resolvingId      = ref(null)


// ── data loading ──────────────────────────────────────────────────────────────
async function loadData() {
  loading.value = true
  try {
    // Use allSettled so one failing endpoint cannot zero-out the whole panel.
    const [statsRes, pendingRes, usersRes, docRes, meritRes, reviewsRes, disputesRes, bookingsRes] =
      await Promise.allSettled([
        api.getAdminStats(),
        api.getPendingVerifications(),
        api.getAllUsers(),
        api.getVerificationRequests(),
        api.getMeritRequests(),
        api.getAdminReviews(),
        api.getAdminDisputes(),
        api.getAdminBookings(),
      ])

    if (statsRes.status     === 'fulfilled') stats.value         = statsRes.value.data
    if (pendingRes.status   === 'fulfilled') pendingTutors.value = pendingRes.value.data
    if (usersRes.status     === 'fulfilled') allUsers.value      = usersRes.value.data
    if (docRes.status       === 'fulfilled') docRequests.value   = docRes.value.data
    if (meritRes.status     === 'fulfilled') meritRequests.value = meritRes.value.data
    if (reviewsRes.status   === 'fulfilled') allReviews.value    = reviewsRes.value.data
    if (disputesRes.status  === 'fulfilled') disputes.value      = disputesRes.value.data
    if (bookingsRes.status  === 'fulfilled') allBookings.value   = bookingsRes.value.data


  } finally {
    loading.value = false
  }
}

onMounted(loadData)

// ── helpers ───────────────────────────────────────────────────────────────────
function docUrl(path) { return path.startsWith('http') ? path : apiBaseUrl + path }

function formatDate(d) {
  return new Date(d).toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' })
}

// ── tutor verification (quick-verify without document) ────────────────────────
async function approve(userId) {
  verifyingId.value = userId
  try {
    await api.verifyTutor(userId)
    pendingTutors.value = pendingTutors.value.filter(u => u.user_id !== userId)
    const user = allUsers.value.find(u => u.user_id === userId)
    if (user) user.is_verified = 1
  } finally {
    verifyingId.value = null
  }
}

// ── document verification ──────────────────────────────────────────────────────
async function reviewDoc(requestId, status) {
  reviewingId.value = requestId
  try {
    await api.reviewVerification(requestId, status)
    const req = docRequests.value.find(r => r.request_id === requestId)
    docRequests.value = docRequests.value.filter(r => r.request_id !== requestId)
    if (status === 'Approved' && req) {
      const user = allUsers.value.find(u => u.user_id === req.user_id)
      if (user) user.is_verified = 1
    }
  } finally {
    reviewingId.value = null
  }
}

// ── merit detail modal ────────────────────────────────────────────────────────
const viewingMerit        = ref(null)
const viewingMeritLoading = ref(false)

async function viewMerit(r) {
  viewingMerit.value        = { ...r, reviews: [] }
  viewingMeritLoading.value = true
  try {
    const res = await api.getAdminMeritDetail(r.merit_request_id)
    viewingMerit.value = res.data
  } catch (err) {
    alert(err.message || 'Could not load application detail.')
    viewingMerit.value = null
  } finally {
    viewingMeritLoading.value = false
  }
}

function forwardMailto(detail) {
  const reviewLines = (detail.reviews || []).slice(0, 5)
    .map(r => `  - ${r.learner_name}: ${'★'.repeat(r.rating)} — "${r.comment || '—'}"`)
    .join('\n')
  const body = [
    `Dear Merit Coordinator,`,
    ``,
    `Please find below the UTM Merit Transfer application for:`,
    `  Name   : ${detail.name}`,
    `  Email  : ${detail.email}`,
    `  Faculty: ${detail.faculty}`,
    ``,
    `PERFORMANCE SNAPSHOT (at time of application)`,
    `  Classes Completed : ${detail.classes_completed}`,
    `  Students Helped   : ${detail.students_helped}`,
    `  Average Rating    : ${detail.avg_rating} / 5.0`,
    `  Review Count      : ${detail.review_count}`,
    ``,
    `RECENT STUDENT REVIEWS`,
    reviewLines || `  No reviews on record.`,
    ``,
    detail.result_link ? `ACADEMIC RESULT / TRANSCRIPT\n  ${detail.result_link}` : '',
    ``,
    `This application has been reviewed on SkillSwap and is forwarded for your approval.`,
    ``,
    `Regards,`,
    `SkillSwap Admin`,
  ].filter(l => l !== undefined).join('\n')

  const subject = `Merit Transfer Application — ${detail.name}`
  return `mailto:merit@utm.my?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
}


// ── user management ───────────────────────────────────────────────────────────
async function toggleSuspend(user) {
  const newActive = user.is_active ? 0 : 1
  updatingUserId.value = user.user_id
  try {
    await api.updateAdminUser(user.user_id, { is_active: newActive })
    user.is_active = newActive
  } catch (err) {
    alert(err.message || 'Could not update user.')
  } finally {
    updatingUserId.value = null
  }
}


async function deleteUser(user) {
  if (!confirm(`Permanently delete "${user.name}"? This removes all their data and cannot be undone.`)) return
  deletingUserId.value = user.user_id
  try {
    await api.deleteAdminUser(user.user_id)
    allUsers.value      = allUsers.value.filter(u => u.user_id !== user.user_id)
    pendingTutors.value = pendingTutors.value.filter(u => u.user_id !== user.user_id)
    if (stats.value) stats.value.total_users = Math.max(0, stats.value.total_users - 1)
  } catch (err) {
    alert(err.message || 'Could not delete user.')
  } finally {
    deletingUserId.value = null
  }
}

// ── review moderation ─────────────────────────────────────────────────────────
async function removeReview(reviewId) {
  if (!confirm('Delete this review? This cannot be undone.')) return
  deletingReviewId.value = reviewId
  try {
    await api.deleteAdminReview(reviewId)
    allReviews.value = allReviews.value.filter(r => r.review_id !== reviewId)
  } catch (err) {
    alert(err.message || 'Could not delete review.')
  } finally {
    deletingReviewId.value = null
  }
}

// ── dispute resolution ────────────────────────────────────────────────────────
async function resolveDispute(bookingId, resolution) {
  const label = resolution === 'refund' ? 'cancel the booking and refund the learner' : 'close the dispute with no changes'
  if (!confirm(`This will ${label}. Proceed?`)) return
  resolvingId.value = bookingId
  try {
    await api.resolveDispute(bookingId, resolution)
    const d = disputes.value.find(x => x.booking_id === bookingId)
    if (d) d.dispute_status = resolution === 'refund' ? 'resolved_refund' : 'resolved_closed'
    // reload so the stats badge and list refresh properly
    await loadData()
  } catch (err) {
    alert(err.message || 'Could not resolve dispute.')
  } finally {
    resolvingId.value = null
  }
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">Admin Control Panel</h3>
    <p class="text-muted mb-3">Platform oversight and moderation</p>

    <!-- Tab nav -->
    <ul class="nav nav-tabs mb-4">
      <li class="nav-item" v-for="tab in [
        { key: 'overview',   label: 'Overview' },
        { key: 'users',      label: 'Users' },
        { key: 'verif',      label: 'Verifications' },
        { key: 'merits',     label: 'Merits' },
        { key: 'content',    label: 'Content' },
        { key: 'disputes',   label: 'Disputes' + (stats && stats.open_disputes ? ` (${stats.open_disputes})` : '') },
        { key: 'bookings',   label: 'All Bookings' },
      ]" :key="tab.key">
        <button
          class="nav-link"
          :class="{ active: activeTab === tab.key }"
          @click="activeTab = tab.key"
        >{{ tab.label }}</button>
      </li>
    </ul>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <template v-else>

      <!-- ═══════════════════════════════════════════════════════ OVERVIEW TAB -->
      <div v-show="activeTab === 'overview'">
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Total Users</p>
                <h4 class="fw-bold mb-0">{{ stats?.total_users ?? 0 }}</h4>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Active Tutors</p>
                <h4 class="fw-bold mb-0">{{ stats?.total_tutors ?? 0 }}</h4>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Total Bookings</p>
                <h4 class="fw-bold mb-0">{{ stats?.total_bookings ?? 0 }}</h4>
                <p class="text-muted small mb-0">{{ stats?.completed_bookings ?? 0 }} completed</p>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Platform Commission</p>
                <h4 class="fw-bold mb-0 text-success">RM{{ (stats?.platform_commission ?? 0).toFixed(2) }}</h4>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Open Disputes</p>
                <h4 class="fw-bold mb-0" :class="stats?.open_disputes ? 'text-danger' : ''">
                  {{ stats?.open_disputes ?? 0 }}
                </h4>
              </div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body">
                <p class="text-muted small mb-1">Pending Verifications</p>
                <h4 class="fw-bold mb-0 text-warning">{{ stats?.pending_verif ?? 0 }}</h4>
              </div>
            </div>
          </div>
        </div>

        <h6 class="fw-bold mb-3">Top Skills (by completed sessions)</h6>
        <div v-if="stats?.top_skills?.length" class="card border-0 shadow-sm mb-4">
          <ul class="list-group list-group-flush">
            <li
              v-for="(s, i) in stats.top_skills"
              :key="s.name"
              class="list-group-item d-flex justify-content-between align-items-center"
            >
              <span><span class="text-muted me-2">{{ i + 1 }}.</span>{{ s.name }}</span>
              <span class="badge bg-primary rounded-pill">{{ s.bookings }} sessions</span>
            </li>
          </ul>
        </div>
        <p v-else class="text-muted small">No completed sessions yet.</p>
      </div>

      <!-- ═══════════════════════════════════════════════════════ USERS TAB -->
      <div v-show="activeTab === 'users'">
        <h6 class="fw-bold mb-3">All Users
          <span class="fw-normal text-muted small ms-2">Suspend, change role, or permanently delete accounts</span>
        </h6>
        <div class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small">
                  <th>Name</th>
                  <th>Email</th>
                  <th>Faculty</th>
                  <th>Verified</th>
                  <th>Status</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in allUsers" :key="u.user_id" :class="{ 'table-warning': !u.is_active }">
                  <td>{{ u.name }}</td>
                  <td class="small text-muted">{{ u.email }}</td>
                  <td class="small">{{ u.faculty }}</td>
                  <td>
                    <i v-if="u.is_verified" class="bi bi-check-circle-fill text-success"></i>
                    <i v-else class="bi bi-dash-circle text-muted"></i>
                  </td>
                  <td>
                    <span class="badge" :class="u.is_active ? 'bg-success' : 'bg-secondary'">
                      {{ u.is_active ? 'Active' : 'Suspended' }}
                    </span>
                  </td>
                  <td class="text-end">
                    <template v-if="u.role !== 'admin'">
                      <button
                        class="btn btn-sm me-1"
                        :class="u.is_active ? 'btn-outline-warning' : 'btn-outline-success'"
                        :disabled="updatingUserId === u.user_id"
                        @click="toggleSuspend(u)"
                      >
                        <i class="bi" :class="u.is_active ? 'bi-slash-circle' : 'bi-check-circle'"></i>
                        {{ u.is_active ? 'Suspend' : 'Activate' }}
                      </button>
                      <button
                        class="btn btn-sm btn-outline-danger"
                        :disabled="deletingUserId === u.user_id"
                        @click="deleteUser(u)"
                      >
                        <i class="bi bi-trash"></i>
                      </button>
                    </template>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══════════════════════════════════════════════════ VERIFICATIONS TAB -->
      <div v-show="activeTab === 'verif'">
        <h6 class="fw-bold mb-3">Document Verification Requests</h6>
        <div v-if="docRequests.length" class="card border-0 shadow-sm mb-4">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small">
                  <th>Name</th><th>Faculty</th><th>Document</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in docRequests" :key="r.request_id">
                  <td>{{ r.name }}</td>
                  <td>{{ r.faculty }}</td>
                  <td>
                    <a :href="docUrl(r.document_url)" target="_blank" rel="noopener" class="small">
                      <i class="bi bi-file-earmark-text me-1"></i>View document
                    </a>
                  </td>
                  <td class="text-end">
                    <button class="btn btn-success btn-sm me-1" :disabled="reviewingId === r.request_id" @click="reviewDoc(r.request_id, 'Approved')">
                      <i class="bi bi-check-lg"></i> Approve
                    </button>
                    <button class="btn btn-outline-danger btn-sm" :disabled="reviewingId === r.request_id" @click="reviewDoc(r.request_id, 'Rejected')">
                      Reject
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small mb-4">No document requests awaiting review.</p>

        <h6 class="fw-bold mb-3">Pending Tutor Verifications (no document yet)</h6>
        <div v-if="pendingTutors.length" class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small"><th>Name</th><th>Faculty</th><th>Email</th><th></th></tr>
              </thead>
              <tbody>
                <tr v-for="u in pendingTutors" :key="u.user_id">
                  <td>{{ u.name }}</td>
                  <td>{{ u.faculty }}</td>
                  <td class="small text-muted">{{ u.email }}</td>
                  <td class="text-end">
                    <button class="btn btn-success btn-sm" :disabled="verifyingId === u.user_id" @click="approve(u.user_id)">
                      <i class="bi bi-check-lg"></i> Verify
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small">No pending verifications.</p>
      </div>

      <!-- ═══════════════════════════════════════════════════════ MERITS TAB -->
      <div v-show="activeTab === 'merits'">
        <h6 class="fw-bold mb-3">UTM Merit Transfer Applications</h6>
        <div v-if="meritRequests.length" class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small">
                  <th>Name</th><th>Faculty</th><th>Classes</th><th>Students</th><th>Rating</th><th>Reviews</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in meritRequests" :key="r.merit_request_id">
                  <td>{{ r.name }}</td>
                  <td>{{ r.faculty }}</td>
                  <td>{{ r.classes_completed }}</td>
                  <td>{{ r.students_helped }}</td>
                  <td>{{ r.avg_rating }}★</td>
                  <td>{{ r.review_count }}</td>
                  <td class="text-end">
                    <button class="btn btn-outline-primary btn-sm" @click="viewMerit(r)">
                      <i class="bi bi-eye me-1"></i>View
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small">No merit conversion requests pending.</p>
      </div>

      <!-- ═══════════════════════════════════════════════════════ CONTENT TAB -->
      <div v-show="activeTab === 'content'">
        <h6 class="fw-bold mb-2">All Reviews
          <span class="fw-normal text-muted small ms-2">Delete abusive or fake reviews</span>
        </h6>
        <div v-if="allReviews.length" class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small">
                  <th>Reviewer</th><th>Tutor</th><th>Skill</th><th>Rating</th><th>Comment</th><th>Date</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in allReviews" :key="r.review_id">
                  <td class="small">{{ r.learner_name }}<br><span class="text-muted">{{ r.learner_email }}</span></td>
                  <td class="small">{{ r.tutor_name }}</td>
                  <td class="small">{{ r.skill_name }}</td>
                  <td>
                    <span class="text-warning">
                      <i v-for="n in 5" :key="n" class="bi" :class="n <= r.rating ? 'bi-star-fill' : 'bi-star'"></i>
                    </span>
                  </td>
                  <td class="small" style="max-width:220px">{{ r.comment || '—' }}</td>
                  <td class="small text-muted text-nowrap">{{ formatDate(r.created_at) }}</td>
                  <td class="text-end">
                    <button
                      class="btn btn-sm btn-outline-danger"
                      :disabled="deletingReviewId === r.review_id"
                      @click="removeReview(r.review_id)"
                    >
                      <i class="bi bi-trash"></i> Delete
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small">No reviews on the platform yet.</p>
      </div>

      <!-- ═══════════════════════════════════════════════════════ DISPUTES TAB -->
      <div v-show="activeTab === 'disputes'">
        <h6 class="fw-bold mb-2">Dispute Queue
          <span class="fw-normal text-muted small ms-2">Mediate learner–tutor disputes</span>
        </h6>
        <div v-if="disputes.length" class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle">
              <thead>
                <tr class="text-muted small">
                  <th>Learner</th><th>Tutor</th><th>Skill</th><th>Date</th><th>Amount</th><th>Reason</th><th>Status</th><th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="d in disputes" :key="d.booking_id">
                  <td class="small">{{ d.learner_name }}<br><span class="text-muted">{{ d.learner_email }}</span></td>
                  <td class="small">{{ d.tutor_name }}</td>
                  <td class="small">{{ d.skill_name }}</td>
                  <td class="small text-nowrap">{{ formatDate(d.booking_date) }}</td>
                  <td class="small">RM{{ d.total_amount.toFixed(2) }}</td>
                  <td class="small" style="max-width:200px">{{ d.dispute_reason }}</td>
                  <td>
                    <span v-if="d.dispute_status === 'open'" class="badge bg-warning text-dark">Open</span>
                    <span v-else-if="d.dispute_status === 'resolved_refund'" class="badge bg-success">Refunded</span>
                    <span v-else class="badge bg-secondary">Closed</span>
                  </td>
                  <td class="text-end">
                    <template v-if="d.dispute_status === 'open'">
                      <button
                        class="btn btn-sm btn-success me-1"
                        :disabled="resolvingId === d.booking_id"
                        @click="resolveDispute(d.booking_id, 'refund')"
                      >
                        <i class="bi bi-arrow-counterclockwise"></i> Refund
                      </button>
                      <button
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="resolvingId === d.booking_id"
                        @click="resolveDispute(d.booking_id, 'close')"
                      >
                        Close
                      </button>
                    </template>
                    <span v-else class="text-muted small">Resolved</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small">No disputes have been raised.</p>
      </div>

      <!-- ═══════════════════════════════════════════════════ ALL BOOKINGS TAB -->
      <div v-show="activeTab === 'bookings'">
        <h6 class="fw-bold mb-2">Platform-wide Bookings
          <span class="fw-normal text-muted small ms-2">Most recent 200 — for context when mediating disputes</span>
        </h6>
        <div v-if="allBookings.length" class="card border-0 shadow-sm">
          <div class="table-responsive">
            <table class="table mb-0 align-middle small">
              <thead>
                <tr class="text-muted">
                  <th>#</th><th>Learner</th><th>Tutor</th><th>Skill</th><th>Date</th><th>Amount</th><th>Status</th><th>Dispute</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="b in allBookings" :key="b.booking_id">
                  <td class="text-muted">{{ b.booking_id }}</td>
                  <td>{{ b.learner_name }}</td>
                  <td>{{ b.tutor_name }}</td>
                  <td>{{ b.skill_name }}</td>
                  <td class="text-nowrap">{{ formatDate(b.booking_date) }}</td>
                  <td>RM{{ b.total_amount.toFixed(2) }}</td>
                  <td>
                    <span
                      class="badge"
                      :class="{
                        'bg-warning text-dark': b.status === 'Pending',
                        'bg-primary': b.status === 'Accepted',
                        'bg-success': b.status === 'Completed',
                        'bg-secondary': b.status === 'Cancelled',
                      }"
                    >{{ b.status }}</span>
                  </td>
                  <td>
                    <span v-if="b.dispute_status === 'open'" class="badge bg-warning text-dark">Open</span>
                    <span v-else-if="b.dispute_status === 'resolved_refund'" class="badge bg-success">Refunded</span>
                    <span v-else-if="b.dispute_status === 'resolved_closed'" class="badge bg-secondary">Closed</span>
                    <span v-else class="text-muted">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <p v-else class="text-muted small">No bookings yet.</p>
      </div>

    </template>
  </div>

  <!-- ══════════════════════════════════ MERIT DETAIL MODAL -->
  <div v-if="viewingMerit" class="modal d-block" tabindex="-1" style="background:rgba(0,0,0,.5)" @click.self="viewingMerit = null">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Merit Application — {{ viewingMerit.name }}</h5>
          <button type="button" class="btn-close" @click="viewingMerit = null"></button>
        </div>

        <div class="modal-body">
          <div v-if="viewingMeritLoading" class="text-center py-5">
            <div class="spinner-border text-primary-ss"></div>
          </div>
          <template v-else>

            <!-- Student info -->
            <div class="mb-3 d-flex align-items-center gap-3">
              <img v-if="viewingMerit.photo_url" :src="viewingMerit.photo_url" class="rounded-circle" width="56" height="56" alt="">
              <div>
                <div class="fw-semibold">{{ viewingMerit.name }}</div>
                <div class="small text-muted">{{ viewingMerit.email }}</div>
                <div class="small text-muted">{{ viewingMerit.faculty }}{{ viewingMerit.year_of_study ? ' · ' + viewingMerit.year_of_study : '' }}</div>
              </div>
            </div>

            <!-- Performance snapshot -->
            <h6 class="fw-bold mb-2">Performance Snapshot <span class="fw-normal text-muted small">(at time of application)</span></h6>
            <div class="row g-2 mb-4">
              <div class="col-6 col-md-3">
                <div class="card border-0 bg-light text-center p-2">
                  <p class="small text-muted mb-0">Classes</p>
                  <h4 class="fw-bold mb-0">{{ viewingMerit.classes_completed }}</h4>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="card border-0 bg-light text-center p-2">
                  <p class="small text-muted mb-0">Students</p>
                  <h4 class="fw-bold mb-0">{{ viewingMerit.students_helped }}</h4>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="card border-0 bg-light text-center p-2">
                  <p class="small text-muted mb-0">Avg Rating</p>
                  <h4 class="fw-bold mb-0 text-warning">{{ viewingMerit.avg_rating }}★</h4>
                </div>
              </div>
              <div class="col-6 col-md-3">
                <div class="card border-0 bg-light text-center p-2">
                  <p class="small text-muted mb-0">Reviews</p>
                  <h4 class="fw-bold mb-0">{{ viewingMerit.review_count }}</h4>
                </div>
              </div>
            </div>

            <!-- Academic result link -->
            <div v-if="viewingMerit.result_link" class="mb-4">
              <h6 class="fw-bold mb-2">Academic Result / Transcript</h6>
              <a :href="viewingMerit.result_link" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-file-earmark-text me-1"></i>View Result
              </a>
            </div>

            <!-- Student reviews -->
            <h6 class="fw-bold mb-2">Student Reviews ({{ viewingMerit.reviews?.length ?? 0 }} shown)</h6>
            <div v-if="viewingMerit.reviews?.length" class="list-group list-group-flush border rounded">
              <div v-for="rev in viewingMerit.reviews" :key="rev.review_id" class="list-group-item">
                <div class="d-flex justify-content-between align-items-center mb-1">
                  <span class="fw-semibold small">{{ rev.learner_name }}</span>
                  <span class="text-warning small">
                    <i v-for="n in 5" :key="n" class="bi" :class="n <= rev.rating ? 'bi-star-fill' : 'bi-star'"></i>
                  </span>
                </div>
                <p class="small text-muted mb-0">{{ rev.comment || '—' }}</p>
              </div>
            </div>
            <p v-else class="text-muted small">No reviews on record for this tutor.</p>

          </template>
        </div>

        <div class="modal-footer">
          <a :href="forwardMailto(viewingMerit)" class="btn btn-primary">
            <i class="bi bi-envelope me-1"></i>Forward to Approver
          </a>
          <button class="btn btn-outline-secondary" @click="viewingMerit = null">Close</button>
        </div>

      </div>
    </div>
  </div>

</template>
