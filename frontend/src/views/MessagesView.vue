<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/data/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth = useAuthStore()

const conversations = ref([])
const activeUser = ref(null) // { user_id, name }
const messages = ref([])
const draft = ref('')
const sending = ref(false)
const loadingThread = ref(false)
const threadEnd = ref(null)
let pollTimer = null

async function loadConversations() {
  const res = await api.getConversations()
  conversations.value = res.data || []
}

async function openThread(user) {
  activeUser.value = { user_id: user.user_id, name: user.name }
  loadingThread.value = true
  try {
    await refreshThread()
  } finally {
    loadingThread.value = false
  }
}

async function refreshThread() {
  if (!activeUser.value) return
  const previousCount = messages.value.length
  const res = await api.getThread(activeUser.value.user_id)
  messages.value = res.data || []
  // Only auto-scroll when something new arrived, so polling doesn't yank
  // the view while the user is reading earlier messages.
  if (messages.value.length !== previousCount) {
    await nextTick()
    threadEnd.value?.scrollIntoView({ behavior: 'smooth' })
  }
}

async function send() {
  const text = draft.value.trim()
  if (!text || !activeUser.value) return
  sending.value = true

  // Optimistic UI: show the sent bubble immediately, then reconcile with the
  // server. If the send fails we roll the bubble back and restore the draft.
  const tempId = `temp-${Date.now()}`
  messages.value.push({ message_id: tempId, sender_id: auth.user?.user_id, body: text })
  draft.value = ''
  await nextTick()
  threadEnd.value?.scrollIntoView({ behavior: 'smooth' })

  try {
    await api.sendMessage(activeUser.value.user_id, text)
    await refreshThread()
    await loadConversations()
  } catch (err) {
    messages.value = messages.value.filter((m) => m.message_id !== tempId)
    draft.value = text
    alert(err.message || 'Could not send message.')
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  await loadConversations()
  // If we arrived via "Message" on a tutor profile (?to=&name=), open it.
  if (route.query.to) {
    openThread({ user_id: Number(route.query.to), name: route.query.name || 'Tutor' })
  }
  // Poll BOTH the conversation list (so new chats/notifications appear) and
  // the open thread every 4s (async chat, no WebSocket needed).
  pollTimer = setInterval(() => {
    loadConversations()
    if (activeUser.value) refreshThread()
  }, 4000)
})

onUnmounted(() => clearInterval(pollTimer))
</script>

<template>
  <div class="container py-4">
    <h3 class="fw-bold mb-3">Messages</h3>
    <div class="row g-3">
      <!-- Conversation list -->
      <div class="col-md-4">
        <div class="card border-0 shadow-sm">
          <div class="list-group list-group-flush">
            <button
              v-for="c in conversations"
              :key="c.user_id"
              class="list-group-item list-group-item-action d-flex align-items-center gap-2"
              :class="{ active: activeUser && activeUser.user_id === c.user_id }"
              @click="openThread(c)"
            >
              <img :src="c.photo_url || 'https://i.pravatar.cc/150?img=1'" class="rounded-circle" width="36" height="36" />
              <span class="text-start">
                <span class="d-block fw-semibold">{{ c.name }}</span>
                <span class="small text-muted text-truncate d-inline-block" style="max-width: 180px">{{ c.last_body }}</span>
              </span>
            </button>
            <p v-if="!conversations.length" class="text-muted small p-3 mb-0">
              No conversations yet. Start one from a tutor's profile.
            </p>
          </div>
        </div>
      </div>

      <!-- Thread -->
      <div class="col-md-8">
        <div v-if="activeUser" class="card border-0 shadow-sm">
          <div class="card-header bg-white fw-bold">{{ activeUser.name }}</div>
          <div class="card-body chat-window">
            <div v-if="loadingThread" class="text-center text-muted small">Loading...</div>
            <template v-else>
              <div
                v-for="m in messages"
                :key="m.message_id"
                class="d-flex mb-2"
                :class="Number(m.sender_id) === Number(auth.user?.user_id) ? 'justify-content-end' : 'justify-content-start'"
              >
                <div
                  class="chat-bubble px-3 py-2 rounded"
                  :class="Number(m.sender_id) === Number(auth.user?.user_id) ? 'bg-primary text-white' : 'bg-light'"
                >
                  {{ m.body }}
                </div>
              </div>
              <div ref="threadEnd"></div>
            </template>
          </div>
          <div class="card-footer bg-white">
            <form class="d-flex gap-2" @submit.prevent="send">
              <input v-model="draft" class="form-control" placeholder="Type a message..." />
              <button class="btn btn-primary" :disabled="sending || !draft.trim()">Send</button>
            </form>
          </div>
        </div>
        <div v-else class="text-center text-muted py-5">
          <i class="bi bi-chat-dots" style="font-size: 2rem"></i>
          <p class="mt-2">Select a conversation to start chatting.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.chat-window {
  height: 360px;
  overflow-y: auto;
}
.chat-bubble {
  max-width: 75%;
}
</style>
