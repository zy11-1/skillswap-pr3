// src/stores/auth.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('ss_token') || null,
    user: JSON.parse(localStorage.getItem('ss_user') || 'null')
  }),

  getters: {
    isLoggedIn: (state) => !!state.token,
    isLearner: (state) => state.user?.role === 'learner',
    isTutor: (state) => state.user?.role === 'tutor',
    isAdmin: (state) => state.user?.role === 'admin'
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
    },

    logout() {
      this.token = null
      this.user = null
      localStorage.removeItem('ss_token')
      localStorage.removeItem('ss_user')
    }
  }
})
