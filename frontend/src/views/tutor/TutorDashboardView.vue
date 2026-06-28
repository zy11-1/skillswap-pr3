<script setup>
import { onMounted, ref } from 'vue'
import { useBookingStore } from '@/stores/booking'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/data/api'

const bookingStore = useBookingStore()
const auth = useAuthStore()
const updatingId = ref(null)

// ---- Availability ----
const availability = ref([])
const newSlot = ref({
  available_date: new Date().toISOString().split('T')[0],
  start_time: '09:00',
  end_time: '17:00'
})
const saving = ref(false)
const loadingAvailability = ref(false)

// ---- Skill offerings ----
const mySkills = ref([])
const allSkills = ref([])
const newOffering = ref({ skill_id: '', hourly_rate: 10, level: 'Intermediate', description: '' })
const savingSkill = ref(false)
const skillError = ref('')
const levels = ['Beginner', 'Intermediate', 'Advanced', 'Native']

async function loadSkills() {
  try {
    const [mine, all] = await Promise.all([api.getMySkills(), api.getSkills()])
    mySkills.value = mine.data || []
    allSkills.value = all.data || []
  } catch (err) {
    console.error('Failed to load skills:', err)
  }
}

async function addOffering() {
  skillError.value = ''
  if (!newOffering.value.skill_id || newOffering.value.hourly_rate <= 0 || !newOffering.value.description) {
    skillError.value = 'Pick a skill, set a rate, and add a short description.'
    return
  }
  savingSkill.value = true
  try {
    await api.addMySkill({
      skill_id: Number(newOffering.value.skill_id),
      hourly_rate: Number(newOffering.value.hourly_rate),
      level: newOffering.value.level,
      description: newOffering.value.description
    })
    newOffering.value = { skill_id: '', hourly_rate: 10, level: 'Intermediate', description: '' }
    await loadSkills()
  } catch (err) {
    skillError.value = err.message || 'Could not add skill.'
  } finally {
    savingSkill.value = false
  }
}

async function removeOffering(userSkillId) {
  if (!confirm('Remove this skill offering?')) return
  try {
    await api.deleteMySkill(userSkillId)
    await loadSkills()
  } catch (err) {
    alert(err.message || 'Could not remove skill.')
  }
}

// ---- Verification ----
const verification = ref({ is_verified: 0, request: null })
const verifFile = ref(null)
const verifUploading = ref(false)
const verifError = ref('')

async function loadVerification() {
  try {
    const res = await api.getVerificationStatus()
    verification.value = res.data
  } catch (err) {
    console.error('Failed to load verification status:', err)
  }
}

function onVerifFileChange(e) {
  verifFile.value = e.target.files[0] || null
}

async function uploadDocument() {
  verifError.value = ''
  if (!verifFile.value) {
    verifError.value = 'Choose a file (JPG, PNG or PDF) first.'
    return
  }
  verifUploading.value = true
  try {
    await api.submitVerification(verifFile.value)
    verifFile.value = null
    await loadVerification()
  } catch (err) {
    verifError.value = err.message || 'Upload failed.'
  } finally {
    verifUploading.value = false
  }
}

async function loadAvailability() {
  loadingAvailability.value = true
  try {
    const res = await api.getTutorAvailability(auth.user.user_id)
    availability.value = res.data || []
  } catch (err) {
    console.error('Failed to load availability:', err)
    availability.value = []
  } finally {
    loadingAvailability.value = false
  }
}

async function addSlot() {
  if (!auth.user) {
    console.error('No user logged in')
    return
  }
  saving.value = true
  try {
    const res = await api.addAvailability(newSlot.value)
    availability.value.push({ ...newSlot.value, availability_id: res.data.availability_id })
    // Reset the form
    newSlot.value = { 
      available_date: new Date().toISOString().split('T')[0], 
      start_time: '09:00', 
      end_time: '17:00' 
    }
  } catch (err) {
    console.error('Failed to add slot:', err)
  } finally {
    saving.value = false
  }
}

function removeSlot(id) {
  availability.value = availability.value.filter(s => s.availability_id !== id)
}

