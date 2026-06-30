<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function handleSubmit() {
  error.value = ''

  if (!email.value || !password.value) {
    error.value = 'Please fill in both email and password.'
    return
  }

  loading.value = true
  try {
    await auth.login(email.value, password.value)
    if (route.query.redirect) {
      router.push(String(route.query.redirect))
    } else if (auth.isAdmin) {
      router.push('/admin')
    } else {
      router.push('/marketplace')
    }
  } catch (err) {
    error.value = err.message || 'Login failed. Please try again.'
  } finally {
    loading.value = false
  }
}

function fillDemo(roleEmail, rolePassword) {
  email.value = roleEmail
  password.value = rolePassword
}
</script>

<template>
  <div class="auth-shell">
    <div class="card auth-card p-4">
      <div class="card-body">
        <div class="text-center mb-4">
          <i class="bi bi-mortarboard-fill text-primary-ss" style="font-size: 2.5rem"></i>
          <h3 class="fw-bold mt-2">Welcome back</h3>
          <p class="text-muted">Log in to SkillSwap</p>
        </div>

        <div v-if="error" class="alert alert-danger py-2">{{ error }}</div>

        <form @submit.prevent="handleSubmit">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input
              v-model="email"
              type="email"
              class="form-control"
              placeholder="you@graduate.utm.my"
              required
            />
          </div>
          <div class="mb-3">
            <label class="form-label">Password</label>
            <input
              v-model="password"
              type="password"
              class="form-control"
              placeholder="••••••••"
              required
            />
          </div>
          <button type="submit" class="btn btn-primary w-100" :disabled="loading">
            <span v-if="loading" class="spinner-border spinner-border-sm me-2"></span>
            {{ loading ? 'Logging in...' : 'Log In' }}
          </button>
        </form>

        <p class="text-center mt-3 mb-0 small">
          Don't have an account?
          <router-link to="/register">Sign up</router-link>
        </p>

        <hr />
        <p class="small text-muted mb-1">Quick demo accounts:</p>
        <div class="d-flex flex-wrap gap-2">
          <button
            class="btn btn-sm btn-outline-secondary"
            @click="fillDemo('aisyah@graduate.utm.my', 'password123')"
          >
            Tutor
          </button>
          <button
            class="btn btn-sm btn-outline-secondary"
            @click="fillDemo('zhengyi@graduate.utm.my', 'password123')"
          >
            Learner
          </button>
          <button
            class="btn btn-sm btn-outline-secondary"
            @click="fillDemo('admin@skillswap.my', 'admin123')"
          >
            Admin
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
