<script setup>
import { onMounted, ref } from 'vue'
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
const registrationId = route.params.registrationId

const registration = ref(null)
const loading = ref(true)

async function load() {
  loading.value = true
  const { data } = await apiClient.get(`/programs/${programId}/registrations/${registrationId}`)
  registration.value = data.data
  loading.value = false
}

function formatAnswer(answer) {
  const value = answer.value
  if (value === null || value === undefined) return 'رد شد'
  if (typeof value === 'object' && value.file_id) return '📎 فایل ارسال شد'
  if (Array.isArray(value)) return value.join('، ')
  return String(value)
}

async function cancelRegistration() {
  if (!confirm('این ثبت‌نام لغو بشه؟')) return
  await apiClient.patch(`/programs/${programId}/registrations/${registrationId}/cancel`)
  await load()
}

async function banUser() {
  const reason = prompt('دلیل بن کردن (اختیاری):')
  await apiClient.patch(`/bot-users/${registration.value.bot_user.id}/ban`, { reason: reason || null })
  await load()
}

async function unbanUser() {
  if (!confirm('بن این کاربر برداشته بشه؟')) return
  await apiClient.patch(`/bot-users/${registration.value.bot_user.id}/unban`)
  await load()
}

function goBack() {
  router.push(`/programs/${programId}/registrations`)
}

onMounted(load)
</script>

<template>
  <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
  <div class="page" v-else-if="registration">
    <div class="card-header">
      <button class="ghost small" @click="goBack">→ بازگشت به لیست ثبت‌نام‌ها</button>
    </div>
    <h1>ثبت‌نام {{ registration.tracking_code }}</h1>

    <section class="card">
      <div class="card-header">
        <h2>اطلاعات کاربر</h2>
        <span class="badge" :class="registration.status === 'confirmed' ? 'published' : 'canceled'">
          {{ STATUS_LABELS[registration.status] }}
        </span>
      </div>
      <div class="info-grid">
        <div><span class="muted">نام:</span> {{ [registration.bot_user.first_name, registration.bot_user.last_name].filter(Boolean).join(' ') || '—' }}</div>
        <div><span class="muted">نام کاربری:</span> {{ registration.bot_user.username || '—' }}</div>
        <div><span class="muted">پلتفرم:</span> {{ PLATFORM_LABELS[registration.bot_user.platform] || registration.bot_user.platform }}</div>
        <div><span class="muted">تاریخ ثبت‌نام:</span> {{ new Date(registration.created_at).toLocaleString('fa-IR') }}</div>
        <div v-if="registration.canceled_reason"><span class="muted">دلیل لغو:</span> {{ REASON_LABELS[registration.canceled_reason] || registration.canceled_reason }}</div>
        <div v-if="registration.bot_user.is_banned"><span class="muted">بن:</span> {{ registration.bot_user.ban_reason || 'بدون دلیل ذکرشده' }}</div>
        <div v-if="registration.bot_user.is_blocked"><span class="muted">بلاک بات:</span> بله</div>
      </div>
      <div class="row">
        <button v-if="registration.status === 'confirmed'" class="danger small" @click="cancelRegistration">لغو ثبت‌نام</button>
        <button v-if="!registration.bot_user.is_banned" class="danger small" @click="banUser">بن کردن کاربر</button>
        <button v-else class="small" @click="unbanUser">رفع بن</button>
      </div>
    </section>

    <section class="card">
      <h2>جواب‌ها</h2>
      <div v-if="registration.answers.length === 0" class="muted">این برنامه فیلدی نداشته.</div>
      <div v-else class="answers-list">
        <div v-for="answer in registration.answers" :key="answer.field_key" class="answer-row">
          <span class="answer-label">
            {{ answer.field_label }}
            <span v-if="!answer.program_field_id" class="muted">(حذف‌شده)</span>
          </span>
          <span class="answer-value">{{ formatAnswer(answer) }}</span>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px;
  font-size: 14px;
}
@media (max-width: 640px) {
  .info-grid {
    grid-template-columns: 1fr;
  }
}
.row {
  display: flex;
  gap: 10px;
}
.answers-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.answer-row {
  display: flex;
  justify-content: space-between;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--color-border);
  font-size: 14px;
}
.answer-row:last-child {
  border-bottom: none;
}
.answer-label {
  color: var(--color-text-muted);
  flex-shrink: 0;
}
.answer-value {
  text-align: left;
  font-weight: 500;
}
</style>
