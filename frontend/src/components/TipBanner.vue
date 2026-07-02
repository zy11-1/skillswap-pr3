<script setup>
// A small dismissible "how this works" banner. Dismissal is remembered in
// localStorage, so it's per device/browser — a first visit on a new PC shows
// the tips again, exactly once, without any backend involvement.
import { ref } from 'vue'

const props = defineProps({
  tipId: { type: String, required: true },   // unique key, e.g. 'tutor-slots'
  icon: { type: String, default: 'bi-lightbulb' }
})

const storageKey = `ss-tip-dismissed-${props.tipId}`
const visible = ref(localStorage.getItem(storageKey) !== '1')

function dismiss() {
  visible.value = false
  localStorage.setItem(storageKey, '1')
}
</script>

<template>
  <div v-if="visible" class="tip-banner d-flex align-items-start gap-2 mb-3">
    <i :class="`bi ${icon}`" class="tip-icon"></i>
    <div class="flex-grow-1 small"><slot /></div>
    <button class="btn-close tip-close" title="Got it — hide this tip" @click="dismiss"></button>
  </div>
</template>

<style scoped>
.tip-banner {
  background: #f4f8ff;
  border: 1px solid #dbe7fb;
  border-left: 3px solid var(--ss-primary, #4a6cf7);
  border-radius: 8px;
  padding: 0.6rem 0.75rem;
  color: #3c4a63;
}
.tip-icon {
  color: var(--ss-primary, #4a6cf7);
  font-size: 1rem;
  margin-top: 1px;
}
.tip-close {
  font-size: 0.6rem;
  margin-top: 3px;
}
</style>
