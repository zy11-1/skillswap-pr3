<script setup>
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { api } from './data/api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const defaultAvatar = 'https://i.pravatar.cc/150?img=1'

// ---- Notification center (bell) ----
const notifOpen = ref(false)
const unreadCount = ref(0)
const notifItems = ref([])
const notifFilter = ref('all')   // 'all' | 'chat' | 'booking'
let notifTimer = null

async function loadNotifications() {
  if (!auth.isLoggedIn) {
    unreadCount.value = 0
    notifItems.value = []
    return
  }
  try {
    const res = await api.getNotifications()
    unreadCount.value = res.data.unread_count
    notifItems.value = res.data.items
  } catch {
    /* ignore transient errors */
  }
}

const filteredNotifs = computed(() => {
  if (notifFilter.value === 'all') return notifItems.value
  return notifItems.value.filter((n) => (n.category || 'chat') === notifFilter.value)
})

function notifIcon(category) {
  return {
    chat: 'bi-chat-dots',
    booking: 'bi-calendar-check',
    system: 'bi-info-circle',
    marketplace: 'bi-shop'
  }[category] || 'bi-bell'
}

function toggleNotif() {
  notifOpen.value = !notifOpen.value
  if (notifOpen.value) loadNotifications()
}

async function markAllRead() {
  try {
    await api.markNotificationsRead()
    unreadCount.value = 0
    notifItems.value = notifItems.value.map((n) => ({ ...n, is_read: 1 }))
  } catch {
    /* ignore */
  }
}

// Clicking a notification routes by type: chat opens the thread; booking/
// system/marketplace go to the relevant screen. Either way it's marked read.
function openNotif(item) {
  notifOpen.value = false
  const category = item?.category || 'chat'
  if (category === 'chat') {
    router.push(item
      ? { name: 'messages', query: { to: item.sender_id, name: item.sender_name } }
      : { name: 'messages' })
  } else if (category === 'marketplace') {
    router.push('/marketplace')
  } else {
    router.push('/bookings') // booking & system → My Classes
  }
  markAllRead()
}

function openMessages() {
  notifOpen.value = false
  router.push('/messages')
}

onMounted(() => {
  loadNotifications()
  notifTimer = setInterval(loadNotifications, 20000)
})
onUnmounted(() => clearInterval(notifTimer))
// Refresh the count whenever the page changes (e.g. after reading messages).
watch(() => route.fullPath, loadNotifications)

const showNavbar = computed(() => auth.isLoggedIn && !route.meta.guestOnly)

// Accent theme follows the active "hat": admin = slate, tutor = blue, learner = green.
const themeClass = computed(() => {
  if (auth.isAdmin) return 'theme-admin'
  return auth.isTutorMode ? 'theme-tutor' : 'theme-learner'
})

// Brand click goes to the home that matches the current hat.
const homeLink = computed(() => {
  if (auth.isAdmin) return '/admin'
  return auth.isTutorMode ? '/tutor-dashboard' : '/marketplace'
})

function switchMode(mode) {
  auth.setMode(mode)
  // Send the user to the home screen of the mode they picked.
  router.push(mode === 'tutor' ? '/tutor-dashboard' : '/marketplace')
}

function handleLogout() {
  auth.logout()
  router.push('/login')
}
</script>

