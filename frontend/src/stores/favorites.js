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
      const res = await api.toggleFavorite(id)
      if (res.data.favorited) {
        if (!this.ids.includes(id)) this.ids.push(id)
      } else {
        this.ids = this.ids.filter((x) => x !== id)
      }
      return res.data.favorited
    }
  }
})
