<script setup>
import { onMounted, reactive, ref } from 'vue'
import apiClient from '../api/client'

const PLATFORM_LABELS = { telegram: 'تلگرام', bale: 'بله', rubika: 'روبیکا' }

const users = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const platformFilter = ref('')
const banStatusFilter = ref('')
const blockStatusFilter = ref('')
const search = ref('')
const page = ref(1)

async function loadUsers() {
  loading.value = true
  try {
    const { data } = await apiClient.get('/bot-users', {
      params: {
        page: page.value,
        ...(platformFilter.value ? { platform: platformFilter.value } : {}),
        ...(banStatusFilter.value !== '' ? { is_banned: banStatusFilter.value } : {}),
        ...(blockStatusFilter.value !== '' ? { is_blocked: blockStatusFilter.value } : {}),
        ...(search.value ? { search: search.value } : {}),
      },
    })
    users.value = data.data
    Object.assign(meta, data.meta)
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  loadUsers()
}

function goToPage(p) {
  if (p < 1 || p > meta.last_page) return
  page.value = p
  loadUsers()
}

async function banUser(user) {
  const reason = prompt('دلیل بن کردن (اختیاری):') || null
  if (reason === null && !confirm('این کاربر بن بشه؟')) return
  await apiClient.patch(`/bot-users/${user.id}/ban`, { reason })
  await loadUsers()
}

async function unbanUser(user) {
  if (!confirm('بن این کاربر برداشته بشه؟')) return
  await apiClient.patch(`/bot-users/${user.id}/unban`)
  await loadUsers()
}

onMounted(loadUsers)
</script>

<template>
  <div class="page">
    <h1>کاربران بات</h1>

    <section class="card">
      <div class="row">
        <input v-model="search" placeholder="جستجو بر اساس نام، یوزرنیم، شماره یا شناسه" @keyup.enter="applyFilters" />
        <select v-model="platformFilter" @change="applyFilters">
          <option value="">همه‌ی پلتفرم‌ها</option>
          <option v-for="(label, platform) in PLATFORM_LABELS" :key="platform" :value="platform">{{ label }}</option>
        </select>
        <select v-model="banStatusFilter" @change="applyFilters">
          <option value="">وضعیت بن: همه</option>
          <option value="1">بن‌شده</option>
          <option value="0">بن‌نشده</option>
        </select>
        <select v-model="blockStatusFilter" @change="applyFilters">
          <option value="">وضعیت بلاک: همه</option>
          <option value="1">بلاک‌کننده‌ی بات</option>
          <option value="0">بلاک نکرده</option>
        </select>
        <button class="primary small" @click="applyFilters">اعمال فیلتر</button>
        <span class="muted">مجموع: {{ meta.total }}</span>
      </div>
    </section>

    <section class="card">
      <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>نام</th>
              <th>یوزرنیم</th>
              <th>پلتفرم</th>
              <th>شماره</th>
              <th>آخرین فعالیت</th>
              <th>وضعیت</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in users" :key="user.id">
              <td>{{ [user.first_name, user.last_name].filter(Boolean).join(' ') || '—' }}</td>
              <td class="muted">{{ user.username ? '@' + user.username : '—' }}</td>
              <td>{{ PLATFORM_LABELS[user.platform] || user.platform }}</td>
              <td class="muted">{{ user.phone || '—' }}</td>
              <td class="muted">{{ user.last_interaction_at ? new Date(user.last_interaction_at).toLocaleDateString('fa-IR') : '—' }}</td>
              <td>
                <span v-if="user.is_banned" class="badge closed">بن‌شده</span>
                <span v-if="user.is_blocked" class="badge draft">بلاک‌کننده</span>
                <span v-if="!user.is_banned && !user.is_blocked" class="badge published">عادی</span>
              </td>
              <td class="actions">
                <button v-if="!user.is_banned" class="danger small" @click="banUser(user)">بن</button>
                <button v-else class="small" @click="unbanUser(user)">رفع بن</button>
              </td>
            </tr>
            <tr v-if="users.length === 0" class="empty-row">
              <td colspan="7">کاربری یافت نشد.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="meta.last_page > 1" class="pagination">
        <button class="small" :disabled="meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">قبلی</button>
        <span class="muted">صفحه {{ meta.current_page }} از {{ meta.last_page }}</span>
        <button class="small" :disabled="meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">بعدی</button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}
.row input {
  flex: 1 1 240px;
}
.actions {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 10px;
}
</style>
