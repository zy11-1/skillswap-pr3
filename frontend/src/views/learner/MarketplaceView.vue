<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/data/api'
import TutorCard from '@/components/tutor/TutorCard.vue'
import { useFavoritesStore } from '@/stores/favorites'
import { useAuthStore } from '@/stores/auth'

const favorites = useFavoritesStore()
const auth = useAuthStore()
// Favourites panel: one card per favourited tutor, derived from the loaded
// list so it updates instantly when a heart is toggled.
const favoriteTutors = computed(() => {
  const seen = new Set()
  return tutors.value.filter((t) => {
    if (!favorites.isFavorite(t.user_id) || seen.has(t.user_id)) return false
    seen.add(t.user_id)
    return true
  })
})

const tutors = ref([])
const skills = ref([])
const trending = ref([])
const recommended = ref([])
const upcomingClasses = ref([])
const loading = ref(true)
const error = ref(null)

// "Upcoming classes" board: classes another student already started (topic set
// + tutor published what they'll cover). Anyone can join; hide the viewer's own.
const joinableClasses = computed(() =>
  upcomingClasses.value.filter((c) => c.tutor_id !== auth.user?.user_id)
)

// Join flow (prepay confirmation in a small modal).
const joinTarget = ref(null)
const joining = ref(false)
const joinError = ref('')
const joinDone = ref(false)

function openJoin(c) {
  joinTarget.value = c
  joinError.value = ''
  joinDone.value = false
}

async function confirmJoin() {
  if (!joinTarget.value) return
  joining.value = true
  joinError.value = ''
  try {
    await api.createBooking({ availability_id: joinTarget.value.availability_id })
    joinDone.value = true
    // Refresh the board so the booked-count / price reflect this join.
    const res = await api.getUpcomingClasses()
    upcomingClasses.value = res.data || []
    setTimeout(() => { joinTarget.value = null }, 1400)
  } catch (err) {
    joinError.value = err.message || 'Could not join this class.'
  } finally {
    joining.value = false
  }
}

function classWhen(c) {
  return new Date(`${c.available_date}T${c.start_time}`).toLocaleString('en-MY', {
    weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
  })
}

const searchQuery = ref('')
const selectedSkill = ref('')
const selectedCategory = ref('')
const selectedFaculty = ref('')
const minRating = ref(0)
const maxPrice = ref(50)

// Faculties available among the loaded tutors (for the filter dropdown).
const faculties = computed(() => {
  const set = new Set(tutors.value.map((t) => t.tutor_faculty).filter(Boolean))
  return [...set].sort()
})

async function loadData() {
  loading.value = true
  error.value = null

  try {
    const [tutorsRes, skillsRes, trendingRes, recommendedRes, upcomingRes] = await Promise.all([
      api.getTutors(),
      api.getSkills(),
      api.getTrendingSkills(),
      api.getRecommendedTutors(),
      api.getUpcomingClasses()
    ])

    // api.getTutors() returns { data: [...] }
    tutors.value = tutorsRes.data || []
    skills.value = skillsRes.data || []
    trending.value = trendingRes.data || []
    recommended.value = recommendedRes.data || []
    upcomingClasses.value = upcomingRes.data || []
    await favorites.fetchIds()
  } catch (err) {
    error.value = 'Failed to load data. Please check that the backend is running.'
    console.error('Failed to load data:', err)
  } finally {
    loading.value = false
  }
}

onMounted(loadData)

const filteredTutors = computed(() => {
  if (!Array.isArray(tutors.value)) return []
  return tutors.value.filter((t) => {
    const matchesSearch =
      !searchQuery.value ||
      (t.tutor_name && t.tutor_name.toLowerCase().includes(searchQuery.value.toLowerCase())) ||
      (t.skill_name && t.skill_name.toLowerCase().includes(searchQuery.value.toLowerCase()))

    const matchesSkill = !selectedSkill.value || t.skill_id === Number(selectedSkill.value)
    const matchesCategory = !selectedCategory.value || t.skill_category === selectedCategory.value
    const matchesFaculty = !selectedFaculty.value || t.tutor_faculty === selectedFaculty.value
    const matchesRating = !minRating.value || (Number(t.avg_rating) || 0) >= minRating.value
    const matchesPrice = t.hourly_rate <= maxPrice.value

    return matchesSearch && matchesSkill && matchesCategory && matchesFaculty && matchesRating && matchesPrice
  })
})

