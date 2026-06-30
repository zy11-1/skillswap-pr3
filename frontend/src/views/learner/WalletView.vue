<script setup>
import { onMounted, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useWalletStore } from '@/stores/wallet'

const auth = useAuthStore()
const wallet = useWalletStore()

onMounted(() => {
  wallet.fetchBalance()
  wallet.fetchTransactions()
})

// Derive the "dual wallet" view from the single ledger:
// earned = sum of Credits, spent = sum of Debits.
const earned = computed(() =>
  wallet.transactions.filter((t) => t.type === 'Credit').reduce((s, t) => s + t.amount, 0)
)
const spent = computed(() =>
  wallet.transactions.filter((t) => t.type === 'Debit').reduce((s, t) => s + t.amount, 0)
)

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
        <h2 class="fw-bold mb-3">
          <span v-if="wallet.loading">...</span>
          <span v-else>RM{{ wallet.balance.toFixed(2) }}</span>
        </h2>
        <div class="d-flex gap-4">
          <div>
            <p class="mb-0 small opacity-75"><i class="bi bi-arrow-down-left me-1"></i>{{ auth.isAdmin ? 'Commission earned' : 'Earned (teaching)' }}</p>
            <h5 class="fw-bold mb-0">RM{{ earned.toFixed(2) }}</h5>
          </div>
          <div>
            <p class="mb-0 small opacity-75"><i class="bi bi-arrow-up-right me-1"></i>Spent (learning)</p>
            <h5 class="fw-bold mb-0">RM{{ spent.toFixed(2) }}</h5>
          </div>
        </div>
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
            <td class="small">
              {{ t.type === 'Credit' ? (auth.isAdmin ? 'Platform commission (10%)' : 'Earned from session') : 'Paid for session' }}
            </td>
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
