<script setup>
import { useFavoritesStore } from '@/stores/favorites'

const props = defineProps({
  tutor: {
    type: Object,
    required: true
  }
})

const favorites = useFavoritesStore()
const defaultAvatar = 'https://i.pravatar.cc/150?img=1'

async function toggleFav() {
  try {
    await favorites.toggle(props.tutor.user_id)
  } catch (err) {
    alert(err.message || 'Could not update favourite.')
  }
}
</script>

<template>
  <div class="card card-tutor h-100">
    <div class="card-body d-flex flex-column">
      <!-- Favourite heart (top-right) -->
      <button
        class="btn btn-sm btn-link p-0 favorite-heart"
        :title="favorites.isFavorite(tutor.user_id) ? 'Remove from favourites' : 'Add to favourites'"
        @click.prevent="toggleFav"
      >
        <i :class="favorites.isFavorite(tutor.user_id) ? 'bi bi-heart-fill text-danger' : 'bi bi-heart text-muted'"></i>
      </button>

      <!-- Avatar + name -->
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

      <!-- Skill tag -->
      <span class="badge bg-light text-dark border mb-2">{{ tutor.skill_name }}</span>

      <!-- Description: fixed 2 lines, truncated with ellipsis -->
      <p class="small text-muted mb-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1;">
        {{ tutor.description }}
      </p>

      <!-- Price and rating -->
      <div class="d-flex justify-content-between align-items-center mt-auto">
        <div>
          <span class="fw-bold text-primary-ss">RM{{ tutor.hourly_rate.toFixed(2) }}</span>
          <span class="text-muted small">/hr</span>
        </div>
        <div v-if="tutor.avg_rating" class="small">
          <i class="bi bi-star-fill text-warning"></i> {{ tutor.avg_rating }}
        </div>
        <div v-else class="small text-muted">No reviews yet</div>
      </div>

      <!-- Button -->
      <router-link
        :to="`/tutor/${tutor.user_id}`"
        class="btn btn-outline-primary btn-sm w-100 mt-3"
      >
        View Profile
      </router-link>
    </div>
  </div>
</template>

<style scoped>
.card-tutor .card-body {
  min-height: 280px;
  position: relative;
}
.favorite-heart {
  position: absolute;
  top: 10px;
  right: 12px;
  font-size: 1.2rem;
  z-index: 2;
}
</style>