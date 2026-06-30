// src/router/index.js
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/', redirect: '/login' },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/auth/LoginView.vue'),
    meta: { guestOnly: true }
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/auth/RegisterView.vue'),
    meta: { guestOnly: true }
  },
  {
    path: '/marketplace',
    name: 'marketplace',
    component: () => import('@/views/learner/MarketplaceView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/upcoming',
    name: 'upcoming-classes',
    component: () => import('@/views/learner/UpcomingClassesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/tutor/:id',
    name: 'tutor-profile',
    component: () => import('@/views/learner/TutorProfileView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/bookings',
    name: 'bookings',
    component: () => import('@/views/learner/BookingsView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/wallet',
    name: 'wallet',
    component: () => import('@/views/learner/WalletView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/messages',
    name: 'messages',
    component: () => import('@/views/MessagesView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/calendar',
    name: 'calendar',
    component: () => import('@/views/CalendarView.vue'),
    meta: { requiresAuth: true }
  },
  {
    path: '/slot/:token',
    name: 'slot-invite',
    component: () => import('@/views/SlotInviteView.vue')
    // Public: anyone with the invite link can view; booking prompts login.
  },
  {
    path: '/tutor-dashboard',
    name: 'tutor-dashboard',
    component: () => import('@/views/tutor/TutorDashboardView.vue'),
    meta: { requiresAuth: true, tutorMode: true }
  },
  {
    path: '/admin',
    name: 'admin-dashboard',
    component: () => import('@/views/admin/AdminDashboardView.vue'),
    meta: { requiresAuth: true, role: 'admin' }
  },
  {
    // Catch-all: any unknown URL renders a friendly 404 instead of a blank page.
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue')
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

// Route guard: redirect unauthenticated users to login,
// and keep logged-in users out of the login/register screens
router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isLoggedIn) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isLoggedIn) {
    return auth.isAdmin ? { name: 'admin-dashboard' } : { name: 'marketplace' }
  }

  if (to.meta.role && auth.user?.role !== to.meta.role) {
    return auth.isAdmin ? { name: 'admin-dashboard' } : { name: 'marketplace' }
  }

  // Tutor-only screens require being in tutor mode (admins excepted).
  if (to.meta.tutorMode && !auth.isTutorMode) {
    return auth.isAdmin ? { name: 'admin-dashboard' } : { name: 'marketplace' }
  }

  return true
})

export default router
