<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const email = ref('test@example.com')
const password = ref('')
const error = ref('')
const loading = ref(false)

const auth = useAuthStore()
const router = useRouter()

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    router.push('/programs')
  } catch (e) {
    error.value = e.response?.data?.message || 'ورود ناموفق بود.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <form class="login-form" @submit.prevent="submit">
      <h1>ورود ادمین</h1>
      <label>
        ایمیل
        <input v-model="email" type="email" required />
      </label>
      <label>
        رمز عبور
        <input v-model="password" type="password" required />
      </label>
      <p v-if="error" class="error">{{ error }}</p>
      <button type="submit" :disabled="loading">{{ loading ? '...' : 'ورود' }}</button>
    </form>
  </div>
</template>

<style scoped>
.login-page {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
}
.login-form {
  display: flex;
  flex-direction: column;
  gap: 12px;
  width: 300px;
  padding: 24px;
  border: 1px solid #ddd;
  border-radius: 8px;
}
label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 14px;
}
input {
  padding: 8px;
  font-size: 14px;
}
button {
  padding: 10px;
  cursor: pointer;
}
.error {
  color: #c0392b;
  font-size: 13px;
}
</style>
