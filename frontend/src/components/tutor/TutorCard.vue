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
    <div class="card-body d-flex flex-column">
      <!-- 头像 + 姓名 -->
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

      <!-- 技能标签 -->
      <span class="badge bg-light text-dark border mb-2">{{ tutor.skill_name }}</span>

      <!-- 描述：固定 2 行，超出省略号 -->
      <p class="small text-muted mb-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; flex: 1;">
        {{ tutor.description }}
      </p>

      <!-- 价格和评分 -->
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

      <!-- 按钮 -->
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
}
</style>