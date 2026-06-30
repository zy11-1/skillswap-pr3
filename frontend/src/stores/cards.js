// src/stores/cards.js
//
// Linked bank/debit cards for wallet top-up and withdrawal. This is demo
// scaffolding: cards live only in the browser (localStorage) and no real
// payment network is contacted. It gives the wallet a realistic "linked
// card" surface that a real gateway (Stripe, FPX, etc.) could later replace.
import { defineStore } from 'pinia'

const STORAGE_KEY = 'ss_cards'

function load() {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]')
  } catch {
    return []
  }
}

export const useCardsStore = defineStore('cards', {
  state: () => ({
    cards: load()
  }),
  actions: {
    persist() {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.cards))
    },
    // Store only the last 4 digits — never keep a full card number, even in a mock.
    addCard({ number, holder, expiry }) {
      const digits = (number || '').replace(/\D/g, '')
      const card = {
        id: Date.now(),
        last4: digits.slice(-4) || '0000',
        holder: holder || 'Cardholder',
        expiry: expiry || '12/29',
        brand: digits.startsWith('4') ? 'Visa' : digits.startsWith('5') ? 'Mastercard' : 'Card'
      }
      this.cards.push(card)
      this.persist()
      return card
    },
    removeCard(id) {
      this.cards = this.cards.filter((c) => c.id !== id)
      this.persist()
    }
  }
})
