// src/stores/favorites.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

export const useFavoritesStore = defineStore('favorites', {
  state: () => ({
    ids: []   // tutor_ids the current user has favourited
  }),
  getters: {
    isFavorite: (state) => (tutorId) => state.ids.includes(Number(tutorId))
  },
  actions: {
    async fetchIds() {
      try {
        const res = await api.getFavoriteIds()
        this.ids = (res.data || []).map(Number)
      } catch {
        this.ids = []
      }
    },
    async toggle(tutorId) {
      const id = Number(tutorId)
      // Optimistic: flip the heart immediately so the UI feels instant,
      // then reconcile with the server (rolling back if the call fails).
      const wasFavorite = this.ids.includes(id)
      if (wasFavorite) {
        this.ids = this.ids.filter((x) => x !== id)
      } else {
        this.ids.push(id)
      }
      try {
        const res = await api.toggleFavorite(id)
        // Reconcile with the server's authoritative result.
        if (res.data.favorited) {
          if (!this.ids.includes(id)) this.ids.push(id)
        } else {
          this.ids = this.ids.filter((x) => x !== id)
        }
        return res.data.favorited
      } catch (err) {
        // Roll back to the pre-click state and let the caller surface the error.
        this.ids = wasFavorite
          ? [...this.ids.filter((x) => x !== id), id]
          : this.ids.filter((x) => x !== id)
        throw err
      }
    }
  }
})
