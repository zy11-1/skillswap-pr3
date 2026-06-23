<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/data/api'

const pendingTutors = ref([])
const allUsers = ref([])
const loading = ref(true)
const verifyingId = ref(null)

async function loadData() {
  loading.value = true
  try {
    const [pendingRes, usersRes] = await Promise.all([
      api.getPendingVerifications(),
      api.getAllUsers()
    ])
    pendingTutors.value = pendingRes.data
    allUsers.value = usersRes.data
  } finally {
    loading.value = false
  }
}

onMounted(loadData)

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
