// src/data/mockApi.js
//
// PR2 NOTE:
// For this Interim Build, we don't have the PHP Slim backend running yet.
// Instead, we simulate API calls using axios + local JSON files, with an
// artificial delay so the app behaves like it's talking to a real server
// (loading states, async/await, etc.) This will be swapped for real
// axios calls to our Slim 4 REST API in PR3.

import axios from 'axios'

import usersData from './users.json'
import skillsData from './skills.json'
import userSkillsData from './userSkills.json'
import bookingsData from './bookings.json'
import reviewsData from './reviews.json'

// Simulated network delay so loading spinners are visible, like a real API
const DELAY_MS = 400

function mockResponse(payload) {
  return new Promise((resolve) => {
    setTimeout(() => {
      // axios.get() normally returns { data: ... }, so we mimic that shape
      resolve({ data: JSON.parse(JSON.stringify(payload)) })
    }, DELAY_MS)
  })
}

// In-memory copies so "writes" (booking, login) persist for the session
let users = [...usersData]
let userSkills = [...userSkillsData]
let bookings = [...bookingsData]
let reviews = [...reviewsData]
const skills = [...skillsData]

export const mockApi = {
  // ---------- AUTH ----------
  async login(email, password) {
    await mockResponse(null)
    const user = users.find((u) => u.email === email && u.password === password)
    if (!user) {
      throw new Error('Invalid email or password')
    }
    // Pretend a JWT was issued by the backend
    const fakeToken = btoa(`${user.user_id}:${user.role}:${Date.now()}`)
    return { token: fakeToken, user: { ...user, password: undefined } }
  },

  async register(newUser) {
    await mockResponse(null)
    const exists = users.some((u) => u.email === newUser.email)
    if (exists) {
      throw new Error('Email already registered')
    }
    const user = {
      user_id: users.length + 1,
      wallet_balance: 0,
      is_verified: 0,
      photo_url: 'https://i.pravatar.cc/150?img=1',
      bio: '',
      ...newUser
    }
    users.push(user)
    const fakeToken = btoa(`${user.user_id}:${user.role}:${Date.now()}`)
    return { token: fakeToken, user: { ...user, password: undefined } }
  },

  // ---------- TUTORS / MARKETPLACE ----------
  async getTutors() {
    const res = await mockResponse(userSkills)
    // join UserSkill -> User + Skill, like a real API would
    const joined = res.data.map((us) => {
      const user = users.find((u) => u.user_id === us.user_id)
      const skill = skills.find((s) => s.skill_id === us.skill_id)
      const tutorReviews = reviews.filter((r) => {
        const booking = bookings.find((b) => b.booking_id === r.booking_id)
        return booking && booking.tutor_id === us.user_id
      })
      const avgRating = tutorReviews.length
        ? (tutorReviews.reduce((sum, r) => sum + r.rating, 0) / tutorReviews.length).toFixed(1)
        : null

      return {
        ...us,
        tutor_name: user?.name,
        tutor_photo: user?.photo_url,
        tutor_faculty: user?.faculty,
        is_verified: user?.is_verified,
        skill_name: skill?.name,
        skill_category: skill?.category,
        avg_rating: avgRating
      }
    })
    return { data: joined }
  },

  async getTutorById(userId) {
    await mockResponse(null)
    const user = users.find((u) => u.user_id === Number(userId))
    if (!user) throw new Error('Tutor not found')
    const offerings = userSkills
      .filter((us) => us.user_id === Number(userId))
      .map((us) => ({
        ...us,
        skill_name: skills.find((s) => s.skill_id === us.skill_id)?.name
      }))
    const tutorReviews = reviews.filter((r) => {
      const booking = bookings.find((b) => b.booking_id === r.booking_id)
      return booking && booking.tutor_id === Number(userId)
    })
    return { data: { ...user, password: undefined, offerings, reviews: tutorReviews } }
  },

  async getSkills() {
    return mockResponse(skills)
  },

  // ---------- BOOKINGS ----------
  async getBookingsForUser(userId, role) {
    await mockResponse(null)
    const key = role === 'tutor' ? 'tutor_id' : 'learner_id'
    const list = bookings
      .filter((b) => b[key] === Number(userId))
      .map((b) => {
        const tutor = users.find((u) => u.user_id === b.tutor_id)
        const learner = users.find((u) => u.user_id === b.learner_id)
        const skill = skills.find((s) => s.skill_id === b.skill_id)
        return {
          ...b,
          tutor_name: tutor?.name,
          learner_name: learner?.name,
          skill_name: skill?.name
        }
      })
    return { data: list }
  },

  async createBooking(booking) {
    await mockResponse(null)
    const newBooking = {
      booking_id: bookings.length + 1,
      status: 'Pending',
      recording_url: null,
      ...booking
    }
    bookings.push(newBooking)
    return { data: newBooking }
  },

  async updateBookingStatus(bookingId, status) {
    await mockResponse(null)
    const booking = bookings.find((b) => b.booking_id === Number(bookingId))
    if (!booking) throw new Error('Booking not found')
    booking.status = status
    return { data: booking }
  },

  // ---------- WALLET ----------
  async getWalletBalance(userId) {
    await mockResponse(null)
    const user = users.find((u) => u.user_id === Number(userId))
    return { data: { balance: user?.wallet_balance ?? 0 } }
  },

  // ---------- ADMIN ----------
  async getAllUsers() {
    return mockResponse(users.map((u) => ({ ...u, password: undefined })))
  },

  async getPendingVerifications() {
    await mockResponse(null)
    const pending = users.filter((u) => u.role === 'tutor' && !u.is_verified)
    return { data: pending.map((u) => ({ ...u, password: undefined })) }
  },

  async verifyTutor(userId) {
    await mockResponse(null)
    const user = users.find((u) => u.user_id === Number(userId))
    if (user) user.is_verified = 1
    return { data: user }
  }
}

// Example of how this gets swapped for a real backend call in PR3:
//
// async getTutors() {
//   return axios.get(`${import.meta.env.VITE_API_URL}/api/tutors`, {
//     headers: { Authorization: `Bearer ${token}` }
//   })
// }
