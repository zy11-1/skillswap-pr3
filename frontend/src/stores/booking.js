// src/stores/booking.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

export const useBookingStore = defineStore('booking', {
  state: () => ({
    bookings: [],
    loading: false
  }),

  actions: {
    async fetchBookings() {
      this.loading = true
      try {
        const res = await api.getBookings()
        this.bookings = res.data
      } finally {
        this.loading = false
      }
    },

    async createBooking(payload) {
      const res = await api.createBooking(payload)
      this.bookings.push(res.data)
      return res.data
    },

    async updateStatus(bookingId, status) {
      const res = await api.updateBookingStatus(bookingId, status)
      const idx = this.bookings.findIndex((b) => b.booking_id === bookingId)
      if (idx !== -1) this.bookings[idx] = { ...this.bookings[idx], ...res.data }
      return res.data
    }
  }
})
