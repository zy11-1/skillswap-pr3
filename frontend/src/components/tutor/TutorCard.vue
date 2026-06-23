<script setup>
defineProps({
  tutor: {
    type: Object,
    required: true
  }
})

const defaultAvatar = 'https://i.pravatar.cc/150?img=1'
</script>

<template>
  <div class="card card-tutor h-100">
    <div class="card-body">
      <div class="d-flex align-items-center mb-2">
        <img
          :src="tutor.tutor_photo || defaultAvatar"
          class="rounded-circle me-3"
          width="48"
          height="48"
          alt="tutor photo"
        />
        <div>
          <h6 class="mb-0">
            {{ tutor.tutor_name }}
            <i
              v-if="tutor.is_verified"
              class="bi bi-patch-check-fill text-success ms-1"
              title="Verified"
            ></i>
          </h6>
          <small class="text-muted">{{ tutor.tutor_faculty }}</small>
        </div>
      </div>

      <span class="badge bg-light text-dark border mb-2">{{ tutor.skill_name }}</span>
      <p class="small text-muted mb-2">{{ tutor.description }}</p>

      <div class="d-flex justify-content-between align-items-center mt-3">
        <div>
          <span class="fw-bold text-primary-ss">RM{{ tutor.hourly_rate.toFixed(2) }}</span>
          <span class="text-muted small">/hr</span>
        </div>
        <div v-if="tutor.avg_rating" class="small">
          <i class="bi bi-star-fill text-warning"></i> {{ tutor.avg_rating }}
        </div>
        <div v-else class="small text-muted">No reviews yet</div>
      </div>

      <router-link
        :to="`/tutor/${tutor.user_id}`"
        class="btn btn-outline-primary btn-sm w-100 mt-3"
      >
        View Profile
      </router-link>
    </div>
  </div>
</template>
