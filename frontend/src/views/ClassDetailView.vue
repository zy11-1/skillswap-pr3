<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/data/api'
import TipBanner from '@/components/TipBanner.vue'

const route = useRoute()
const router = useRouter()

const loading = ref(true)
const error = ref('')
const slot = ref(null)
const students = ref([])
const isOwner = ref(false)
const myBookingId = ref(null)

const classId = computed(() => Number(route.params.id))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.getClassDetail(classId.value)
    slot.value = res.data.slot
    students.value = res.data.students
    isOwner.value = res.data.is_owner
    myBookingId.value = res.data.my_booking_id
    topicDraft.value = slot.value.topics_covered || ''
    resourcesDraft.value = slot.value.resources || ''
    followUpDraft.value = slot.value.follow_up_link || ''
    linkDraft.value = slot.value.meeting_link || ''
    locationDraft.value = slot.value.location || ''
  } catch (err) {
    error.value = err.message || 'Could not load this class.'
  } finally {
    loading.value = false
  }
}
onMounted(load)

const activeStudents = computed(() => students.value.filter((s) => s.status !== 'Cancelled'))
const whenLabel = computed(() => {
  if (!slot.value) return ''
  const d = new Date(`${slot.value.available_date}T${slot.value.start_time}`)
  return d.toLocaleDateString('en-MY', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
    + ` · ${slot.value.start_time.slice(0, 5)}–${slot.value.end_time.slice(0, 5)}`
})
const hasEnded = computed(() => {
  if (!slot.value) return false
  return new Date(`${slot.value.available_date}T${slot.value.end_time}`).getTime() < Date.now()
})
const myRow = computed(() => students.value.find((s) => s.booking_id === myBookingId.value))
const recordingUrl = computed(() => {
  const withRec = students.value.find((s) => s.recording_url)
  if (isOwner.value) return withRec?.recording_url || null
  return myRow.value?.recording_url || withRec?.recording_url || null
})

// ---- Tutor edits (each saves independently) ----
const topicDraft = ref('')
const resourcesDraft = ref('')
const followUpDraft = ref('')
const linkDraft = ref('')
const locationDraft = ref('')
const saving = ref('')          // which section is saving
const saved = ref('')           // which section just saved (flash feedback)

function flash(section) {
  saved.value = section
  setTimeout(() => { if (saved.value === section) saved.value = '' }, 2000)
}

async function saveTopic() {
  if (!topicDraft.value.trim()) return
  saving.value = 'topic'
  try {
    await api.setSyllabus(classId.value, topicDraft.value.trim())
    await load()
    flash('topic')
  } catch (err) {
    alert(err.message || 'Could not save the topic details.')
  } finally {
    saving.value = ''
  }
}

async function savePatch(section, payload) {
  saving.value = section
  try {
    await api.updateAvailability(classId.value, payload)
    await load()
    flash(section)
  } catch (err) {
    alert(err.message || 'Could not save.')
  } finally {
    saving.value = ''
  }
}

// ---- Group message (tutor) ----
const announceText = ref('')
const announcing = ref(false)
const announcedTo = ref(null)
async function sendAnnouncement() {
  if (!announceText.value.trim()) return
  announcing.value = true
  try {
    const res = await api.announceToClass(classId.value, announceText.value.trim())
    announcedTo.value = res.data.sent_to
    announceText.value = ''
    setTimeout(() => { announcedTo.value = null }, 3000)
  } catch (err) {
    alert(err.message || 'Could not send the message.')
  } finally {
    announcing.value = false
  }
}

function messageUser(userId, name) {
  router.push({ path: '/messages', query: { to: userId, name } })
}

function statusClass(status) {
  return `status-pill status-${status.toLowerCase()}`
}
</script>

<template>
  <div class="container py-4" style="max-width: 860px">
    <button class="btn btn-link btn-sm px-0 mb-2" @click="router.back()">
      <i class="bi bi-arrow-left me-1"></i>Back
    </button>

    <div v-if="loading" class="text-center py-5"><div class="spinner-border text-primary-ss"></div></div>
    <div v-else-if="error" class="alert alert-danger">{{ error }}</div>

    <template v-else-if="slot">
      <TipBanner v-if="isOwner" tip-id="class-page-tutor">
        This is your class's home page. Update what you'll cover, share materials, message the whole
        class at once, and paste an invite link to the follow-up class — students are notified of changes automatically.
      </TipBanner>
      <TipBanner v-else tip-id="class-page-learner">
        Everything about your class lives here — the topic, materials, the meeting link, and your
        classmates. If anything changes, your tutor's updates appear here and in your notifications.
      </TipBanner>

      <!-- Header card -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <h4 class="fw-bold mb-1">
                <i class="bi bi-bookmark-fill text-primary-ss me-1"></i>
                {{ slot.topic || 'Open topic — first student to book picks it' }}
              </h4>
              <p class="text-muted mb-1">
                <img v-if="slot.tutor_photo" :src="slot.tutor_photo" class="rounded-circle me-1" width="22" height="22" />
                {{ isOwner ? 'Taught by you' : `with ${slot.tutor_name}` }}
              </p>
              <p class="mb-1"><i class="bi bi-calendar3 me-1"></i>{{ whenLabel }} · {{ slot.hours }}h</p>
              <p class="mb-0 small">
                <span :class="slot.mode === 'Online' ? 'text-primary' : 'text-success'">
                  <i :class="slot.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ slot.mode }}
                </span>
                <template v-if="slot.mode === 'Physical' && slot.location"> · {{ slot.location }}</template>
                <a v-if="slot.mode === 'Online' && slot.meeting_link" :href="slot.meeting_link" target="_blank" rel="noopener" class="ms-2">
                  <i class="bi bi-box-arrow-up-right me-1"></i>Join meeting
                </a>
              </p>
            </div>
            <div class="text-end">
              <span v-if="slot.status === 'Cancelled'" class="badge bg-danger d-block mb-1">Cancelled</span>
              <span v-else-if="hasEnded" class="badge bg-secondary d-block mb-1">Class has ended</span>
              <span class="fw-bold text-primary-ss d-block">RM{{ Number(slot.next_price).toFixed(2) }} / student</span>
              <span class="text-muted" style="font-size:.7rem">
                {{ activeStudents.length }} enrolled · projected final RM{{ Number(slot.projected_price).toFixed(2) }}
              </span>
            </div>
          </div>
          <a v-if="recordingUrl" :href="recordingUrl" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm mt-2">
            <i class="bi bi-camera-video me-1"></i>Watch recording
          </a>
        </div>
      </div>

      <!-- What will be covered -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-2"><i class="bi bi-card-text me-1 text-primary-ss"></i>What will be covered</h6>
          <template v-if="isOwner">
            <textarea v-model="topicDraft" class="form-control form-control-sm mb-2" rows="3"
              placeholder="e.g. Vue.js Part 1 — components, props, and reactive state; live coding + Q&A"></textarea>
            <button class="btn btn-primary btn-sm" :disabled="saving === 'topic' || !topicDraft.trim()" @click="saveTopic">
              {{ saving === 'topic' ? 'Saving…' : 'Save' }}
            </button>
            <span v-if="saved === 'topic'" class="text-success small ms-2"><i class="bi bi-check-lg"></i> Saved — students updated</span>
          </template>
          <p v-else class="mb-0" style="white-space: pre-line">{{ slot.topics_covered || 'The tutor hasn\'t written the details yet — check back soon.' }}</p>
        </div>
      </div>

      <!-- Resources / materials -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-2"><i class="bi bi-folder2-open me-1 text-primary-ss"></i>Resources &amp; materials</h6>
          <template v-if="isOwner">
            <textarea v-model="resourcesDraft" class="form-control form-control-sm mb-2" rows="3"
              placeholder="Notes, slides, and links — one per line. Students see these here."></textarea>
            <button class="btn btn-primary btn-sm" :disabled="saving === 'resources'" @click="savePatch('resources', { resources: resourcesDraft })">
              {{ saving === 'resources' ? 'Saving…' : 'Save' }}
            </button>
            <span v-if="saved === 'resources'" class="text-success small ms-2"><i class="bi bi-check-lg"></i> Saved — students notified</span>
          </template>
          <p v-else-if="slot.resources" class="mb-0" style="white-space: pre-line">{{ slot.resources }}</p>
          <p v-else class="text-muted small mb-0">No materials shared yet.</p>
        </div>
      </div>

      <!-- Meeting link / location (tutor editable) -->
      <div v-if="isOwner" class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-2"><i class="bi bi-geo me-1 text-primary-ss"></i>Where the class happens</h6>
          <div class="input-group input-group-sm" style="max-width: 480px">
            <span class="input-group-text">{{ slot.mode === 'Online' ? 'Meeting link' : 'Location' }}</span>
            <input v-if="slot.mode === 'Online'" v-model="linkDraft" type="url" class="form-control" placeholder="https://meet.google.com/…" />
            <input v-else v-model="locationDraft" type="text" class="form-control" placeholder="e.g. Library, Level 2" />
            <button class="btn btn-outline-primary" :disabled="saving === 'where'"
              @click="savePatch('where', slot.mode === 'Online' ? { meeting_link: linkDraft } : { location: locationDraft })">
              {{ saving === 'where' ? '…' : 'Save' }}
            </button>
          </div>
          <span v-if="saved === 'where'" class="text-success small"><i class="bi bi-check-lg"></i> Saved — students notified</span>
        </div>
      </div>

      <!-- Follow-up class -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-2"><i class="bi bi-signpost-split me-1 text-primary-ss"></i>Follow-up class (part 2)</h6>
          <template v-if="isOwner">
            <p class="text-muted small mb-2">
              Teaching the next part of this topic? Open a new slot (Private works great), copy its invite
              link from your dashboard, and paste it here — everyone in this class gets notified and can
              grab a seat directly.
            </p>
            <div class="input-group input-group-sm mb-1" style="max-width: 480px">
              <input v-model="followUpDraft" type="url" class="form-control" placeholder="https://…/slot/abc123 (invite link)" />
              <button class="btn btn-outline-primary" :disabled="saving === 'followup'"
                @click="savePatch('followup', { follow_up_link: followUpDraft })">
                {{ saving === 'followup' ? '…' : 'Save' }}
              </button>
            </div>
            <span v-if="saved === 'followup'" class="text-success small"><i class="bi bi-check-lg"></i> Saved — students notified</span>
          </template>
          <template v-else>
            <a v-if="slot.follow_up_link" :href="slot.follow_up_link" class="btn btn-primary btn-sm" target="_blank" rel="noopener">
              <i class="bi bi-arrow-right-circle me-1"></i>Join the follow-up class
            </a>
            <p v-else class="text-muted small mb-0">No follow-up class announced yet.</p>
          </template>
        </div>
      </div>

      <!-- Students -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <h6 class="fw-bold mb-2">
            <i class="bi bi-people-fill me-1 text-primary-ss"></i>
            {{ isOwner ? 'Students in this class' : 'Classmates' }}
            <span class="text-muted fw-normal small">({{ activeStudents.length }})</span>
          </h6>
          <ol v-if="students.length" class="list-group list-group-numbered list-group-flush">
            <li v-for="s in students" :key="s.booking_id"
              class="list-group-item d-flex justify-content-between align-items-center px-0">
              <span>
                <img v-if="s.learner_photo" :src="s.learner_photo" class="rounded-circle me-2" width="26" height="26" />
                {{ s.learner_name }}
                <span v-if="s.booking_id === myBookingId" class="badge bg-info-subtle text-info-emphasis ms-1">you</span>
              </span>
              <span class="d-flex align-items-center gap-2">
                <span :class="statusClass(s.status)">{{ s.status }}</span>
                <button v-if="isOwner" class="btn btn-outline-primary btn-sm"
                  title="Message this student" @click="messageUser(s.learner_id, s.learner_name)">
                  <i class="bi bi-chat-dots"></i>
                </button>
              </span>
            </li>
          </ol>
          <p v-else class="text-muted small mb-0">No students yet — share this class to get it started.</p>
          <router-link v-if="isOwner" to="/bookings" class="small d-inline-block mt-2">
            <i class="bi bi-list-check me-1"></i>Approve / manage requests in My Classes
          </router-link>
        </div>
      </div>

      <!-- Group message (tutor) / message tutor (learner) -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
          <template v-if="isOwner">
            <h6 class="fw-bold mb-2"><i class="bi bi-megaphone me-1 text-primary-ss"></i>Message the whole class</h6>
            <textarea v-model="announceText" class="form-control form-control-sm mb-2" rows="2"
              placeholder="e.g. Bring your laptops — we'll code along today. See you at 4pm!"></textarea>
            <button class="btn btn-primary btn-sm" :disabled="announcing || !announceText.trim()" @click="sendAnnouncement">
              <i class="bi bi-send me-1"></i>{{ announcing ? 'Sending…' : 'Send to everyone' }}
            </button>
            <span v-if="announcedTo !== null" class="text-success small ms-2">
              <i class="bi bi-check-lg"></i> Sent to {{ announcedTo }} student{{ announcedTo === 1 ? '' : 's' }}
            </span>
          </template>
          <template v-else>
            <h6 class="fw-bold mb-2"><i class="bi bi-chat-dots me-1 text-primary-ss"></i>Questions about this class?</h6>
            <button class="btn btn-primary btn-sm" @click="messageUser(slot.tutor_id, slot.tutor_name)">
              <i class="bi bi-send me-1"></i>Message {{ slot.tutor_name }}
            </button>
          </template>
        </div>
      </div>
    </template>
  </div>
</template>
