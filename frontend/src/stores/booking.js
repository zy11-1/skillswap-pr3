// src/stores/booking.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

// Bookings have two distinct meanings depending on the user's role on each
// booking: "sessions I booked" (as learner) and "sessions I teach" (as tutor).
// We keep them in separate collections so the two datasets can never clobber
// each other, and so a view always binds to a fixed, unambiguous role.
export const useBookingStore = defineStore('booking', {
  state: () => ({
    learnerBookings: [],   // sessions I booked (?as=learner)
    tutorBookings: [],     // sessions I teach (?as=tutor)
    loadingLearner: false,
    loadingTutor: false
  }),

  actions: {
    async fetchAsLearner() {
      this.loadingLearner = true
      try {
        const res = await api.getBookings('learner')
        this.learnerBookings = res.data || []
      } finally {
        this.loadingLearner = false
      }
    },

    async fetchAsTutor() {
      this.loadingTutor = true
      try {
        const res = await api.getBookings('tutor')
        this.tutorBookings = res.data || []
      } finally {
        this.loadingTutor = false
      }
    },

    async createBooking(payload) {
      const res = await api.createBooking(payload)
      // Booking is always a learner action, so only the learner list grows.
      this.learnerBookings.push(res.data)
      return res.data
    },

    async updateStatus(bookingId, status) {
      const res = await api.updateBookingStatus(bookingId, status)
      // Accept/Complete happens from the teaching (tutor) list.
      const idx = this.tutorBookings.findIndex((b) => b.booking_id === bookingId)
      if (idx !== -1) this.tutorBookings[idx] = { ...this.tutorBookings[idx], ...res.data }
      return res.data
    }
  }
})
