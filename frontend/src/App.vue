<script setup>
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <nav v-if="auth.isAuthenticated" class="nav">
    <span class="brand">🎫 پنل ادمین ثبت‌نام</span>
    <button class="ghost small" @click="handleLogout">خروج</button>
  </nav>
  <router-view />
</template>

<style scoped>
.nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px 24px;
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
  box-shadow: var(--shadow-sm);
  position: sticky;
  top: 0;
  z-index: 10;
}
.brand {
  font-weight: 700;
  font-size: 15px;
}
</style>
