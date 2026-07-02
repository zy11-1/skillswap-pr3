<script setup>
// Transparent "Payment & Policies" summary shown under every booking UI.
// One source of truth for the money rules, so every user sees the same
// numbers the backend actually enforces.
import { computed } from 'vue'

const props = defineProps({
  // The slot being booked (optional — without it, the general policy shows).
  slot: { type: Object, default: null }
})

const pay = computed(() => (props.slot ? Number(props.slot.next_price) : null))
const hours = computed(() => (props.slot ? Number(props.slot.hours) : null))
const hourly = computed(() => (props.slot ? Number(props.slot.next_hourly) : null))
const projected = computed(() =>
  props.slot ? Number(props.slot.projected_price_join ?? props.slot.projected_price) : null
)
</script>

<template>
  <div class="policies mt-3">
    <h6 class="fw-bold small mb-2"><i class="bi bi-shield-check me-1"></i>Payment &amp; Policies</h6>

    <!-- Breakdown for the selected slot -->
    <table v-if="slot" class="table table-sm mb-2 small breakdown">
      <tbody>
        <tr>
          <td>Rate set by tutor</td>
          <td class="text-end">RM{{ hourly.toFixed(2) }} / hour × {{ hours }}h</td>
        </tr>
        <tr class="fw-bold">
          <td>You prepay now (wallet)</td>
          <td class="text-end">RM{{ pay.toFixed(2) }}</td>
        </tr>
        <tr class="text-success">
          <td>Projected final price if you join<br>
            <span class="fw-normal text-muted">− RM1 for every student enrolled (never below RM10)</span>
          </td>
          <td class="text-end align-middle">RM{{ projected.toFixed(2) }}</td>
        </tr>
      </tbody>
    </table>

    <ul class="small text-muted mb-0 ps-3 policy-list">
      <li><strong>Same price for everyone</strong> — every student prepays exactly what the first student paid.</li>
      <li><strong>Automatic group discount</strong> — after the class runs, the final price is reduced RM1 per
        enrolled student (RM10 minimum) and the difference is <strong>refunded to every student's wallet
        automatically</strong>. No one needs to ask.</li>
      <li><strong>Full refund guarantee</strong> — if the tutor declines your request or cancels the class,
        100% of your prepayment returns to your wallet instantly, and an admin is notified.</li>
      <li><strong>Platform commission</strong> — SkillSwap keeps 10% of the final price; the tutor receives
        90%. Commission is charged only on completed classes.</li>
      <li><strong>Disputes</strong> — problem with a session? Use "Report issue" in My Classes; an admin can
        cancel and refund you in full.</li>
    </ul>
  </div>
</template>

<style scoped>
.policies {
  background: #f8fafc;
  border: 1px solid #e6ebf2;
  border-radius: 10px;
  padding: 0.75rem;
}
.breakdown td { background: transparent; }
.policy-list li { margin-bottom: 2px; }
</style>
