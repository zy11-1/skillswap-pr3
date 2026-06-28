<script setup>
import { ref } from 'vue'
import { api } from '@/data/api'

// `booking` is the row from My Bookings. If it already has a review_id,
// we're editing; otherwise we're creating a new review for it.
const props = defineProps({
  booking: { type: Object, required: true }
})

const emit = defineEmits(['close', 'saved'])

const isEditing = !!props.booking.review_id

const rating = ref(props.booking.review_rating || 0)
const hover = ref(0)
const comment = ref(props.booking.review_comment || '')
const submitting = ref(false)
const error = ref('')

async function submitReview() {
  error.value = ''

  if (rating.value < 1 || rating.value > 5) {
    error.value = 'Please pick a star rating from 1 to 5.'
    return
  }

  submitting.value = true
  try {
    if (isEditing) {
      await api.updateReview(props.booking.review_id, {
        rating: rating.value,
        comment: comment.value
      })
    } else {
      await api.createReview({
        booking_id: props.booking.booking_id,
        rating: rating.value,
        comment: comment.value
      })
    }
    emit('saved')
  } catch (err) {
    error.value = err.message || 'Could not save your review.'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="modal-backdrop-custom" @click.self="emit('close')">
    <div class="card review-modal shadow-lg">
      <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
          <h5 class="fw-bold mb-0">{{ isEditing ? 'Edit your review' : 'Leave a review' }}</h5>
          <button class="btn-close" @click="emit('close')"></button>
        </div>

        <p class="text-muted small">
          for <strong>{{ booking.tutor_name }}</strong> — {{ booking.skill_name }}
        </p>

        <div v-if="error" class="alert alert-danger py-2 small">{{ error }}</div>

        <form @submit.prevent="submitReview">
          <div class="mb-3">
            <label class="form-label small">Your rating</label>
            <div class="fs-3" @mouseleave="hover = 0">
              <i
                v-for="n in 5"
                :key="n"
                class="bi star-pick"
                :class="n <= (hover || rating) ? 'bi-star-fill text-warning' : 'bi-star text-muted'"
                @mouseenter="hover = n"
                @click="rating = n"
              ></i>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small">Comment (optional)</label>
            <textarea
              v-model="comment"
              class="form-control"
              rows="3"
              placeholder="How was the session?"
            ></textarea>
          </div>

          <button type="submit" class="btn btn-primary w-100" :disabled="submitting">
            <span v-if="submitting" class="spinner-border spinner-border-sm me-2"></span>
            {{ submitting ? 'Saving...' : (isEditing ? 'Update Review' : 'Submit Review') }}
          </button>
        </form>
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

.review-modal {
  border: none;
  border-radius: 16px;
  max-width: 420px;
  width: 100%;
}

.star-pick {
  cursor: pointer;
}
</style>
