<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '../api/client'

const AUDIENCE_LABELS = {
  program_registrants: 'ثبت‌نام‌شدگان یک برنامه',
  all_users: 'همه‌ی کاربران بات',
  inactive_users: 'کاربران غیرفعال',
  single_user: 'یک کاربر خاص',
}
const SOURCE_LABELS = {
  manual: 'دستی',
  auto_ban: 'خودکار (بن)',
  auto_unban: 'خودکار (رفع بن)',
  auto_program_canceled: 'خودکار (لغو برنامه)',
  auto_program_published: 'خودکار (انتشار برنامه)',
  auto_registration_canceled: 'خودکار (لغو ثبت‌نام)',
}
const STATUS_LABELS = { pending: 'در حال ارسال', completed: 'تکمیل‌شده' }
const PLATFORM_LABELS = { telegram: 'تلگرام', bale: 'بله', rubika: 'روبیکا' }
const DELIVERY_STATUS_LABELS = {
  pending: 'در انتظار',
  sent: 'ارسال‌شده',
  failed: 'ناموفق',
  skipped_banned: 'ردشده (بن)',
  skipped_blocked: 'ردشده (بلاک)',
}
const DELIVERY_BADGE_CLASS = {
  pending: 'draft',
  sent: 'published',
  failed: 'canceled',
  skipped_banned: 'closed',
  skipped_blocked: 'closed',
}

const route = useRoute()
const router = useRouter()
const notification = ref(null)
const loading = ref(true)
let poll = null

async function load() {
  const { data } = await apiClient.get(`/notifications/${route.params.id}`)
  notification.value = data.data
  loading.value = false

  if (notification.value.status === 'pending') {
    poll = setTimeout(load, 3000)
  }
}

function goBack() {
  router.push('/notifications')
}

onMounted(load)
onUnmounted(() => {
  if (poll) clearTimeout(poll)
})
</script>

<template>
  <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
  <div class="page" v-else>
    <div class="card-header">
      <button class="ghost small" @click="goBack">→ بازگشت به اعلان‌ها</button>
    </div>

    <section class="card">
      <div class="card-header">
        <h1>جزئیات اعلان</h1>
        <span class="badge" :class="notification.status === 'completed' ? 'published' : 'draft'">{{ STATUS_LABELS[notification.status] }}</span>
      </div>
      <p class="message-box">{{ notification.message }}</p>
      <div class="stats-row">
        <span class="muted">منبع: {{ SOURCE_LABELS[notification.source] || notification.source }}</span>
        <span class="muted">
          مخاطب: {{ AUDIENCE_LABELS[notification.audience_type] || notification.audience_type }}
          <template v-if="notification.audience_program_title">— {{ notification.audience_program_title }}</template>
        </span>
        <span v-if="notification.button_label" class="muted">دکمه: «{{ notification.button_label }}»</span>
      </div>
      <div class="cards-grid">
        <div class="card stat-card"><span class="stat-value">{{ notification.total_recipients }}</span><span class="muted">کل مخاطبان</span></div>
        <div class="card stat-card"><span class="stat-value">{{ notification.sent_count }}</span><span class="muted">ارسال‌شده</span></div>
        <div class="card stat-card"><span class="stat-value">{{ notification.failed_count }}</span><span class="muted">ناموفق</span></div>
        <div class="card stat-card"><span class="stat-value">{{ notification.skipped_banned_count }}</span><span class="muted">ردشده (بن)</span></div>
        <div class="card stat-card"><span class="stat-value">{{ notification.skipped_blocked_count }}</span><span class="muted">ردشده (بلاک)</span></div>
      </div>
    </section>

    <section class="card">
      <h2>وضعیت ارسال به تفکیک کاربر</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>کاربر</th>
              <th>پلتفرم</th>
              <th>وضعیت</th>
              <th>خطا</th>
              <th>زمان ارسال</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in notification.deliveries" :key="d.id">
              <td>{{ d.bot_user_name || '—' }}</td>
              <td class="muted">{{ PLATFORM_LABELS[d.platform] || d.platform }}</td>
              <td><span class="badge" :class="DELIVERY_BADGE_CLASS[d.status]">{{ DELIVERY_STATUS_LABELS[d.status] }}</span></td>
              <td class="muted">{{ d.error_message || '—' }}</td>
              <td class="muted">{{ d.sent_at ? new Date(d.sent_at).toLocaleString('fa-IR') : '—' }}</td>
            </tr>
            <tr v-if="notification.deliveries.length === 0" class="empty-row">
              <td colspan="5">مخاطبی وجود ندارد.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.message-box {
  white-space: pre-wrap;
  background: var(--color-bg);
  border-radius: var(--radius-md);
  padding: 12px;
}
.stats-row {
  display: flex;
  gap: 20px;
  flex-wrap: wrap;
  font-size: 14px;
  margin: 10px 0;
}
.cards-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
}
@media (max-width: 900px) {
  .cards-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
.stat-card {
  align-items: center;
  text-align: center;
  gap: 6px;
}
.stat-value {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-primary);
}
</style>
