<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const email = ref('')
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
    router.push('/dashboard')
  } catch (e) {
    error.value = e.response?.data?.message || 'ورود ناموفق بود.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <form class="login-form card" @submit.prevent="submit">
      <div class="login-header">
        <div class="login-icon">🎫</div>
        <h1>ورود ادمین</h1>
        <p class="muted">برای مدیریت برنامه‌های ثبت‌نام وارد شوید.</p>
      </div>
      <label>
        ایمیل
        <input v-model="email" type="email" autocomplete="username" required />
      </label>
      <label>
        رمز عبور
        <input v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="error" class="alert alert-error">{{ error }}</p>
      <button type="submit" class="primary" :disabled="loading">{{ loading ? 'در حال ورود...' : 'ورود' }}</button>
    </form>
  </div>
</template>

<style scoped>
.login-page {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  padding: 20px;
}
.login-form {
  width: 100%;
  max-width: 340px;
}
.login-header {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 4px;
}
.login-icon {
  font-size: 28px;
  margin-bottom: 4px;
}
button[type='submit'] {
  width: 100%;
}
</style>
