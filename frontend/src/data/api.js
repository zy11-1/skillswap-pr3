// src/data/api.js
//
// PR3: real backend integration. This replaces mockApi.js — same
// call shapes where possible, but now talking to the PHP Slim 4 API
// over HTTP instead of reading local JSON files.

import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

export const apiBaseUrl = import.meta.env.VITE_API_URL || 'http://localhost:8080'
const baseURL = apiBaseUrl

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

async getSlotByToken(token) {
  try {
    const res = await http.get(`/api/slots/${token}`)
    return res.data
  } catch (err) {
    throw unwrapError(err)
  }
},

async updateAvailability(availabilityId, data) {
  try {
    const res = await http.patch(`/api/tutor/availability/${availabilityId}`, data)
    return res.data
  } catch (err) {
    throw unwrapError(err)
  }
},

async cancelAvailability(availabilityId, priority) {
  try {
    const res = await http.post(`/api/tutor/availability/${availabilityId}/cancel`, { priority })
    return res.data
  } catch (err) {
    throw unwrapError(err)
  }
},

async deleteAvailability(availabilityId) {
  try {
    const res = await http.delete(`/api/tutor/availability/${availabilityId}`)
    return res.data
  } catch (err) {
    throw unwrapError(err)
  }
},

  // ---------- TUTOR SKILL OFFERINGS ----------
  async getMySkills() {
    const res = await http.get('/api/tutor/skills')
    return res.data
  },

  async addMySkill(payload) {
    try {
      const res = await http.post('/api/tutor/skills', payload)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async updateMySkill(userSkillId, payload) {
    try {
      const res = await http.patch(`/api/tutor/skills/${userSkillId}`, payload)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async deleteMySkill(userSkillId) {
    try {
      const res = await http.delete(`/api/tutor/skills/${userSkillId}`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
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

  async getRecommendedTutors() {
    const res = await http.get('/api/tutors/recommended')
    return res.data
  },

  async getSkills() {
    const res = await http.get('/api/skills')
    return res.data
  },

  async getTrendingSkills() {
    const res = await http.get('/api/skills/trending')
    return res.data
  },

  // ---------- FAVORITES ----------
  async getFavorites() {
    const res = await http.get('/api/favorites')
    return res.data
  },

  async getFavoriteIds() {
    const res = await http.get('/api/favorites/ids')
    return res.data
  },

  async toggleFavorite(tutorId) {
    try {
      const res = await http.post(`/api/favorites/${tutorId}`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- BOOKINGS ----------
  async getBookings(mode = 'learner') {
    const res = await http.get('/api/bookings', { params: { as: mode } })
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

  async setBookingRecording(bookingId, recordingUrl) {
    try {
      const res = await http.patch(`/api/bookings/${bookingId}/recording`, { recording_url: recordingUrl })
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

  // ---------- NOTIFICATIONS ----------
  async getNotifications() {
    const res = await http.get('/api/notifications')
    return res.data
  },

  async markNotificationsRead() {
    const res = await http.post('/api/notifications/read')
    return res.data
  },

  // ---------- MESSAGES ----------
  async getConversations() {
    const res = await http.get('/api/messages')
    return res.data
  },

  async getThread(userId) {
    const res = await http.get(`/api/messages/${userId}`)
    return res.data
  },

  async sendMessage(receiverId, body) {
    try {
      const res = await http.post('/api/messages', { receiver_id: receiverId, body })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- GROUP CLASSES ----------
  async getGroupClasses() {
    const res = await http.get('/api/group-classes')
    return res.data
  },

  async createGroupClass(payload) {
    try {
      const res = await http.post('/api/group-classes', payload)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async enrollGroupClass(classId) {
    try {
      const res = await http.post(`/api/group-classes/${classId}/enroll`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- MERITS (performance-based) ----------
  async getMeritStanding() {
    const res = await http.get('/api/merits/standing')
    return res.data
  },

  async applyForMerit() {
    try {
      const res = await http.post('/api/merits/apply')
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async getMeritRequests() {
    const res = await http.get('/api/admin/merits')
    return res.data
  },

  async reviewMerit(requestId, status) {
    try {
      const res = await http.patch(`/api/admin/merits/${requestId}`, { status })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- VERIFICATION ----------
  async getVerificationStatus() {
    const res = await http.get('/api/verification/me')
    return res.data
  },

  async submitVerification(file) {
    const formData = new FormData()
    formData.append('document', file)
    try {
      const res = await http.post('/api/verification', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async getVerificationRequests() {
    const res = await http.get('/api/admin/verifications/requests')
    return res.data
  },

  async reviewVerification(requestId, status) {
    try {
      const res = await http.patch(`/api/admin/verifications/requests/${requestId}`, { status })
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

  // ---------- ADMIN — users ----------
  async getAllUsers() {
    const res = await http.get('/api/admin/users')
    return res.data
  },

  async updateAdminUser(userId, data) {
    try {
      const res = await http.patch(`/api/admin/users/${userId}`, data)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async deleteAdminUser(userId) {
    try {
      const res = await http.delete(`/api/admin/users/${userId}`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  async getPendingVerifications() {
    const res = await http.get('/api/admin/verifications/pending')
    return res.data
  },

  async verifyTutor(userId) {
    const res = await http.patch(`/api/admin/users/${userId}/verify`)
    return res.data
  },

  // ---------- ADMIN — content moderation ----------
  async getAdminReviews() {
    const res = await http.get('/api/admin/reviews')
    return res.data
  },

  async deleteAdminReview(reviewId) {
    try {
      const res = await http.delete(`/api/admin/reviews/${reviewId}`)
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- ADMIN — disputes ----------
  async getAdminDisputes() {
    const res = await http.get('/api/admin/disputes')
    return res.data
  },

  async resolveDispute(bookingId, resolution) {
    try {
      const res = await http.patch(`/api/admin/disputes/${bookingId}`, { resolution })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  },

  // ---------- ADMIN — stats + bookings ----------
  async getAdminStats() {
    const res = await http.get('/api/admin/stats')
    return res.data
  },

  async getAdminBookings() {
    const res = await http.get('/api/admin/bookings')
    return res.data
  },

  // ---------- DISPUTES (user side) ----------
  async submitDispute(bookingId, reason) {
    try {
      const res = await http.post(`/api/bookings/${bookingId}/dispute`, { reason })
      return res.data
    } catch (err) {
      throw unwrapError(err)
    }
  }
}