onMounted(() => {
  bookingStore.fetchBookings()
  loadAvailability()
  loadSkills()
  loadVerification()
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

    <!-- Bookings -->
    <div v-if="bookingStore.loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="bookingStore.bookings.length" class="row g-3 mb-4">
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

    <div v-else class="text-center py-3 text-muted">
      <i class="bi bi-inbox" style="font-size: 2rem"></i>
      <p class="mt-2">No booking requests yet.</p>
    </div>

    <!-- ============================================================ -->
    <!-- Verification -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-patch-check me-2"></i>Verification
      </div>
      <div class="card-body">
        <div v-if="verification.is_verified" class="alert alert-success py-2 mb-0">
          <i class="bi bi-patch-check-fill me-1"></i>
          Your account is <strong>verified</strong>. Learners see a verified badge on your profile.
        </div>
        <template v-else>
          <p class="text-muted small mb-2">
            Upload a transcript or certificate (JPG, PNG or PDF). An admin will review it and grant
            you a <strong>Verified</strong> badge.
          </p>
          <div v-if="verifError" class="alert alert-danger py-2 small">{{ verifError }}</div>

          <div
            v-if="verification.request && verification.request.status === 'Pending'"
            class="alert alert-info py-2 small mb-2"
          >
            <i class="bi bi-hourglass-split me-1"></i>
            Your document is uploaded and <strong>pending review</strong>.
          </div>
          <div
            v-else-if="verification.request && verification.request.status === 'Rejected'"
            class="alert alert-warning py-2 small mb-2"
          >
            Your previous request was rejected. You can upload a new document.
          </div>

          <div v-if="!(verification.request && verification.request.status === 'Pending')" class="row g-2 align-items-end">
            <div class="col-md-8">
              <input type="file" accept=".jpg,.jpeg,.png,.pdf" class="form-control form-control-sm" @change="onVerifFileChange" />
            </div>
            <div class="col-md-4">
              <button class="btn btn-primary btn-sm w-100" :disabled="verifUploading" @click="uploadDocument">
                {{ verifUploading ? 'Uploading...' : 'Upload for verification' }}
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- My Skill Offerings -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-easel me-2"></i>My Skills &amp; Rates
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Add the skills you want to teach. These appear in the Marketplace so learners can book you.
        </p>

        <div v-if="skillError" class="alert alert-danger py-2 small">{{ skillError }}</div>

        <!-- Existing offerings -->
        <div v-if="mySkills.length" class="mb-3">
          <div
            v-for="s in mySkills"
            :key="s.userskill_id"
            class="d-flex align-items-center justify-content-between bg-light p-2 rounded mb-1"
          >
            <div>
              <strong>{{ s.skill_name }}</strong>
              <span class="badge bg-white text-dark border ms-1">{{ s.level }}</span>
              <span class="text-primary-ss fw-semibold ms-2">RM{{ s.hourly_rate.toFixed(2) }}/hr</span>
              <div class="small text-muted">{{ s.description }}</div>
            </div>
            <button class="btn btn-sm btn-outline-danger" @click="removeOffering(s.userskill_id)">
              <i class="bi bi-trash"></i>
            </button>
          </div>
        </div>
        <p v-else class="text-muted small">You don't offer any skills yet — add one below.</p>

        <!-- Add offering -->
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small">Skill</label>
            <select v-model="newOffering.skill_id" class="form-select form-select-sm">
              <option value="" disabled>Select a skill</option>
              <option v-for="sk in allSkills" :key="sk.skill_id" :value="sk.skill_id">{{ sk.name }}</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Rate (RM/hr)</label>
            <input v-model.number="newOffering.hourly_rate" type="number" min="1" step="0.5" class="form-control form-control-sm" />
          </div>
          <div class="col-md-2">
            <label class="form-label small">Level</label>
            <select v-model="newOffering.level" class="form-select form-select-sm">
              <option v-for="lv in levels" :key="lv" :value="lv">{{ lv }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Description</label>
            <input v-model="newOffering.description" type="text" class="form-control form-control-sm" placeholder="What you cover" />
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100" :disabled="savingSkill" @click="addOffering">
              {{ savingSkill ? '...' : 'Add Skill' }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- Tutor Availability Settings -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-clock me-2"></i>My Availability
      </div>
      <div class="card-body">
        <div class="alert alert-info py-1 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Availability only applies to the <strong>next 7 days</strong>.
          Students will only see and book within this window.
        </div>

        <div v-if="loadingAvailability" class="text-center py-2">
          <div class="spinner-border spinner-border-sm text-primary-ss"></div>
          <span class="ms-2 text-muted small">Loading...</span>
        </div>

        <!-- Existing slots -->
        <div v-if="availability.length" class="mb-3">
          <div
            v-for="slot in availability"
            :key="slot.availability_id"
            class="d-flex align-items-center justify-content-between bg-light p-2 rounded mb-1"
          >
            <span>
              <strong>{{ slot.available_date }}</strong>
              {{ slot.start_time.slice(0,5) }} – {{ slot.end_time.slice(0,5) }}
            </span>
            <button
              class="btn btn-sm btn-outline-danger"
              @click="removeSlot(slot.availability_id)"
            >
              <i class="bi bi-x"></i>
            </button>
          </div>
        </div>
        <p v-else class="text-muted small">No availability set yet.</p>

        <!-- Add new slot -->
        <div class="row g-2 align-items-end">
          <div class="col-4">
            <label class="form-label small">Date</label>
            <input v-model="newSlot.available_date" type="date" class="form-control form-control-sm" />
          </div>
          <div class="col-3">
            <label class="form-label small">Start</label>
            <input v-model="newSlot.start_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-3">
            <label class="form-label small">End</label>
            <input v-model="newSlot.end_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-2">
            <button
              class="btn btn-primary btn-sm w-100"
              :disabled="saving"
              @click="addSlot"
            >
              {{ saving ? '...' : 'Add' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>