function resetFilters() {
  searchQuery.value = ''
  selectedSkill.value = ''
  selectedCategory.value = ''
  selectedFaculty.value = ''
  minRating.value = 0
  maxPrice.value = 50
}
</script>

<template>
  <div class="container py-4">
    <div class="mb-4">
      <h3 class="fw-bold">Find a Tutor</h3>
      <p class="text-muted">Browse skills offered by your fellow students</p>
    </div>

    <!-- Upcoming classes board: classes other students started, open to join -->
    <div v-if="!loading && joinableClasses.length" class="mb-4">
      <h6 class="fw-bold mb-1"><i class="bi bi-calendar-event text-primary-ss me-1"></i>Upcoming classes</h6>
      <p class="text-muted small mb-2">Classes other students started — join in, and the price drops RM1 for every extra person who books.</p>
      <div class="row g-3">
        <div v-for="c in joinableClasses" :key="'cls-' + c.availability_id" class="col-md-4">
          <div class="card border-0 shadow-sm h-100 upcoming-card">
            <div class="card-body d-flex flex-column">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <span class="badge bg-info-subtle text-info-emphasis"><i class="bi bi-bookmark-fill me-1"></i>{{ c.topic }}</span>
                <span class="badge" :class="c.mode === 'Online' ? 'bg-primary' : 'bg-success'">
                  <i :class="c.mode === 'Online' ? 'bi bi-camera-video' : 'bi bi-geo-alt'" class="me-1"></i>{{ c.mode }}
                </span>
              </div>
              <p class="small text-muted mb-1">with <strong>{{ c.tutor_name }}</strong></p>
              <p class="small mb-1"><i class="bi bi-calendar3 me-1"></i>{{ classWhen(c) }} · {{ c.hours }}h</p>
              <p v-if="c.topics_covered" class="small text-muted mb-2 text-truncate-2">
                <i class="bi bi-card-text me-1"></i>{{ c.topics_covered }}
              </p>
              <div class="mt-auto d-flex justify-content-between align-items-center">
                <span>
                  <span class="fw-bold text-primary-ss">RM{{ Number(c.next_price).toFixed(2) }}</span>
                  <span class="d-block text-muted" style="font-size:.65rem">{{ c.booked_count }} booked · price drops as it fills</span>
                </span>
                <button class="btn btn-primary btn-sm" @click="openJoin(c)">
                  <i class="bi bi-plus-circle me-1"></i>Join
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
      <hr class="my-4" />
    </div>

    <!-- Favourite tutors (pinned) -->
    <div v-if="!loading && favoriteTutors.length" class="mb-4">
      <h6 class="fw-bold mb-2"><i class="bi bi-heart-fill text-danger me-1"></i>Your favourite tutors</h6>
      <div class="row g-3">
        <div v-for="tutor in favoriteTutors" :key="'fav-' + tutor.user_id" class="col-md-4">
          <TutorCard :tutor="tutor" />
        </div>
      </div>
      <hr class="my-4" />
    </div>

    <!-- Recommended for you (same faculty) -->
    <div v-if="!loading && recommended.length" class="mb-4">
      <h6 class="fw-bold mb-2"><i class="bi bi-stars me-1 text-warning"></i>Recommended for you</h6>
      <p class="text-muted small mb-2">Tutors from your faculty</p>
      <div class="row g-3">
        <div v-for="tutor in recommended" :key="'rec-' + tutor.userskill_id" class="col-md-4">
          <TutorCard :tutor="tutor" />
        </div>
      </div>
    </div>

    <!-- Trending skills -->
    <div v-if="trending.length" class="mb-3">
      <span class="small text-muted me-2"><i class="bi bi-graph-up-arrow me-1"></i>Trending:</span>
      <button
        v-for="t in trending"
        :key="t.skill_id"
        class="btn btn-sm me-1 mb-1"
        :class="Number(selectedSkill) === t.skill_id ? 'btn-primary' : 'btn-outline-primary'"
        @click="selectedSkill = String(t.skill_id)"
      >
        {{ t.name }}
        <span v-if="t.booking_count" class="badge bg-light text-dark ms-1">{{ t.booking_count }}</span>
      </button>
    </div>

    <!-- Search & Filters -->
    <div class="card mb-4 border-0 shadow-sm">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label small">Search</label>
            <input
              v-model="searchQuery"
              type="text"
              class="form-control"
              placeholder="Search tutor or skill..."
            />
          </div>
          <div class="col-md-3">
            <label class="form-label small">Skill</label>
            <select v-model="selectedSkill" class="form-select">
              <option value="">All skills</option>
              <option v-for="s in skills" :key="s.skill_id" :value="s.skill_id">
                {{ s.name }}
              </option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Category</label>
            <select v-model="selectedCategory" class="form-select">
              <option value="">All categories</option>
              <option value="Academic">Academic</option>
              <option value="Non-Academic">Non-Academic</option>
            </select>
          </div>
          <div class="col-md-2">
            <label class="form-label small">Max RM{{ maxPrice }}/hr</label>
            <input v-model="maxPrice" type="range" class="form-range" min="5" max="50" step="1" />
          </div>
        </div>
        <div class="row g-3 mt-0">
          <div class="col-md-3">
            <label class="form-label small">Faculty</label>
            <select v-model="selectedFaculty" class="form-select">
              <option value="">All faculties</option>
              <option v-for="f in faculties" :key="f" :value="f">{{ f }}</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Minimum rating</label>
            <select v-model.number="minRating" class="form-select">
              <option :value="0">Any rating</option>
              <option :value="3">3+ stars</option>
              <option :value="4">4+ stars</option>
              <option :value="4.5">4.5+ stars</option>
            </select>
          </div>
        </div>
        <button class="btn btn-link btn-sm px-0 mt-2" @click="resetFilters">
          Clear filters
        </button>
      </div>
    </div>

    <!-- Error message -->
    <div v-if="error" class="alert alert-danger">
      {{ error }}
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
      <p class="text-muted mt-2">Loading tutors...</p>
    </div>

    <!-- Results -->
    <template v-else>
      <p class="text-muted small">{{ filteredTutors.length }} tutor(s) found</p>
      <div v-if="filteredTutors.length" class="row g-3">
        <div v-for="tutor in filteredTutors" :key="tutor.userskill_id" class="col-md-4">
          <TutorCard :tutor="tutor" />
        </div>
      </div>
      <div v-else class="text-center py-5 text-muted">
        <i class="bi bi-search" style="font-size: 2rem"></i>
        <p class="mt-2">No tutors match your filters. Try widening your search.</p>
      </div>
    </template>

    <!-- Join-a-class confirmation (prepay) -->
    <div v-if="joinTarget" class="modal-backdrop-custom" @click.self="joinTarget = null">
      <div class="card join-modal shadow-lg">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="fw-bold mb-0">Join this class?</h5>
            <button class="btn-close" @click="joinTarget = null"></button>
          </div>

          <div v-if="joinDone" class="alert alert-success mb-0">
            <i class="bi bi-check-circle-fill me-2"></i>Request sent — the tutor will approve it. You'll be notified.
          </div>
          <template v-else>
            <p class="text-muted small mb-2">
              <strong>{{ joinTarget.topic }}</strong> with {{ joinTarget.tutor_name }}<br />
              {{ classWhen(joinTarget) }} · {{ joinTarget.hours }}h
            </p>
            <div v-if="joinError" class="alert alert-danger py-2 small">{{ joinError }}</div>
            <div class="alert alert-warning py-2 small">
              <i class="bi bi-wallet2 me-1"></i>
              <strong>RM{{ Number(joinTarget.next_price).toFixed(2) }}</strong> will be deducted from your wallet now
              (refunded if the tutor declines, and partly refunded as more people join).
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-primary" :disabled="joining" @click="confirmJoin">
                <span v-if="joining" class="spinner-border spinner-border-sm me-2"></span>
                {{ joining ? 'Booking…' : `Confirm & pay RM${Number(joinTarget.next_price).toFixed(2)}` }}
              </button>
              <button class="btn btn-light" @click="joinTarget = null">Cancel</button>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.text-truncate-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.upcoming-card {
  border-top: 3px solid var(--ss-primary) !important;
}
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
.join-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}
</style>