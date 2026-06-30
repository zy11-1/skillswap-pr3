<script setup>
import { onMounted, computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useWalletStore } from '@/stores/wallet'
import { useCardsStore } from '@/stores/cards'
import { api } from '@/data/api'

const auth = useAuthStore()
const wallet = useWalletStore()
const cards = useCardsStore()

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

// ---- Add a card ----
const showCardForm = ref(false)
const cardForm = ref({ number: '', holder: '', expiry: '' })
const cardError = ref('')
function saveCard() {
  cardError.value = ''
  const digits = cardForm.value.number.replace(/\D/g, '')
  if (digits.length < 12) {
    cardError.value = 'Enter a valid card number.'
    return
  }
  cards.addCard(cardForm.value)
  cardForm.value = { number: '', holder: '', expiry: '' }
  showCardForm.value = false
}

// ---- Top up / Withdraw ----
const moneyModal = ref(null)          // 'topup' | 'withdraw' | null
const amount = ref(null)
const selectedCardId = ref(null)
const moneyError = ref('')
const moneyBusy = ref(false)
const moneyDone = ref('')

function openMoney(kind) {
  moneyModal.value = kind
  amount.value = null
  moneyError.value = ''
  moneyDone.value = ''
  selectedCardId.value = cards.cards[0]?.id ?? null
}

