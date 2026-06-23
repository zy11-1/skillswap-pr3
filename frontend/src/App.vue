<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const defaultAvatar = 'https://i.pravatar.cc/150?img=1'

const showNavbar = computed(() => auth.isLoggedIn && !route.meta.guestOnly)

function handleLogout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <nav v-if="showNavbar" class="navbar navbar-expand-lg navbar-dark bg-primary-ss shadow-sm">
    <div class="container">
      <router-link class="navbar-brand" to="/marketplace">
        <i class="bi bi-mortarboard-fill me-2"></i>SkillSwap
      </router-link>
      <button
        class="navbar-toggler"
        type="button"
        data-bs-toggle="collapse"
        data-bs-target="#navMenu"
      >
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="navMenu">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item">
            <router-link class="nav-link" to="/marketplace">Marketplace</router-link>
          </li>
          <li class="nav-item">
            <router-link class="nav-link" to="/bookings">My Bookings</router-link>
          </li>
          <li class="nav-item">
            <router-link class="nav-link" to="/wallet">Wallet</router-link>
          </li>
          <li v-if="auth.isTutor" class="nav-item">
            <router-link class="nav-link" to="/tutor-dashboard">Tutor Dashboard</router-link>
          </li>
          <li v-if="auth.isAdmin" class="nav-item">
            <router-link class="nav-link" to="/admin">Admin</router-link>
          </li>
        </ul>
        <div class="d-flex align-items-center text-white">
          <img
            :src="auth.user?.photo_url || defaultAvatar"
            class="rounded-circle me-2"
            width="32"
            height="32"
            alt="avatar"
          />
          <span class="me-3 small">{{ auth.user?.name }}</span>
          <button class="btn btn-sm btn-outline-light" @click="handleLogout">
            <i class="bi bi-box-arrow-right"></i> Logout
          </button>
        </div>
      </div>
    </div>
  </nav>

  <router-view />
</template>
