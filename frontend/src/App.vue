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
    <span>پنل ادمین</span>
    <button @click="handleLogout">خروج</button>
  </nav>
  <router-view />
</template>

<style scoped>
.nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 24px;
  border-bottom: 1px solid #ddd;
}
button {
  cursor: pointer;
  padding: 6px 10px;
}
</style>