async function submitMoney() {
  moneyError.value = ''
  const amt = Number(amount.value)
  if (!amt || amt <= 0) {
    moneyError.value = 'Enter an amount greater than RM0.'
    return
  }
  if (!cards.cards.length) {
    moneyError.value = 'Add a card first.'
    return
  }
  if (moneyModal.value === 'withdraw' && amt > wallet.balance) {
    moneyError.value = `You only have RM${wallet.balance.toFixed(2)} to withdraw.`
    return
  }
  const card = cards.cards.find((c) => c.id === selectedCardId.value) || cards.cards[0]
  moneyBusy.value = true
  try {
    const fn = moneyModal.value === 'topup' ? api.walletTopUp : api.walletWithdraw
    const res = await fn(amt, card.last4)
    wallet.balance = Number(res.data.balance) || 0
    await wallet.fetchTransactions()
    moneyDone.value = moneyModal.value === 'topup'
      ? `RM${amt.toFixed(2)} added from your ${card.brand} ending ${card.last4}.`
      : `RM${amt.toFixed(2)} sent to your ${card.brand} ending ${card.last4}.`
    setTimeout(() => { moneyModal.value = null }, 1500)
  } catch (err) {
    moneyError.value = err.message || 'Something went wrong.'
  } finally {
    moneyBusy.value = false
  }
}
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-1">My Wallet</h3>
    <p class="text-muted">Top up from a linked card, spend on classes, and withdraw what you earn.</p>

    <div class="card wallet-card mb-4">
      <div class="card-body p-4">
        <p class="mb-1 small opacity-75">Available Balance</p>
        <h2 class="fw-bold mb-3">
          <span v-if="wallet.loading">...</span>
          <span v-else>RM{{ wallet.balance.toFixed(2) }}</span>
        </h2>
        <div class="d-flex gap-4 mb-3">
          <div>
            <p class="mb-0 small opacity-75"><i class="bi bi-arrow-down-left me-1"></i>{{ auth.isAdmin ? 'Commission earned' : 'Earned (teaching)' }}</p>
            <h5 class="fw-bold mb-0">RM{{ earned.toFixed(2) }}</h5>
          </div>
          <div>
            <p class="mb-0 small opacity-75"><i class="bi bi-arrow-up-right me-1"></i>Spent (learning)</p>
            <h5 class="fw-bold mb-0">RM{{ spent.toFixed(2) }}</h5>
          </div>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-light btn-sm fw-semibold" @click="openMoney('topup')">
            <i class="bi bi-plus-circle me-1"></i>Top up
          </button>
          <button class="btn btn-outline-light btn-sm fw-semibold" @click="openMoney('withdraw')">
            <i class="bi bi-cash-stack me-1"></i>Withdraw
          </button>
        </div>
      </div>
    </div>

    <!-- Linked cards -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-credit-card-2-front me-2"></i>Payment methods</span>
        <button class="btn btn-sm btn-outline-primary" @click="showCardForm = !showCardForm">
          <i class="bi bi-plus-lg me-1"></i>Add card
        </button>
      </div>
      <div class="card-body">
        <div v-if="showCardForm" class="border rounded p-3 mb-3 bg-light">
          <div v-if="cardError" class="alert alert-danger py-2 small">{{ cardError }}</div>
          <div class="row g-2">
            <div class="col-md-5">
              <label class="form-label small">Card number</label>
              <input v-model="cardForm.number" class="form-control form-control-sm" placeholder="4242 4242 4242 4242" />
            </div>
            <div class="col-md-4">
              <label class="form-label small">Name on card</label>
              <input v-model="cardForm.holder" class="form-control form-control-sm" placeholder="Your name" />
            </div>
            <div class="col-md-3">
              <label class="form-label small">Expiry</label>
              <input v-model="cardForm.expiry" class="form-control form-control-sm" placeholder="MM/YY" />
            </div>
          </div>
          <button class="btn btn-primary btn-sm mt-2" @click="saveCard">Save card</button>
          <p class="text-muted mt-2 mb-0" style="font-size:.7rem">
            <i class="bi bi-shield-lock me-1"></i>Demo only — we keep just the last 4 digits in your browser, never the full number.
          </p>
        </div>

        <div v-if="cards.cards.length" class="row g-2">
          <div v-for="c in cards.cards" :key="c.id" class="col-md-6">
            <div class="d-flex align-items-center justify-content-between bg-light rounded p-2">
              <span>
                <i class="bi bi-credit-card-2-front me-2 text-primary-ss"></i>
                <strong>{{ c.brand }}</strong> •••• {{ c.last4 }}
                <span class="text-muted small ms-2">{{ c.holder }} · {{ c.expiry }}</span>
              </span>
              <button class="btn btn-sm btn-link text-danger p-0" @click="cards.removeCard(c.id)" title="Remove">
                <i class="bi bi-trash"></i>
              </button>
            </div>
          </div>
        </div>
        <p v-else-if="!showCardForm" class="text-muted small mb-0">No cards linked yet — add one to top up or withdraw.</p>
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
              <template v-if="t.booking_id">
                {{ t.type === 'Credit' ? (auth.isAdmin ? 'Platform commission (10%)' : 'Earned from session') : 'Paid for session' }}
              </template>
              <template v-else>
                {{ t.type === 'Credit' ? 'Top-up from card' : 'Withdrawal to card' }}
              </template>
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
      <p class="mt-2">No transactions yet.</p>
    </div>

    <!-- Top up / Withdraw modal -->
    <div v-if="moneyModal" class="modal-backdrop-custom" @click.self="moneyModal = null">
      <div class="card money-modal shadow-lg">
        <div class="card-body p-4">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h5 class="fw-bold mb-0">{{ moneyModal === 'topup' ? 'Top up wallet' : 'Withdraw earnings' }}</h5>
            <button class="btn-close" @click="moneyModal = null"></button>
          </div>

          <div v-if="moneyDone" class="alert alert-success mb-0">
            <i class="bi bi-check-circle-fill me-2"></i>{{ moneyDone }}
          </div>
          <template v-else>
            <div v-if="moneyError" class="alert alert-danger py-2 small">{{ moneyError }}</div>

            <div v-if="!cards.cards.length" class="alert alert-warning py-2 small">
              Add a card under <strong>Payment methods</strong> first.
            </div>
            <template v-else>
              <label class="form-label small">Amount (RM)</label>
              <input v-model.number="amount" type="number" min="1" step="1" class="form-control mb-3" placeholder="0.00" />

              <label class="form-label small">{{ moneyModal === 'topup' ? 'Pay from' : 'Send to' }}</label>
              <select v-model="selectedCardId" class="form-select mb-3">
                <option v-for="c in cards.cards" :key="c.id" :value="c.id">
                  {{ c.brand }} •••• {{ c.last4 }}
                </option>
              </select>

              <button class="btn btn-primary w-100" :disabled="moneyBusy" @click="submitMoney">
                <span v-if="moneyBusy" class="spinner-border spinner-border-sm me-2"></span>
                {{ moneyModal === 'topup' ? 'Add money' : 'Withdraw' }}
              </button>
              <p class="text-muted text-center mt-2 mb-0" style="font-size:.7rem">
                <i class="bi bi-info-circle me-1"></i>Demo — no real funds move.
              </p>
            </template>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop-custom {
  position: fixed;
  inset: 0;
  background: rgba(15, 20, 35, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1050;
  padding: 1rem;
}
.money-modal {
  border: none;
  border-radius: 16px;
  max-width: 400px;
  width: 100%;
}
</style>
