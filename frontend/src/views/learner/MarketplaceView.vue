<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/data/api'
import TutorCard from '@/components/tutor/TutorCard.vue'

const tutors = ref([])
const skills = ref([])
const trending = ref([])
const loading = ref(true)
const error = ref(null)

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
    const [tutorsRes, skillsRes, trendingRes] = await Promise.all([
      api.getTutors(),
      api.getSkills(),
      api.getTrendingSkills()
    ])

    // api.getTutors() returns { data: [...] }
    tutors.value = tutorsRes.data || []
    skills.value = skillsRes.data || []
    trending.value = trendingRes.data || []
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
  </div>
</template>