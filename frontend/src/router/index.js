import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginView from '../views/LoginView.vue'
import ProgramsListView from '../views/ProgramsListView.vue'
import ProgramDetailView from '../views/ProgramDetailView.vue'

const routes = [
  { path: '/', redirect: '/programs' },
  { path: '/login', component: LoginView },
  { path: '/programs', component: ProgramsListView, meta: { requiresAuth: true } },
  { path: '/programs/:id', component: ProgramDetailView, meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return '/login'
  }
  return true
})

export default router
