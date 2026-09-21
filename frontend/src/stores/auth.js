import { defineStore } from 'pinia'
import apiClient from '../api/client'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('auth_token') || null,
    user: null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
  },
  actions: {
    async login(email, password) {
      const { data } = await apiClient.post('/login', { email, password })
      this.token = data.token
      this.user = data.user
      localStorage.setItem('auth_token', data.token)
    },
    async logout() {
      try {
        await apiClient.post('/logout')
      } finally {
        this.token = null
        this.user = null
        localStorage.removeItem('auth_token')
      }
    },
    async fetchMe() {
      const { data } = await apiClient.get('/me')
      this.user = data
    },
  },
})
