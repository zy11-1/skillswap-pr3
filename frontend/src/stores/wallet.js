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
        this.balance = res.data.balance
      } finally {
        this.loading = false
      }
    },

    async fetchTransactions() {
      const res = await api.getWalletTransactions()
      this.transactions = res.data
    }
  }
})
