<script setup>
import { ref, onMounted } from 'vue'
import { api, apiBaseUrl } from '@/data/api'

const pendingTutors = ref([])
const allUsers = ref([])
const docRequests = ref([])
const loading = ref(true)
const verifyingId = ref(null)
const reviewingId = ref(null)

async function loadData() {
  loading.value = true
  try {
    const [pendingRes, usersRes, docRes] = await Promise.all([
      api.getPendingVerifications(),
      api.getAllUsers(),
      api.getVerificationRequests()
    ])
    pendingTutors.value = pendingRes.data
    allUsers.value = usersRes.data
    docRequests.value = docRes.data
  } finally {
    loading.value = false
  }
}

onMounted(loadData)

function docUrl(path) {
  return apiBaseUrl + path
}

async function reviewDoc(requestId, status) {
  reviewingId.value = requestId
  try {
    await api.reviewVerification(requestId, status)
    const req = docRequests.value.find((r) => r.request_id === requestId)
    docRequests.value = docRequests.value.filter((r) => r.request_id !== requestId)
    if (status === 'Approved' && req) {
      const user = allUsers.value.find((u) => u.user_id === req.user_id)
      if (user) user.is_verified = 1
    }
  } finally {
    reviewingId.value = null
  }
}

async function approve(userId) {
  verifyingId.value = userId
  try {
    await api.verifyTutor(userId)
    pendingTutors.value = pendingTutors.value.filter((u) => u.user_id !== userId)
    const user = allUsers.value.find((u) => u.user_id === userId)
    if (user) user.is_verified = 1
  } finally {
    verifyingId.value = null
  }
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">Admin Control Panel</h3>
    <p class="text-muted">Verify tutors and oversee the platform</p>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <template v-else>
      <!-- Overview cards -->
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="card border-0 shadow-sm">
            <div class="card-body">
              <p class="text-muted small mb-1">Total Users</p>
              <h4 class="fw-bold mb-0">{{ allUsers.length }}</h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card border-0 shadow-sm">
            <div class="card-body">
              <p class="text-muted small mb-1">Tutors</p>
              <h4 class="fw-bold mb-0">{{ allUsers.filter(u => u.role === 'tutor').length }}</h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card border-0 shadow-sm">
            <div class="card-body">
              <p class="text-muted small mb-1">Pending Verifications</p>
              <h4 class="fw-bold mb-0 text-warning">{{ pendingTutors.length }}</h4>
            </div>
          </div>
        </div>
      </div>

      <!-- Document verification requests -->
      <h6 class="fw-bold mb-3">Document Verification Requests</h6>
      <div v-if="docRequests.length" class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
          <table class="table mb-0 align-middle">
            <thead>
              <tr class="text-muted small">
                <th>Name</th>
                <th>Faculty</th>
                <th>Document</th>
                <th class="text-end">Action</th>
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
                  <button
                    class="btn btn-success btn-sm me-1"
                    :disabled="reviewingId === r.request_id"
                    @click="reviewDoc(r.request_id, 'Approved')"
                  >
                    <i class="bi bi-check-lg"></i> Approve
                  </button>
                  <button
                    class="btn btn-outline-danger btn-sm"
                    :disabled="reviewingId === r.request_id"
                    @click="reviewDoc(r.request_id, 'Rejected')"
                  >
                    Reject
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-else class="text-muted small mb-4">No document requests awaiting review.</p>

      <!-- Pending verifications -->
      <h6 class="fw-bold mb-3">Pending Tutor Verifications</h6>
      <div v-if="pendingTutors.length" class="card border-0 shadow-sm mb-4">
        <div class="table-responsive">
          <table class="table mb-0 align-middle">
            <thead>
              <tr class="text-muted small">
                <th>Name</th>
                <th>Faculty</th>
                <th>Email</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in pendingTutors" :key="u.user_id">
                <td>{{ u.name }}</td>
                <td>{{ u.faculty }}</td>
                <td class="small text-muted">{{ u.email }}</td>
                <td class="text-end">
                  <button
                    class="btn btn-success btn-sm"
                    :disabled="verifyingId === u.user_id"
                    @click="approve(u.user_id)"
                  >
                    <i class="bi bi-check-lg"></i> Verify
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p v-else class="text-muted small mb-4">No pending verifications. All caught up!</p>

      <!-- All users -->
      <h6 class="fw-bold mb-3">All Users</h6>
      <div class="card border-0 shadow-sm">
        <div class="table-responsive">
          <table class="table mb-0 align-middle">
            <thead>
              <tr class="text-muted small">
                <th>Name</th>
                <th>Role</th>
                <th>Faculty</th>
                <th>Verified</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in allUsers" :key="u.user_id">
                <td>{{ u.name }}</td>
                <td><span class="badge bg-light text-dark border text-capitalize">{{ u.role }}</span></td>
                <td class="small text-muted">{{ u.faculty }}</td>
                <td>
                  <i v-if="u.is_verified" class="bi bi-check-circle-fill text-success"></i>
                  <i v-else class="bi bi-dash-circle text-muted"></i>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
