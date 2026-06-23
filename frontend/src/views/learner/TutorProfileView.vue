<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/data/api'
import BookingModal from '@/components/booking/BookingModal.vue'

const route = useRoute()
const router = useRouter()

const tutor = ref(null)
const loading = ref(true)
const showBookingModal = ref(false)
const selectedOffering = ref(null)
const bookingSuccess = ref(false)
const defaultAvatar = 'https://i.pravatar.cc/150?img=1'

async function loadTutor() {
  loading.value = true
  try {
    const res = await api.getTutorById(route.params.id)
    tutor.value = res.data
  } finally {
    loading.value = false
  }
}

onMounted(loadTutor)

function openBooking(offering) {
  selectedOffering.value = offering
  showBookingModal.value = true
}

function handleBooked() {
  showBookingModal.value = false
  bookingSuccess.value = true
  setTimeout(() => {
    router.push('/bookings')
  }, 1200)
}
</script>

<template>
  <div class="container py-4">
    <div v-if="loading" class="text-center py-5">
      <div class="spinner-border text-primary-ss"></div>
    </div>

    <template v-else-if="tutor">
      <button class="btn btn-link px-0 mb-3" @click="router.back()">
        <i class="bi bi-arrow-left"></i> Back to marketplace
      </button>

      <div v-if="bookingSuccess" class="alert alert-success">
        <i class="bi bi-check-circle-fill me-2"></i>Booking request sent! Redirecting to your bookings...
      </div>

      <div class="row g-4">
        <div class="col-md-4">
          <div class="card border-0 shadow-sm text-center p-3">
            <img
              :src="tutor.photo_url || defaultAvatar"
              class="rounded-circle mx-auto mb-3"
              width="100"
              height="100"
              alt="tutor photo"
            />
            <h5 class="mb-0">
              {{ tutor.name }}
              <i v-if="tutor.is_verified" class="bi bi-patch-check-fill text-success"></i>
            </h5>
            <p class="text-muted small">{{ tutor.faculty }}</p>
            <p class="small">{{ tutor.bio }}</p>
          </div>
        </div>

        <div class="col-md-8">
          <h6 class="fw-bold mb-3">Skills Offered</h6>
          <div
            v-for="offering in tutor.offerings"
            :key="offering.userskill_id"
            class="card border-0 shadow-sm mb-3"
          >
            <div class="card-body d-flex justify-content-between align-items-center">
              <div>
                <h6 class="mb-1">
                  {{ offering.skill_name }}
                  <span class="badge bg-light text-dark border ms-1">{{ offering.level }}</span>
                </h6>
                <p class="small text-muted mb-0">{{ offering.description }}</p>
                <span class="fw-bold text-primary-ss">RM{{ offering.hourly_rate.toFixed(2) }}/hr</span>
              </div>
              <button class="btn btn-primary btn-sm" @click="openBooking(offering)">
                Book
              </button>
            </div>
          </div>

          <h6 class="fw-bold mt-4 mb-3">Reviews</h6>
          <div v-if="tutor.reviews.length">
            <div v-for="review in tutor.reviews" :key="review.review_id" class="card border-0 shadow-sm mb-2">
              <div class="card-body py-2">
                <div class="d-flex justify-content-between">
                  <span>
                    <i
                      v-for="n in 5"
                      :key="n"
                      class="bi"
                      :class="n <= review.rating ? 'bi-star-fill text-warning' : 'bi-star'"
                    ></i>
                  </span>
                </div>
                <p class="small mb-0 mt-1">{{ review.comment }}</p>
              </div>
            </div>
          </div>
          <p v-else class="text-muted small">No reviews yet.</p>
        </div>
      </div>

      <BookingModal
        v-if="showBookingModal"
        :tutor="tutor"
        :offering="selectedOffering"
        @close="showBookingModal = false"
        @booked="handleBooked"
      />
    </template>
  </div>
</template>
