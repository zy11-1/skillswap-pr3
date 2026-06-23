<script setup>
import { ref, computed, onMounted } from 'vue'
import { api } from '@/data/api'
import TutorCard from '@/components/tutor/TutorCard.vue'

const tutors = ref([])
const skills = ref([])
const loading = ref(true)
const error = ref(null)

const searchQuery = ref('')
const selectedSkill = ref('')
const selectedCategory = ref('')
const maxPrice = ref(50)

async function loadData() {
  loading.value = true
  error.value = null

  try {
    const [tutorsRes, skillsRes] = await Promise.all([
      api.getTutors(),
      api.getSkills()
    ])

    // api.getTutors() 返回 { data: [...] }
    tutors.value = tutorsRes.data || []
    skills.value = skillsRes.data || []
  } catch (err) {
    error.value = '加载数据失败，请检查后端是否运行'
    console.error('加载失败:', err)
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
    const matchesPrice = t.hourly_rate <= maxPrice.value

    return matchesSearch && matchesSkill && matchesCategory && matchesPrice
  })
})

function resetFilters() {
  searchQuery.value = ''
  selectedSkill.value = ''
  selectedCategory.value = ''
  maxPrice.value = 50
}
</script>

<template>
  <div class="container py-4">
    <div class="mb-4">
      <h3 class="fw-bold">Find a Tutor</h3>
      <p class="text-muted">Browse skills offered by your fellow students</p>
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
        <button class="btn btn-link btn-sm px-0 mt-2" @click="resetFilters">
          Clear filters
        </button>
      </div>
    </div>

    <!-- 错误提示 -->
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