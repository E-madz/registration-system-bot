<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '../api/client'

const PLATFORM_LABELS = { telegram: 'تلگرام', bale: 'بله', rubika: 'روبیکا' }
const STATUS_LABELS = { confirmed: 'تاییدشده', canceled: 'لغوشده' }
const REASON_LABELS = {
  self: 'خودِ کاربر',
  admin: 'ادمین',
  banned: 'بن‌شدن کاربر',
  bot_blocked: 'بلاک کردن بات',
}

const route = useRoute()
const router = useRouter()
const programId = route.params.id

const program = ref(null)
const registrations = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const exporting = ref(false)
const statusFilter = ref('')
const platformFilter = ref('')
const page = ref(1)

async function loadProgram() {
  const { data } = await apiClient.get(`/programs/${programId}`)
  program.value = data.data
}

async function loadRegistrations() {
  loading.value = true
  try {
    const { data } = await apiClient.get(`/programs/${programId}/registrations`, {
      params: {
        page: page.value,
        ...(statusFilter.value ? { status: statusFilter.value } : {}),
        ...(platformFilter.value ? { platform: platformFilter.value } : {}),
      },
    })
    registrations.value = data.data
    Object.assign(meta, data.meta)
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  loadRegistrations()
}

function goToPage(p) {
  if (p < 1 || p > meta.last_page) return
  page.value = p
  loadRegistrations()
}

async function cancelRegistration(reg) {
  const reason = prompt('دلیل لغو (برای اطلاع‌رسانی به کاربر، اختیاری):')
  if (reason === null) return
  await apiClient.patch(`/programs/${programId}/registrations/${reg.id}/cancel`, { reason: reason || null })
  await loadRegistrations()
}

async function banUser(reg) {
  const reason = prompt('دلیل بن کردن (اختیاری):') || null
  if (reason === null && !confirm('این کاربر بن بشه؟')) return
  await apiClient.patch(`/bot-users/${reg.bot_user.id}/ban`, { reason })
  await loadRegistrations()
}

async function unbanUser(reg) {
  if (!confirm('بن این کاربر برداشته بشه؟')) return
  await apiClient.patch(`/bot-users/${reg.bot_user.id}/unban`)
  await loadRegistrations()
}

async function exportCsv() {
  exporting.value = true
  try {
    const response = await apiClient.get(`/programs/${programId}/registrations/export`, {
      params: {
        ...(statusFilter.value ? { status: statusFilter.value } : {}),
        ...(platformFilter.value ? { platform: platformFilter.value } : {}),
      },
      responseType: 'blob',
    })
    const url = window.URL.createObjectURL(new Blob([response.data]))
    const link = document.createElement('a')
    link.href = url
    link.download = `registrations-${program.value?.slug || programId}.csv`
    link.click()
    window.URL.revokeObjectURL(url)
  } finally {
    exporting.value = false
  }
}

function openRegistration(reg) {
  router.push(`/programs/${programId}/registrations/${reg.id}`)
}

function goBack() {
  router.push(`/programs/${programId}`)
}

onMounted(() => {
  loadProgram()
  loadRegistrations()
})
</script>

<template>
  <div class="page">
    <div class="card-header">
      <button class="ghost small" @click="goBack">→ بازگشت به برنامه</button>
    </div>
    <h1>ثبت‌نام‌های «{{ program?.title }}»</h1>

    <section class="card">
      <div class="row">
        <select v-model="statusFilter" @change="applyFilters">
          <option value="">همه‌ی وضعیت‌ها</option>
          <option v-for="(label, status) in STATUS_LABELS" :key="status" :value="status">{{ label }}</option>
        </select>
        <select v-model="platformFilter" @change="applyFilters">
          <option value="">همه‌ی پلتفرم‌ها</option>
          <option v-for="(label, platform) in PLATFORM_LABELS" :key="platform" :value="platform">{{ label }}</option>
        </select>
        <button class="primary" @click="exportCsv" :disabled="exporting">{{ exporting ? 'در حال آماده‌سازی...' : '⬇ خروجی CSV' }}</button>
        <span class="muted">مجموع: {{ meta.total }}</span>
      </div>
    </section>

    <section class="card">
      <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>کد پیگیری</th>
              <th>کاربر</th>
              <th>پلتفرم</th>
              <th>وضعیت</th>
              <th>دلیل لغو</th>
              <th>تاریخ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="reg in registrations" :key="reg.id" class="clickable" @click="openRegistration(reg)">
              <td class="muted">{{ reg.tracking_code }}</td>
              <td>
                {{ [reg.bot_user.first_name, reg.bot_user.last_name].filter(Boolean).join(' ') || '—' }}
                <span v-if="reg.bot_user.is_banned" class="badge closed">بن‌شده</span>
                <span v-if="reg.bot_user.is_blocked" class="badge draft">بلاک‌کننده</span>
              </td>
              <td>{{ PLATFORM_LABELS[reg.bot_user.platform] || reg.bot_user.platform }}</td>
              <td><span class="badge" :class="reg.status === 'confirmed' ? 'published' : 'canceled'">{{ STATUS_LABELS[reg.status] }}</span></td>
              <td class="muted">{{ reg.canceled_reason ? (REASON_LABELS[reg.canceled_reason] || reg.canceled_reason) : '—' }}</td>
              <td class="muted">{{ new Date(reg.created_at).toLocaleDateString('fa-IR') }}</td>
              <td class="actions" @click.stop>
                <button v-if="reg.status === 'confirmed'" class="danger small" @click="cancelRegistration(reg)">لغو</button>
                <button v-if="!reg.bot_user.is_banned" class="danger small" @click="banUser(reg)">بن</button>
                <button v-else class="small" @click="unbanUser(reg)">رفع بن</button>
              </td>
            </tr>
            <tr v-if="registrations.length === 0" class="empty-row">
              <td colspan="7">ثبت‌نامی یافت نشد.</td>
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
.actions {
  display: flex;
  gap: 6px;
  justify-content: flex-end;
}
tr.clickable {
  cursor: pointer;
}
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 10px;
}
</style>
