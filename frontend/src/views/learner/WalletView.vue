<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useWalletStore } from '@/stores/wallet'

const auth = useAuthStore()
const wallet = useWalletStore()

onMounted(() => {
  wallet.fetchBalance()
  wallet.fetchTransactions()
})

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-MY', { dateStyle: 'medium' })
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">My Wallet</h3>
    <p class="text-muted">Mock wallet ledger — no real payments are processed</p>

    <div class="card wallet-card mb-4">
      <div class="card-body p-4">
        <p class="mb-1 small opacity-75">Available Balance</p>
        <h2 class="fw-bold mb-0">
          <span v-if="wallet.loading">...</span>
          <span v-else>RM{{ wallet.balance.toFixed(2) }}</span>
        </h2>
        <p class="small mt-2 mb-0 opacity-75">
          {{ auth.isTutor ? 'Earnings from teaching sessions' : 'Available for booking sessions' }}
        </p>
      </div>
    </div>

    <h6 class="fw-bold mb-3">Transaction History</h6>
    <div v-if="wallet.transactions.length" class="table-responsive">
      <table class="table bg-white shadow-sm rounded">
        <thead>
          <tr class="text-muted small">
            <th>Date</th>
            <th>Type</th>
            <th class="text-end">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in wallet.transactions" :key="t.transaction_id">
            <td class="small">{{ formatDate(t.created_at) }}</td>
            <td class="small">{{ t.type === 'Credit' ? 'Earned from session' : 'Paid for session' }}</td>
            <td
              class="text-end fw-semibold"
              :class="t.type === 'Credit' ? 'text-success' : 'text-danger'"
            >
              {{ t.type === 'Credit' ? '+' : '-' }}RM{{ t.amount.toFixed(2) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-else class="text-center py-5 text-muted">
      <i class="bi bi-wallet2" style="font-size: 2rem"></i>
      <p class="mt-2">No completed transactions yet.</p>
    </div>
  </div>
</template>
