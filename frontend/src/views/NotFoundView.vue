<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

// Send people somewhere useful: the home that matches their current hat,
// or the login screen if they're not signed in.
const homeLink = computed(() => {
  if (!auth.isLoggedIn) return '/login'
  if (auth.isAdmin) return '/admin'
  return auth.isTutorMode ? '/tutor-dashboard' : '/marketplace'
})
</script>

<template>
  <div class="container py-5 text-center">
    <i class="bi bi-compass text-primary-ss" style="font-size: 3rem"></i>
    <h3 class="fw-bold mt-3">Page not found</h3>
    <p class="text-muted">
      The page you're looking for doesn't exist or may have moved.
    </p>
    <router-link :to="homeLink" class="btn btn-primary mt-2">
      <i class="bi bi-house-door me-1"></i>Back to home
    </router-link>
  </div>
</template>
