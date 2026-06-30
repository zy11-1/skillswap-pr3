// src/stores/wallet.js
import { defineStore } from 'pinia'
import { api } from '@/data/api'

export const useWalletStore = defineStore('wallet', {
  state: () => ({
    balance: 0,
    transactions: [],
    loading: false
  }),

  actions: {
    async fetchBalance() {
      this.loading = true
      try {
        const res = await api.getWalletBalance()
        // Cast at the boundary: MySQL DECIMAL can arrive as a string, which
        // would break .toFixed() and turn the earned/spent sums into string
        // concatenation. Keep amounts numeric everywhere downstream.
        this.balance = Number(res.data.balance) || 0
      } finally {
        this.loading = false
      }
    },

    async fetchTransactions() {
      const res = await api.getWalletTransactions()
      this.transactions = (res.data || []).map((t) => ({ ...t, amount: Number(t.amount) || 0 }))
    }
  }
})
