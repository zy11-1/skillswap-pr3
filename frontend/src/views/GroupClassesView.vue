<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/data/api'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const classes = ref([])
const skills = ref([])
const loading = ref(true)
const enrollingId = ref(null)
const creating = ref(false)
const formError = ref('')

const form = ref({ skill_id: '', title: '', class_date: '', duration: 1, capacity: 5, price_per_seat: 8 })

async function load() {
  loading.value = true
  try {
    const [classesRes, skillsRes] = await Promise.all([api.getGroupClasses(), api.getSkills()])
    classes.value = classesRes.data || []
    skills.value = skillsRes.data || []
  } finally {
    loading.value = false
  }
}

onMounted(load)

function seatsLeft(c) {
  return c.capacity - c.seats_taken
}

async function enroll(c) {
  enrollingId.value = c.group_class_id
  try {
    await api.enrollGroupClass(c.group_class_id)
    await load()
  } catch (err) {
    alert(err.message || 'Could not enrol.')
  } finally {
    enrollingId.value = null
  }
}

async function createClass() {
  formError.value = ''
  if (!form.value.skill_id || !form.value.title || !form.value.class_date) {
    formError.value = 'Skill, title and date/time are required.'
    return
  }
  creating.value = true
  try {
    await api.createGroupClass({
      skill_id: Number(form.value.skill_id),
      title: form.value.title,
      class_date: new Date(form.value.class_date).toISOString(),
      duration: Number(form.value.duration),
      capacity: Number(form.value.capacity),
      price_per_seat: Number(form.value.price_per_seat)
    })
    form.value = { skill_id: '', title: '', class_date: '', duration: 1, capacity: 5, price_per_seat: 8 }
    await load()
  } catch (err) {
    formError.value = err.message || 'Could not create class.'
  } finally {
    creating.value = false
  }
}

function formatDate(d) {
  return new Date(d).toLocaleString('en-MY', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">Group Classes</h3>
    <p class="text-muted">Learn together at a discounted per-seat rate</p>

    <!-- Tutor: create a class -->
    <div v-if="auth.isTutorMode" class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-bold"><i class="bi bi-people me-2"></i>Host a group class</div>
      <div class="card-body">
        <div v-if="formError" class="alert alert-danger py-2 small">{{ formError }}</div>
        <div class="row g-2 align-items-end">
          <div class="col-md-3">
            <label class="form-label small">Skill</label>
            <select v-model="form.skill_id" class="form-select form-select-sm">
              <option value="" disabled>Select</option>
              <option v-for="s in skills" :key="s.skill_id" :value="s.skill_id">{{ s.name }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Title</label>
            <input v-model="form.title" class="form-control form-control-sm" placeholder="e.g. Calculus crash course" />
          </div>
          <div class="col-md-3">
            <label class="form-label small">Date &amp; time</label>
            <input v-model="form.class_date" type="datetime-local" class="form-control form-control-sm" />
          </div>
          <div class="col-md-1">
            <label class="form-label small">Hours</label>
            <input v-model.number="form.duration" type="number" min="1" class="form-control form-control-sm" />
          </div>
          <div class="col-md-1">
            <label class="form-label small">Seats</label>
            <input v-model.number="form.capacity" type="number" min="2" class="form-control form-control-sm" />
          </div>
          <div class="col-md-1">
            <label class="form-label small">RM/seat</label>
            <input v-model.number="form.price_per_seat" type="number" min="1" step="0.5" class="form-control form-control-sm" />
          </div>
        </div>
        <button class="btn btn-primary btn-sm mt-2" :disabled="creating" @click="createClass">
          {{ creating ? 'Creating...' : 'Create class' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <div v-else-if="classes.length" class="row g-3">
      <div v-for="c in classes" :key="c.group_class_id" class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start">
              <h6 class="mb-1">{{ c.title }}</h6>
              <span class="badge bg-light text-dark border">{{ c.skill_name }}</span>
            </div>
            <p class="small text-muted mb-1">with {{ c.tutor_name }}</p>
            <p class="small mb-1"><i class="bi bi-calendar3 me-1"></i>{{ formatDate(c.class_date) }} · {{ c.duration }}h</p>
            <p class="small mb-2">
              <i class="bi bi-people me-1"></i>{{ c.seats_taken }}/{{ c.capacity }} enrolled
              <span class="text-success ms-1">({{ seatsLeft(c) }} seats left)</span>
            </p>
            <div class="mt-auto d-flex justify-content-between align-items-center">
              <span class="fw-bold text-primary-ss">RM{{ c.price_per_seat.toFixed(2) }}/seat</span>
              <span v-if="c.is_mine" class="badge bg-secondary">Your class</span>
              <span v-else-if="c.is_enrolled" class="badge bg-success">Enrolled</span>
              <button
                v-else
                class="btn btn-primary btn-sm"
                :disabled="enrollingId === c.group_class_id || seatsLeft(c) <= 0"
                @click="enroll(c)"
              >
                {{ seatsLeft(c) <= 0 ? 'Full' : (enrollingId === c.group_class_id ? '...' : 'Join') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-people" style="font-size: 2rem"></i>
      <p class="mt-2">No group classes yet.</p>
    </div>
  </div>
</template>
