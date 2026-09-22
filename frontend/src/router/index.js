import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import ProgramsListView from '../views/ProgramsListView.vue'
import ProgramDetailView from '../views/ProgramDetailView.vue'
import RegistrationsListView from '../views/RegistrationsListView.vue'
import RegistrationDetailView from '../views/RegistrationDetailView.vue'
import NotificationsListView from '../views/NotificationsListView.vue'
import NotificationDetailView from '../views/NotificationDetailView.vue'
import UsersListView from '../views/UsersListView.vue'

const routes = [
  { path: '/', redirect: '/dashboard' },
  { path: '/login', component: LoginView },
  { path: '/dashboard', component: DashboardView, meta: { requiresAuth: true } },
  { path: '/programs', component: ProgramsListView, meta: { requiresAuth: true } },
  { path: '/programs/:id', component: ProgramDetailView, meta: { requiresAuth: true } },
  { path: '/programs/:id/registrations', component: RegistrationsListView, meta: { requiresAuth: true } },
  { path: '/programs/:id/registrations/:registrationId', component: RegistrationDetailView, meta: { requiresAuth: true } },
  { path: '/notifications', component: NotificationsListView, meta: { requiresAuth: true } },
  { path: '/notifications/:id', component: NotificationDetailView, meta: { requiresAuth: true } },
  { path: '/users', component: UsersListView, meta: { requiresAuth: true } },
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
