// src/stores/auth.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('ss_token') || null,
    user: JSON.parse(localStorage.getItem('ss_user') || 'null'),
    // The active "mode" of a dual-role account. Any non-admin user can
    // switch between learning and tutoring; this is just UI state.
    activeMode: localStorage.getItem('ss_mode') || 'learner'
  }),

  getters: {
    isLoggedIn: (state) => !!state.token,
    isAdmin: (state) => state.user?.role === 'admin',
    // Learner/Tutor are now modes you toggle, not fixed identities.
    isLearnerMode: (state) => state.activeMode === 'learner',
    isTutorMode: (state) => state.activeMode === 'tutor'
  },

  actions: {
    async login(email, password) {
      const { token, user } = await api.login(email, password)
      this.setSession(token, user)
      return user
    },

    async register(payload) {
      const { token, user } = await api.register(payload)
      this.setSession(token, user)
      return user
    },

    setSession(token, user) {
      this.token = token
      this.user = user
      localStorage.setItem('ss_token', token)
      localStorage.setItem('ss_user', JSON.stringify(user))
      // Start in the mode that matches how they registered, but they're
      // free to switch afterwards. Admins stay in learner mode by default.
      this.setMode(user?.role === 'tutor' ? 'tutor' : 'learner')
    },

    setMode(mode) {
      this.activeMode = mode === 'tutor' ? 'tutor' : 'learner'
      localStorage.setItem('ss_mode', this.activeMode)
    },

    logout() {
      this.token = null
      this.user = null
      this.activeMode = 'learner'
      localStorage.removeItem('ss_token')
      localStorage.removeItem('ss_user')
      localStorage.removeItem('ss_mode')
    }
  }
})
