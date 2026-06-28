// src/data/api.js
//
// PR3: real backend integration. This replaces mockApi.js — same
// call shapes where possible, but now talking to the PHP Slim 4 API
// over HTTP instead of reading local JSON files.

import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

const baseURL = import.meta.env.VITE_API_URL || 'http://localhost:8080'

const http = axios.create({
  baseURL,
  headers: { 'Content-Type': 'application/json' }
})

// Attach the JWT to every request automatically, once the user is
// logged in. Pinia can't be used until the app is mounted, so we
// read straight from localStorage here (same place the auth store
// persists the token).
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('ss_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// If the backend ever returns 401 (expired/invalid token), log the
// user out and send them back to the login screen.
http.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const auth = useAuthStore()
      auth.logout()
      window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

function unwrapError(error) {
  const message = error.response?.data?.error || error.response?.data?.errors?.[0] || error.message
  return new Error(message)
}

export const api = {
  // ---------- TUTOR AVAILABILITY ----------
async getTutorAvailability(tutorId) {
  const res = await http.get(`/api/tutors/${tutorId}/availability`)
  return res.data
},

async addAvailability(data) {
  const res = await http.post('/api/tutor/availability', data)
  return res.data
},
  // ---------- AUTH ----------
  async login(email, password) {
    try {
      const res = await http.post('/api/auth/login', { email, password })
      return res.data // { token, user }
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async register(payload) {
    try {
      const res = await http.post('/api/auth/register', payload)
      return res.data // { token, user }
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async me() {
    const res = await http.get('/api/auth/me')
    return res.data
  },

  // ---------- TUTORS / MARKETPLACE ----------
  async getTutors(filters = {}) {
    const params = {}
    if (filters.search) params.search = filters.search
    if (filters.skillId) params.skill_id = filters.skillId
    if (filters.category) params.category = filters.category
    if (filters.maxPrice) params.max_price = filters.maxPrice

    const res = await http.get('/api/tutors', { params })
    return res.data
  },

  async getTutorById(userId) {
    const res = await http.get(`/api/tutors/${userId}`)
    return res.data
  },

  async getSkills() {
    const res = await http.get('/api/skills')
    return res.data
  },

  // ---------- BOOKINGS ----------
  async getBookings() {
    const res = await http.get('/api/bookings')
    return res.data
  },

  async createBooking(booking) {
    try {
      const res = await http.post('/api/bookings', booking)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async updateBookingStatus(bookingId, status) {
    try {
      const res = await http.patch(`/api/bookings/${bookingId}/status`, { status })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- REVIEWS ----------
  async getTutorReviews(tutorId) {
    const res = await http.get(`/api/tutors/${tutorId}/reviews`)
    return res.data
  },

  async createReview(payload) {
    try {
      const res = await http.post('/api/reviews', payload)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async updateReview(reviewId, payload) {
    try {
      const res = await http.patch(`/api/reviews/${reviewId}`, payload)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async deleteReview(reviewId) {
    try {
      const res = await http.delete(`/api/reviews/${reviewId}`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- WALLET ----------
  async getWalletBalance() {
    const res = await http.get('/api/wallet')
    return res.data
  },

  async getWalletTransactions() {
    const res = await http.get('/api/wallet/transactions')
    return res.data
  },

  // ---------- ADMIN ----------
  async getAllUsers() {
    const res = await http.get('/api/admin/users')
    return res.data
  },

  async getPendingVerifications() {
    const res = await http.get('/api/admin/verifications/pending')
    return res.data
  },

  async verifyTutor(userId) {
    const res = await http.patch(`/api/admin/users/${userId}/verify`)
    return res.data
  }
}