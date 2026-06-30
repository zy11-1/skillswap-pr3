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
    end_time: '11:00',
    capacity: 5,          // number of seats in this group class
    base_price: 20,       // starting price per seat, per hour (RM10 floor)
    repeat_weeks: 1,      // 1 = single slot; >1 creates that many weekly copies
    mode: 'Physical',     // 'Physical' or 'Online'
    meeting_link: '',
    location: '',
    resources: '',
    outcomes: '',
    visibility: 'Public'  // 'Public' (browsable) or 'Private' (invite link only)
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

// ---- Merit standing (performance-based) ----
const merit = ref({
  classes_completed: 0, students_helped: 0, avg_rating: 0, review_count: 0,
  thresholds: { classes: 100, students: 150, rating: 4, reviews: 100 },
  eligible: false, has_pending: false, requests: []
})
const meritError = ref('')
const applyingMerit = ref(false)

async function loadMerits() {
  try {
    const res = await api.getMeritStanding()
    merit.value = res.data
  } catch (err) {
    console.error('Failed to load merit standing:', err)
  }
}

function meritPct(value, target) {
  return Math.min(100, Math.round((value / target) * 100))
}

async function applyMerit() {
  meritError.value = ''
  applyingMerit.value = true
  try {
    await api.applyForMerit()
    await loadMerits()
  } catch (err) {
    meritError.value = err.message || 'Could not submit application.'
  } finally {
    applyingMerit.value = false
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
  const capacity = Number(newSlot.value.capacity)
  if (capacity < 1) {
    slotError.value = 'A group class needs at least 1 seat.'
    return
  }
  if (Number(newSlot.value.base_price) < 10) {
    slotError.value = 'Base price must be at least RM10 per hour.'
    return
  }
  saving.value = true
  try {
    await api.addAvailability({
      available_date: newSlot.value.available_date,
      start_time: newSlot.value.start_time,
      end_time: newSlot.value.end_time,
      capacity,
      base_price: Number(newSlot.value.base_price),
      repeat_weeks: Number(newSlot.value.repeat_weeks) || 1,
      mode: newSlot.value.mode,
      meeting_link: newSlot.value.meeting_link,
      location: newSlot.value.location,
      resources: newSlot.value.resources,
      outcomes: newSlot.value.outcomes,
      visibility: newSlot.value.visibility
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
    base_price: slot.base_price,
    available_date: slot.available_date,
    start_time: slot.start_time ? slot.start_time.slice(0, 5) : '',
    end_time: slot.end_time ? slot.end_time.slice(0, 5) : '',
    mode: slot.mode || 'Physical',
    meeting_link: slot.meeting_link || '',
    location: slot.location || '',
    resources: slot.resources || '',
    outcomes: slot.outcomes || '',
    visibility: slot.visibility || 'Public'
  }
  // Base price is only editable before anyone has booked.
  editForm.value.priceLocked = (slot.seats_taken || 0) > 0
}

function cancelEdit() {
  editingId.value = null
}

// Syllabus prompt: once the first student locks a slot's topic, the tutor
// must publish "Topics covered" before anyone else can join.
const syllabusDraft = ref({})        // keyed by availability_id
const savingSyllabus = ref(null)
async function saveSyllabus(slot) {
  const text = (syllabusDraft.value[slot.availability_id] || '').trim()
  if (!text) return
  savingSyllabus.value = slot.availability_id
  try {
    await api.setSyllabus(slot.availability_id, text)
    await loadAvailability()
  } catch (err) {
    alert(err.message || 'Could not save the syllabus.')
  } finally {
    savingSyllabus.value = null
  }
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
      base_price: Number(editForm.value.base_price),
      start_time: editForm.value.start_time,
      end_time: editForm.value.end_time,
      mode: editForm.value.mode,
      meeting_link: editForm.value.meeting_link,
      location: editForm.value.location,
      resources: editForm.value.resources,
      outcomes: editForm.value.outcomes,
      visibility: editForm.value.visibility
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
    <!-- Merit standing (performance-based UTM merit transfer) -->
    <!-- ============================================================ -->
    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header bg-white fw-bold">
        <i class="bi bi-award me-2"></i>University Merit Standing
      </div>
      <div class="card-body">
        <p class="text-muted small mb-3">
          Earn a UTM merit transfer through your teaching record — not credits.
          You become eligible once you clear all four thresholds below; an admin then reviews your record.
        </p>
        <div v-if="meritError" class="alert alert-danger py-2 small">{{ meritError }}</div>

        <div class="row g-3 mb-3">
          <div class="col-md-3 col-6" v-for="m in [
            { label: 'Classes completed', value: merit.classes_completed, target: merit.thresholds.classes },
            { label: 'Students helped', value: merit.students_helped, target: merit.thresholds.students },
            { label: 'Avg rating', value: merit.avg_rating, target: merit.thresholds.rating, isRating: true },
            { label: 'Reviews', value: merit.review_count, target: merit.thresholds.reviews }
          ]" :key="m.label">
            <div class="border rounded p-2 h-100">
              <div class="small text-muted">{{ m.label }}</div>
              <div class="fw-bold">
                <span :class="m.value >= m.target ? 'text-success' : ''">{{ m.value }}</span>
                <span class="text-muted small"> / {{ m.target }}{{ m.isRating ? '+' : '' }}</span>
                <i v-if="m.value >= m.target" class="bi bi-check-circle-fill text-success ms-1"></i>
              </div>
              <div class="progress mt-1" style="height: 5px">
                <div class="progress-bar" :class="m.value >= m.target ? 'bg-success' : 'bg-primary'"
                     :style="{ width: meritPct(m.value, m.target) + '%' }"></div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="merit.has_pending" class="alert alert-info py-2 small mb-0">
          <i class="bi bi-hourglass-split me-1"></i>Your merit transfer application is pending admin review.
        </div>
        <div v-else-if="merit.eligible">
          <div class="alert alert-success py-2 small">
            <i class="bi bi-trophy-fill me-1"></i>You're eligible! Apply to transfer your merit to UTM.
          </div>
          <button class="btn btn-primary btn-sm" :disabled="applyingMerit" @click="applyMerit">
            {{ applyingMerit ? 'Submitting…' : 'Apply for UTM merit transfer' }}
          </button>
        </div>
        <p v-else class="text-muted small mb-0">
          Keep teaching and earning great reviews to unlock the merit transfer.
        </p>

        <div v-if="merit.requests && merit.requests.length" class="mt-3">
          <div class="small text-muted mb-1">Application history</div>
          <div v-for="r in merit.requests" :key="r.merit_request_id" class="small">
            {{ r.classes_completed }} classes · {{ r.students_helped }} students · {{ r.avg_rating }}★
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
              <span class="text-primary-ss fw-semibold ms-2">RM{{ Number(s.hourly_rate).toFixed(2) }}/hr</span>
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
                <span class="badge ms-2 bg-info text-dark">
                  <i class="bi bi-people-fill me-1"></i>
                  {{ slot.seats_taken }} {{ slot.seats_taken === 1 ? 'student' : 'students' }} enrolled
                  <span class="fw-normal">· {{ slot.capacity }} seat cap</span>
                </span>
                <span class="badge ms-1" :class="slot.mode === 'Online' ? 'bg-primary' : 'bg-success'">
                  <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>
                  {{ slot.mode }}
                </span>
                <span v-if="slot.visibility === 'Private'" class="badge bg-dark ms-1">
                  <i class="bi bi-lock-fill me-1"></i>Private
                </span>
                <span class="badge ms-1" :class="slot.topic ? 'bg-info-subtle text-info-emphasis' : 'bg-success-subtle text-success-emphasis'">
                  <i class="bi bi-bookmark me-1"></i>{{ slot.topic || 'Open topic' }}
                </span>
                <span class="badge bg-light text-dark border ms-1">
                  RM{{ Number(slot.base_price).toFixed(0) }}/hr base · now RM{{ Number(slot.next_price).toFixed(2) }}
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

            <!-- Syllabus prompt: required once the first student locks a topic -->
            <div v-if="slot.needs_syllabus" class="mt-2 border-top pt-2">
              <div class="alert alert-warning py-2 small mb-2">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                A student started this class on <strong>{{ slot.topic }}</strong>. Add what you'll cover
                so the rest of the class can join.
              </div>
              <div class="d-flex gap-2">
                <input
                  v-model="syllabusDraft[slot.availability_id]"
                  type="text"
                  class="form-control form-control-sm"
                  placeholder="e.g. Vue.js – Part 2: Components & props"
                />
                <button
                  class="btn btn-sm btn-primary text-nowrap"
                  :disabled="savingSyllabus === slot.availability_id"
                  @click="saveSyllabus(slot)"
                >
                  {{ savingSyllabus === slot.availability_id ? 'Saving…' : 'Publish' }}
                </button>
              </div>
            </div>
            <div v-else-if="slot.topics_covered" class="mt-2 small text-muted">
              <i class="bi bi-card-text me-1"></i><strong>Covering:</strong> {{ slot.topics_covered }}
            </div>

            <!-- Inline editor (the day is fixed; the time/length can change) -->
            <div v-if="editingId === slot.availability_id" class="mt-2 border-top pt-2">
              <div class="row g-2">
                <div class="col-md-3">
                  <label class="form-label small">Day <span class="text-muted">(can't change)</span></label>
                  <input :value="editForm.available_date" type="date" disabled class="form-control form-control-sm" />
                </div>
                <div class="col-md-2">
                  <label class="form-label small">Start</label>
                  <input v-model="editForm.start_time" type="time" class="form-control form-control-sm" />
                </div>
                <div class="col-md-2">
                  <label class="form-label small">End</label>
                  <input v-model="editForm.end_time" type="time" class="form-control form-control-sm" />
                </div>
                <div v-if="slot.seats_taken > 0" class="col-12">
                  <p class="text-warning small mb-1">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Changing the time will ask the {{ slot.seats_taken }} booked student(s) to accept or reject
                    (reject = full refund; a shorter class refunds the difference).
                  </p>
                </div>
                <div class="col-md-3">
                  <label class="form-label small">Seats</label>
                  <input v-model.number="editForm.capacity" type="number" min="1" class="form-control form-control-sm" />
                </div>
                <div class="col-md-3">
                  <label class="form-label small">Base price /hr</label>
                  <input v-model.number="editForm.base_price" type="number" min="10" step="1"
                         :disabled="editForm.priceLocked" class="form-control form-control-sm" />
                  <span v-if="editForm.priceLocked" class="text-muted" style="font-size:.65rem">locked — students booked</span>
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
            <label class="form-label small">Seats</label>
            <input v-model.number="newSlot.capacity" type="number" min="1" class="form-control form-control-sm" />
          </div>
          <div class="col-md-2">
            <label class="form-label small">Base price /hr</label>
            <input v-model.number="newSlot.base_price" type="number" min="10" step="1" class="form-control form-control-sm" />
          </div>
          <div class="col-md-3">
            <label class="form-label small">Repeat weekly</label>
            <select v-model.number="newSlot.repeat_weeks" class="form-select form-select-sm">
              <option :value="1">Just this once</option>
              <option :value="2">For 2 weeks</option>
              <option :value="4">For 4 weeks</option>
              <option :value="8">For 8 weeks</option>
            </select>
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
            <button class="btn btn-primary btn-sm w-100" :disabled="saving" @click="addSlot">
              {{ saving ? '...' : 'Add group class' }}
            </button>
          </div>
          <div class="col-12">
            <p class="text-muted small mb-0">
              <i class="bi bi-info-circle me-1"></i>
              Every session is a <strong>group class</strong>: the first student picks the topic, then you
              add what you'll cover. Price starts at your base rate and drops RM1 per extra student (min RM10/hr).
              Bookings are prepaid and wait for your approval.
              <template v-if="newSlot.visibility === 'Private'"><br />Private slots won't show in the marketplace — use <strong>Copy invite link</strong> after adding.</template>
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