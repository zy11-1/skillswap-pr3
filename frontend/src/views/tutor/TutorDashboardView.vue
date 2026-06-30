<script setup>
import { onMounted, ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/data/api'

const auth = useAuthStore()

// ---- Availability ----
const availability = ref([])
function blankSlot() {
  return {
    available_date: new Date().toISOString().split('T')[0],
    start_time: '09:00',
    end_time: '17:00',
    slot_type: 'Solo',   // 'Solo' (capacity 1) or 'Group' (capacity > 1)
    group_capacity: 5,
    mode: 'Physical',    // 'Physical' or 'Online'
    meeting_link: '',
    location: '',
    resources: '',
    outcomes: '',
    visibility: 'Public', // 'Public' (browsable) or 'Private' (invite link only)
    auto_accept: true     // instant confirm vs manual approval
  }
}

function inviteLink(slot) {
  return `${window.location.origin}/slot/${slot.share_token}`
}

async function copyInvite(slot) {
  try {
    await navigator.clipboard.writeText(inviteLink(slot))
    alert('Invite link copied! Share it with the students you want to join this private session.')
  } catch {
    prompt('Copy this invite link:', inviteLink(slot))
  }
}
const newSlot = ref(blankSlot())

// Inline edit / cancel state
const editingId = ref(null)
const editForm = ref({})
const editError = ref('')
const savingEdit = ref(false)
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

// ---- Merit conversion ----
const merits = ref({ merit_points: 0, wallet_balance: 0, rate: 10, requests: [] })
const meritCredits = ref(10)
const meritError = ref('')
const requestingMerit = ref(false)

async function loadMerits() {
  try {
    const res = await api.getMyMerits()
    merits.value = res.data
  } catch (err) {
    console.error('Failed to load merits:', err)
  }
}

const hasPendingMerit = computed(() => merits.value.requests?.some((r) => r.status === 'Pending'))

async function requestMerit() {
  meritError.value = ''
  requestingMerit.value = true
  try {
    await api.requestMeritConversion(Number(meritCredits.value))
    await loadMerits()
  } catch (err) {
    meritError.value = err.message || 'Could not submit request.'
  } finally {
    requestingMerit.value = false
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

const slotError = ref('')

async function addSlot() {
  if (!auth.user) {
    console.error('No user logged in')
    return
  }
  slotError.value = ''
  // Solo = 1 seat; Group = the chosen capacity (min 2).
  const capacity = newSlot.value.slot_type === 'Group' ? Number(newSlot.value.group_capacity) : 1
  if (newSlot.value.slot_type === 'Group' && capacity < 2) {
    slotError.value = 'A group slot needs a capacity of at least 2.'
    return
  }
  saving.value = true
  try {
    await api.addAvailability({
      available_date: newSlot.value.available_date,
      start_time: newSlot.value.start_time,
      end_time: newSlot.value.end_time,
      capacity,
      mode: newSlot.value.mode,
      meeting_link: newSlot.value.meeting_link,
      location: newSlot.value.location,
      resources: newSlot.value.resources,
      outcomes: newSlot.value.outcomes,
      visibility: newSlot.value.visibility,
      auto_accept: newSlot.value.auto_accept ? 1 : 0
    })
    // Reload so the new slot shows its capacity/seats from the server.
    await loadAvailability()
    newSlot.value = blankSlot()
  } catch (err) {
    slotError.value = err.message || 'Failed to add slot.'
  } finally {
    saving.value = false
  }
}

// Open the inline editor for a slot (time is not editable).
function startEdit(slot) {
  editError.value = ''
  editingId.value = slot.availability_id
  editForm.value = {
    capacity: slot.capacity,
    mode: slot.mode || 'Physical',
    meeting_link: slot.meeting_link || '',
    location: slot.location || '',
    resources: slot.resources || '',
    outcomes: slot.outcomes || '',
    visibility: slot.visibility || 'Public',
    auto_accept: slot.auto_accept !== false
  }
}

function cancelEdit() {
  editingId.value = null
}

async function saveEdit(slot) {
  editError.value = ''
  if (Number(editForm.value.capacity) < (slot.seats_taken || 0)) {
    editError.value = `Capacity can't be below the ${slot.seats_taken} seat(s) already booked.`
    return
  }
  savingEdit.value = true
  try {
    await api.updateAvailability(slot.availability_id, {
      capacity: Number(editForm.value.capacity),
      mode: editForm.value.mode,
      meeting_link: editForm.value.meeting_link,
      location: editForm.value.location,
      resources: editForm.value.resources,
      outcomes: editForm.value.outcomes,
      visibility: editForm.value.visibility,
      auto_accept: editForm.value.auto_accept ? 1 : 0
    })
    editingId.value = null
    await loadAvailability()
  } catch (err) {
    editError.value = err.message || 'Could not save changes.'
  } finally {
    savingEdit.value = false
  }
}

// Cancel uses an in-app dialog (not native confirm) so the priority
// checkbox is reliable — browsers suppress back-to-back confirm() popups.
const cancelTarget = ref(null)
const cancelPriority = ref(true)
const cancelling = ref(false)

function cancelSlot(slot) {
  cancelTarget.value = slot
  cancelPriority.value = (slot.seats_taken || 0) > 0
}

async function confirmCancel() {
  const slot = cancelTarget.value
  if (!slot) return
  cancelling.value = true
  try {
    const givePriority = cancelPriority.value && (slot.seats_taken || 0) > 0
    const res = await api.cancelAvailability(slot.availability_id, givePriority)
    cancelTarget.value = null
    await loadAvailability()
    if (res.data?.students_notified) {
      alert(`Session cancelled. ${res.data.students_notified} student(s) notified${givePriority ? ' and given priority for your next slot' : ''}.`)
    }
  } catch (err) {
    alert(err.message || 'Could not cancel the slot.')
  } finally {
    cancelling.value = false
  }
}

async function removeSlot(id) {
  if (!confirm('Remove this availability slot?')) return
  try {
    await api.deleteAvailability(id)
    await loadAvailability()
  } catch (err) {
    alert(err.message || 'Could not remove slot.')
  }
}

onMounted(() => {
  // Booking/session management lives in "My Classes" now; the dashboard is
  // the setup hub (availability, skills, verification, merits).
  loadAvailability()
  loadSkills()
  loadVerification()
  loadMerits()
})
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">Tutor Dashboard</h3>
    <p class="text-muted">
      Set up your skills, availability and verification.
      Manage booked sessions in <router-link to="/bookings">My Classes</router-link>.
    </p>

    <!-- Not-visible warning: a tutor only appears in the marketplace once
         they list at least one skill (availability alone isn't enough). -->
    <div v-if="!mySkills.length" class="alert alert-warning d-flex align-items-start">
      <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
      <div>
        <strong>You're not visible to students yet.</strong>
        Add at least one skill in <strong>My Skills &amp; Rates</strong> below (with an hourly rate)
        to appear in the Marketplace and be searchable. Setting availability slots alone does
        <em>not</em> make you findable — students book a <em>skill</em>.
      </div>
    </div>

    <!-- ============================================================ -->
    <!-- Merit conversion -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-award me-2"></i>University Merit Points
      </div>
      <div class="card-body">
        <div class="d-flex gap-4 mb-3">
          <div>
            <div class="small text-muted">Merit points earned</div>
            <div class="h4 fw-bold mb-0 text-primary-ss">{{ merits.merit_points }}</div>
          </div>
          <div>
            <div class="small text-muted">Credit balance</div>
            <div class="h4 fw-bold mb-0">RM{{ merits.wallet_balance.toFixed(2) }}</div>
          </div>
        </div>
        <p class="text-muted small mb-2">
          Convert earned credits into official university merit points
          ({{ merits.rate }} credits = 1 merit). An admin reviews each request.
        </p>
        <div v-if="meritError" class="alert alert-danger py-2 small">{{ meritError }}</div>
        <div v-if="hasPendingMerit" class="alert alert-info py-2 small mb-2">
          <i class="bi bi-hourglass-split me-1"></i>You have a merit request pending review.
        </div>
        <div v-else class="row g-2 align-items-end">
          <div class="col-md-4">
            <label class="form-label small">Credits to convert</label>
            <input v-model.number="meritCredits" type="number" min="10" step="10" class="form-control form-control-sm" />
          </div>
          <div class="col-md-4">
            <div class="small text-muted">≈ {{ Math.floor(meritCredits / merits.rate) }} merit point(s)</div>
            <button class="btn btn-primary btn-sm w-100 mt-1" :disabled="requestingMerit" @click="requestMerit">
              {{ requestingMerit ? '...' : 'Request conversion' }}
            </button>
          </div>
        </div>

        <div v-if="merits.requests && merits.requests.length" class="mt-3">
          <div class="small text-muted mb-1">Recent requests</div>
          <div v-for="r in merits.requests" :key="r.merit_request_id" class="small">
            RM{{ Number(r.credits_amount).toFixed(2) }} → {{ r.merit_points }} merit(s)
            <span class="badge ms-1" :class="{
              'bg-warning text-dark': r.status === 'Pending',
              'bg-success': r.status === 'Approved',
              'bg-secondary': r.status === 'Rejected'
            }">{{ r.status }}</span>
          </div>
        </div>
      </div>
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

        <div v-if="slotError" class="alert alert-danger py-2 small">{{ slotError }}</div>

        <div v-if="editError" class="alert alert-danger py-2 small">{{ editError }}</div>

        <!-- Existing slots -->
        <div v-if="availability.length" class="mb-3">
          <div v-for="slot in availability" :key="slot.availability_id" class="bg-light p-2 rounded mb-2">
            <div class="d-flex align-items-center justify-content-between">
              <span>
                <strong>{{ slot.available_date }}</strong>
                {{ slot.start_time.slice(0,5) }} – {{ slot.end_time.slice(0,5) }}
                <span class="badge ms-2" :class="slot.type === 'Group' ? 'bg-info text-dark' : 'bg-secondary'">
                  <i :class="slot.type === 'Group' ? 'bi bi-people-fill' : 'bi bi-person-fill'" class="me-1"></i>
                  {{ slot.type }}
                  <template v-if="slot.type === 'Group'"> · {{ slot.seats_taken }}/{{ slot.capacity }} booked</template>
                  <template v-else-if="slot.seats_taken > 0"> · booked</template>
                </span>
                <span class="badge ms-1" :class="slot.mode === 'Online' ? 'bg-primary' : 'bg-success'">
                  <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>
                  {{ slot.mode }}
                </span>
                <span v-if="slot.visibility === 'Private'" class="badge bg-dark ms-1">
                  <i class="bi bi-lock-fill me-1"></i>Private
                </span>
                <span class="badge ms-1" :class="slot.auto_accept ? 'bg-light text-dark border' : 'bg-warning text-dark'">
                  <i :class="slot.auto_accept ? 'bi bi-lightning-charge' : 'bi bi-hand-thumbs-up'" class="me-1"></i>
                  {{ slot.auto_accept ? 'Instant' : 'Approval' }}
                </span>
              </span>
              <span class="d-flex gap-2">
                <button
                  v-if="slot.visibility === 'Private' && slot.share_token"
                  class="btn btn-sm btn-dark"
                  @click="copyInvite(slot)"
                >
                  <i class="bi bi-link-45deg me-1"></i>Copy invite link
                </button>
                <button class="btn btn-sm btn-outline-secondary" @click="startEdit(slot)">
                  <i class="bi bi-pencil me-1"></i>Edit
                </button>
                <!-- Booked slot -> Cancel (notifies students). Empty slot -> Delete. -->
                <button
                  v-if="slot.seats_taken > 0"
                  class="btn btn-sm btn-warning"
                  @click="cancelSlot(slot)"
                >
                  <i class="bi bi-slash-circle me-1"></i>Cancel session
                </button>
                <button
                  v-else
                  class="btn btn-sm btn-outline-danger"
                  @click="removeSlot(slot.availability_id)"
                >
                  <i class="bi bi-trash me-1"></i>Delete
                </button>
              </span>
            </div>

            <!-- Inline editor (time is fixed; everything else editable) -->
            <div v-if="editingId === slot.availability_id" class="mt-2 border-top pt-2">
              <div class="row g-2">
                <div class="col-md-3">
                  <label class="form-label small">Capacity</label>
                  <input v-model.number="editForm.capacity" type="number" min="1" class="form-control form-control-sm" />
                </div>
                <div class="col-md-3">
                  <label class="form-label small">Mode</label>
                  <select v-model="editForm.mode" class="form-select form-select-sm">
                    <option value="Physical">Physical</option>
                    <option value="Online">Online</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small">{{ editForm.mode === 'Online' ? 'Meeting link' : 'Location' }}</label>
                  <input
                    v-if="editForm.mode === 'Online'"
                    v-model="editForm.meeting_link"
                    type="url" class="form-control form-control-sm" placeholder="https://meet.google.com/..."
                  />
                  <input v-else v-model="editForm.location" type="text" class="form-control form-control-sm" placeholder="e.g. Library room 3" />
                </div>
                <div class="col-md-6">
                  <label class="form-label small">Resources / sources</label>
                  <input v-model="editForm.resources" type="text" class="form-control form-control-sm" placeholder="Links or notes you'll share" />
                </div>
                <div class="col-md-6">
                  <label class="form-label small">Learning outcomes</label>
                  <input v-model="editForm.outcomes" type="text" class="form-control form-control-sm" placeholder="What the student will gain" />
                </div>
                <div class="col-md-6">
                  <label class="form-label small">Visibility</label>
                  <select v-model="editForm.visibility" class="form-select form-select-sm">
                    <option value="Public">Public (anyone can find &amp; book)</option>
                    <option value="Private">Private (invite link only)</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small">Booking approval</label>
                  <select v-model="editForm.auto_accept" class="form-select form-select-sm">
                    <option :value="true">Auto-accept (instant confirm)</option>
                    <option :value="false">I approve each request</option>
                  </select>
                </div>
              </div>
              <div class="mt-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary" :disabled="savingEdit" @click="saveEdit(slot)">
                  {{ savingEdit ? 'Saving...' : 'Save changes' }}
                </button>
                <button class="btn btn-sm btn-light" @click="cancelEdit">Close</button>
                <span class="text-muted small align-self-center">Time can't be changed — cancel &amp; repost to move it.</span>
              </div>
            </div>
          </div>
        </div>
        <p v-else class="text-muted small">No availability set yet.</p>

        <!-- Add new slot -->
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small">Date</label>
            <input v-model="newSlot.available_date" type="date" class="form-control form-control-sm" />
          </div>
          <div class="col-md-2">
            <label class="form-label small">Start</label>
            <input v-model="newSlot.start_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-md-2">
            <label class="form-label small">End</label>
            <input v-model="newSlot.end_time" type="time" class="form-control form-control-sm" />
          </div>
          <div class="col-md-2">
            <label class="form-label small">Type</label>
            <select v-model="newSlot.slot_type" class="form-select form-select-sm">
              <option value="Solo">Solo (1 seat)</option>
              <option value="Group">Group</option>
            </select>
          </div>
          <div v-if="newSlot.slot_type === 'Group'" class="col-md-2">
            <label class="form-label small">Seats</label>
            <input v-model.number="newSlot.group_capacity" type="number" min="2" class="form-control form-control-sm" />
          </div>
          <div class="col-md-3">
            <label class="form-label small">Mode</label>
            <select v-model="newSlot.mode" class="form-select form-select-sm">
              <option value="Physical">Physical (in person)</option>
              <option value="Online">Online</option>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label small">{{ newSlot.mode === 'Online' ? 'Meeting link' : 'Location' }}</label>
            <input
              v-if="newSlot.mode === 'Online'"
              v-model="newSlot.meeting_link"
              type="url" class="form-control form-control-sm" placeholder="https://meet.google.com/..."
            />
            <input v-else v-model="newSlot.location" type="text" class="form-control form-control-sm" placeholder="e.g. Library room 3" />
          </div>
          <div class="col-md-6">
            <label class="form-label small">Resources / sources (optional)</label>
            <input v-model="newSlot.resources" type="text" class="form-control form-control-sm" placeholder="Links or notes you'll share" />
          </div>
          <div class="col-md-4">
            <label class="form-label small">Learning outcomes (optional)</label>
            <input v-model="newSlot.outcomes" type="text" class="form-control form-control-sm" placeholder="What the student will gain" />
          </div>
          <div class="col-md-3">
            <label class="form-label small">Visibility</label>
            <select v-model="newSlot.visibility" class="form-select form-select-sm">
              <option value="Public">Public (anyone can find &amp; book)</option>
              <option value="Private">Private (invite link only)</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Booking approval</label>
            <select v-model="newSlot.auto_accept" class="form-select form-select-sm">
              <option :value="true">Auto-accept (instant confirm)</option>
              <option :value="false">I approve each request</option>
            </select>
          </div>
          <div class="col-md-2">
            <button class="btn btn-primary btn-sm w-100" :disabled="saving" @click="addSlot">
              {{ saving ? '...' : 'Add slot' }}
            </button>
          </div>
          <div v-if="newSlot.visibility === 'Private'" class="col-12">
            <p class="text-muted small mb-0">
              <i class="bi bi-info-circle me-1"></i>Private slots won't show in the marketplace —
              after adding, use <strong>Copy invite link</strong> to share it.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Cancel-session dialog (in-app, so the priority checkbox is reliable) -->
  <div v-if="cancelTarget" class="modal-backdrop-custom" @click.self="cancelTarget = null">
    <div class="card cancel-modal shadow-lg">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <h5 class="fw-bold mb-0">Cancel this session?</h5>
          <button class="btn-close" @click="cancelTarget = null"></button>
        </div>
        <p class="text-muted small mb-3">
          {{ cancelTarget.available_date }} ·
          {{ cancelTarget.start_time.slice(0,5) }}–{{ cancelTarget.end_time.slice(0,5) }}
        </p>

        <div v-if="cancelTarget.seats_taken > 0" class="alert alert-warning py-2 small">
          <i class="bi bi-people-fill me-1"></i>
          <strong>{{ cancelTarget.seats_taken }}</strong> student(s) are booked and will be messaged that it's cancelled.
        </div>
        <p v-else class="text-muted small">No students are booked, so nobody will be notified.</p>

        <div v-if="cancelTarget.seats_taken > 0" class="form-check mb-3">
          <input id="give-priority" v-model="cancelPriority" type="checkbox" class="form-check-input" />
          <label for="give-priority" class="form-check-label small">
            Give these students <strong>priority</strong> on my next slot
            <span class="d-block text-muted">They get a 12-hour head start to grab a seat before it opens to everyone else.</span>
          </label>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-warning" :disabled="cancelling" @click="confirmCancel">
            <span v-if="cancelling" class="spinner-border spinner-border-sm me-2"></span>
            {{ cancelling ? 'Cancelling…' : 'Cancel session' }}
          </button>
          <button class="btn btn-light" @click="cancelTarget = null">Keep it</button>
        </div>
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
.cancel-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}
</style>