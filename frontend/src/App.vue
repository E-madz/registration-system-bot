<script setup>
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <nav v-if="auth.isAuthenticated" class="nav">
    <div class="nav-right">
      <span class="brand">🎫 پنل ادمین ثبت‌نام</span>
      <router-link to="/dashboard" class="nav-link" :class="{ active: route.path === '/dashboard' }">داشبورد</router-link>
      <router-link to="/programs" class="nav-link" :class="{ active: route.path.startsWith('/programs') }">برنامه‌ها</router-link>
      <router-link to="/notifications" class="nav-link" :class="{ active: route.path.startsWith('/notifications') }">اطلاع‌رسانی‌ها</router-link>
      <router-link to="/users" class="nav-link" :class="{ active: route.path.startsWith('/users') }">کاربران</router-link>
    </div>
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
  gap: 12px;
}
.nav-right {
  display: flex;
  align-items: center;
  gap: 20px;
}
.brand {
  font-weight: 700;
  font-size: 15px;
}
.nav-link {
  color: var(--color-text-muted);
  text-decoration: none;
  font-size: 14px;
  font-weight: 600;
  padding: 6px 4px;
  border-bottom: 2px solid transparent;
}
.nav-link.active {
  color: var(--color-primary);
  border-bottom-color: var(--color-primary);
}
</style>
