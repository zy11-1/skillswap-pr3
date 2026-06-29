<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const form = ref({
  name: '',
  email: '',
  password: '',
  confirmPassword: '',
  faculty: '',
  photo_url: 'https://i.pravatar.cc/150?img=1'
})

const avatarOptions = [
  'https://i.pravatar.cc/150?img=1',
  'https://i.pravatar.cc/150?img=33',
  'https://i.pravatar.cc/150?img=44',
  'https://i.pravatar.cc/150?img=58'
]

const error = ref('')
const loading = ref(false)

const faculties = [
  'Computing',
  'Engineering',
  'Built Environment & Surveying',
  'Science',
  'Management',
  'Social Sciences & Humanities'
]

async function handleSubmit() {
  error.value = ''

  if (!form.value.name || !form.value.email || !form.value.password || !form.value.faculty) {
    error.value = 'Please fill in all required fields.'
    return
  }

  if (form.value.password.length < 6) {
    error.value = 'Password must be at least 6 characters.'
    return
  }

  if (form.value.password !== form.value.confirmPassword) {
    error.value = 'Passwords do not match.'
    return
  }

  loading.value = true
  try {
    await auth.register({
      name: form.value.name,
      email: form.value.email,
      password: form.value.password,
      faculty: form.value.faculty,
      photo_url: form.value.photo_url
    })
    router.push('/marketplace')
  } catch (err) {
    error.value = err.message || 'Registration failed.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="auth-shell">
    <div class="card auth-card p-4">
      <div class="card-body">
        <div class="text-center mb-4">
          <i class="bi bi-person-plus-fill text-primary-ss" style="font-size: 2.5rem"></i>
          <h3 class="fw-bold mt-2">Create your account</h3>
          <p class="text-muted">One student account — learn and tutor from the same login</p>
        </div>

        <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

        <form @submit.prevent="handleSubmit">
          <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input v-model="form.name" type="text" class="form-control" required />
          </div>

          <div class="mb-3">
            <label class="form-label">Email</label>
            <input
              v-model="form.email"
              type="email"
              class="form-control"
              placeholder="you@graduate.utm.my"
              required
            />
          </div>

          <div class="mb-3">
            <label class="form-label">Faculty</label>
            <select v-model="form.faculty" class="form-select" required>
              <option value="" disabled>Select your faculty</option>
              <option v-for="f in faculties" :key="f" :value="f">{{ f }}</option>
            </select>
          </div>

          <div class="alert alert-light border small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Your account can both <strong>learn</strong> and <strong>tutor</strong>. Switch anytime
            with the Learner/Tutor toggle, and add skills from your Tutor dashboard to appear in the marketplace.
          </div>

          <div class="mb-3">
            <label class="form-label d-block">Choose an avatar</label>
            <div class="d-flex gap-2">
              <img
                v-for="avatar in avatarOptions"
                :key="avatar"
                :src="avatar"
                class="rounded-circle avatar-choice"
                :class="{ 'avatar-choice-selected': form.photo_url === avatar }"
                width="48"
                height="48"
                alt="avatar option"
                @click="form.photo_url = avatar"
              />
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Password</label>
            <input v-model="form.password" type="password" class="form-control" required />
          </div>

          <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input v-model="form.confirmPassword" type="password" class="form-control" required />
          </div>

          <button type="submit" class="btn btn-primary w-100" :disabled="loading">
            <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
            {{ loading ? 'Creating account...' : 'Sign Up' }}
          </button>
        </form>

        <p class="text-center mt-3 mb-0 small">
          Already have an account?
          <router-link to="/login">Log in</router-link>
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.avatar-choice {
  cursor: pointer;
  border: 3px solid transparent;
  opacity: 0.6;
  transition: all 0.15s ease;
}

.avatar-choice:hover {
  opacity: 0.85;
}

.avatar-choice-selected {
  border-color: var(--ss-primary);
  opacity: 1;
}
</style>