<template>
  <div :class="themeClass">
  <nav v-if="showNavbar" class="navbar navbar-expand-lg navbar-dark bg-primary-ss shadow-sm">
    <div class="container">
      <router-link class="navbar-brand" :to="homeLink">
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
          <!-- Mode-relevant primary links only -->
          <li v-if="!auth.isAdmin && auth.isLearnerMode" class="nav-item">
            <router-link class="nav-link" to="/marketplace">Marketplace</router-link>
          </li>
          <li v-if="!auth.isAdmin" class="nav-item">
            <router-link class="nav-link" to="/bookings">My Classes</router-link>
          </li>
          <li v-if="!auth.isAdmin && auth.isTutorMode" class="nav-item">
            <router-link class="nav-link" to="/tutor-dashboard">Tutor Dashboard</router-link>
          </li>
          <li v-if="auth.isAdmin" class="nav-item">
            <router-link class="nav-link" to="/admin">Admin</router-link>
          </li>
        </ul>

        <!-- Learner / Tutor mode toggle: one account, two modes, shared wallet -->
        <div v-if="!auth.isAdmin" class="btn-group btn-group-sm me-3" role="group" aria-label="Mode">
          <button
            type="button"
            class="btn"
            :class="auth.isLearnerMode ? 'btn-light' : 'btn-outline-light'"
            @click="switchMode('learner')"
          >
            <i class="bi bi-mortarboard me-1"></i>Learner
          </button>
          <button
            type="button"
            class="btn"
            :class="auth.isTutorMode ? 'btn-light' : 'btn-outline-light'"
            @click="switchMode('tutor')"
          >
            <i class="bi bi-easel me-1"></i>Tutor
          </button>
        </div>

        <div class="d-flex align-items-center text-white">
          <!-- Always-available utilities as icons -->
          <router-link to="/messages" class="btn btn-sm btn-outline-light me-2" title="Messages" aria-label="Messages">
            <i class="bi bi-chat-dots"></i>
          </router-link>
          <router-link to="/wallet" class="btn btn-sm btn-outline-light me-2" title="Wallet" aria-label="Wallet">
            <i class="bi bi-wallet2"></i>
          </router-link>

          <!-- Notification bell -->
          <div class="position-relative me-3">
            <button class="btn btn-sm btn-outline-light position-relative" @click="toggleNotif" aria-label="Notifications">
              <i class="bi bi-bell"></i>
              <span
                v-if="unreadCount"
                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              >
                {{ unreadCount > 9 ? '9+' : unreadCount }}
              </span>
            </button>
            <template v-if="notifOpen">
              <div class="notif-backdrop" @click="notifOpen = false"></div>
              <div class="notif-panel card shadow">
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-2">
                  <span class="fw-bold small text-dark">Notifications</span>
                  <button class="btn btn-sm btn-link p-0" @click="markAllRead">Mark all read</button>
                </div>

                <!-- Filter tabs: All, Messages (chat), Bookings -->
                <div class="btn-group btn-group-sm w-100 p-2 bg-white border-bottom" role="group">
                  <button class="btn" :class="notifFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary'" @click="notifFilter = 'all'">
                    All
                  </button>
                  <button class="btn" :class="notifFilter === 'chat' ? 'btn-primary' : 'btn-outline-secondary'" @click="notifFilter = 'chat'" title="Class messages">
                    <i class="bi bi-chat-dots"></i> Messages
                  </button>
                  <button class="btn" :class="notifFilter === 'booking' ? 'btn-primary' : 'btn-outline-secondary'" @click="notifFilter = 'booking'" title="Booking updates">
                    <i class="bi bi-calendar-check"></i> Bookings
                  </button>
                </div>

                <div class="notif-list">
                  <button
                    v-for="n in filteredNotifs"
                    :key="n.message_id"
                    class="list-group-item list-group-item-action text-start border-0 border-bottom d-flex gap-2"
                    :class="{ 'bg-light': !n.is_read }"
                    @click="openNotif(n)"
                  >
                    <i :class="['bi', notifIcon(n.category), 'mt-1', n.category === 'booking' ? 'text-success' : n.category === 'chat' ? 'text-primary' : 'text-secondary']"></i>
                    <span class="flex-grow-1 overflow-hidden">
                      <span class="small text-dark">
                        <strong>{{ n.sender_name }}</strong>
                        <span v-if="!n.is_read" class="badge bg-primary-ss ms-1">new</span>
                      </span>
                      <span class="small text-muted d-block text-truncate">{{ n.body }}</span>
                    </span>
                  </button>
                  <p v-if="!filteredNotifs.length" class="text-muted small p-3 mb-0">
                    {{ notifFilter === 'all' ? 'No notifications yet.' : `No ${notifFilter === 'chat' ? 'message' : notifFilter} notifications.` }}
                  </p>
                </div>
              </div>
            </template>
          </div>

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
  </div>
</template>

<style scoped>
.notif-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1055;
}
.notif-panel {
  position: absolute;
  right: 0;
  top: 120%;
  width: 320px;
  z-index: 1060;
  border: none;
  border-radius: 12px;
  overflow: hidden;
}
.notif-list {
  max-height: 360px;
  overflow-y: auto;
  background: #fff;
}
</style>
