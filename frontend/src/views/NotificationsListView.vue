<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
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

const route = useRoute()
const router = useRouter()

const notifications = ref([])
const meta = reactive({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(true)
const page = ref(1)

const programs = ref([])
const form = reactive({
  message: '',
  audience_type: 'all_users',
  audience_program_id: '',
  inactive_days: 30,
  audience_platform: '',
  button_label: '',
  button_program_id: '',
})
const sending = ref(false)
const formError = ref('')
const formSuccess = ref('')

const needsProgram = computed(() => form.audience_type === 'program_registrants')
const needsInactiveDays = computed(() => form.audience_type === 'inactive_users')

async function loadPrograms() {
  const { data } = await apiClient.get('/programs', { params: { per_page: 100 } })
  programs.value = data.data
}

async function loadNotifications() {
  loading.value = true
  try {
    const { data } = await apiClient.get('/notifications', { params: { page: page.value } })
    notifications.value = data.data
    Object.assign(meta, data.meta)
  } finally {
    loading.value = false
  }
}

function goToPage(p) {
  if (p < 1 || p > meta.last_page) return
  page.value = p
  loadNotifications()
}

async function send() {
  formError.value = ''
  formSuccess.value = ''

  const payload = {
    message: form.message,
    audience_type: form.audience_type,
    audience_platform: form.audience_platform || null,
  }
  if (needsProgram.value) payload.audience_program_id = form.audience_program_id
  if (needsInactiveDays.value) payload.inactive_days = form.inactive_days
  if (form.button_label && form.button_program_id) {
    payload.button_label = form.button_label
    payload.button_program_id = form.button_program_id
  }

  if (!confirm('این پیام برای مخاطبان انتخاب‌شده ارسال می‌شود و قابل بازگشت نیست. ادامه می‌دهید؟')) return

  sending.value = true
  try {
    const { data } = await apiClient.post('/notifications', payload)
    formSuccess.value = `ارسال آغاز شد. تعداد مخاطبان: ${data.data.total_recipients}`
    form.message = ''
    form.button_label = ''
    form.button_program_id = ''
    page.value = 1
    await loadNotifications()
  } catch (e) {
    const errors = e.response?.data?.errors
    formError.value = errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || 'خطا در ارسال اعلان.')
  } finally {
    sending.value = false
  }
}

function openNotification(n) {
  router.push(`/notifications/${n.id}`)
}

onMounted(() => {
  loadPrograms()
  if (route.query.program_id) {
    form.audience_type = 'program_registrants'
    form.audience_program_id = Number(route.query.program_id)
  }
  loadNotifications()
})
</script>

<template>
  <div class="page">
    <h1>اطلاع‌رسانی‌ها</h1>

    <section class="card">
      <h2>ارسال اعلان دستی</h2>
      <form class="form-grid" @submit.prevent="send">
        <label class="span-2">متن پیام<textarea v-model="form.message" required maxlength="4096"></textarea></label>

        <label>
          مخاطب
          <select v-model="form.audience_type">
            <option value="all_users">همه‌ی کاربران بات</option>
            <option value="program_registrants">ثبت‌نام‌شدگان یک برنامه</option>
            <option value="inactive_users">کاربران غیرفعال</option>
          </select>
        </label>
        <label>
          پلتفرم (اختیاری)
          <select v-model="form.audience_platform">
            <option value="">همه‌ی پلتفرم‌ها</option>
            <option value="telegram">تلگرام</option>
            <option value="bale">بله</option>
            <option value="rubika">روبیکا</option>
          </select>
        </label>

        <label v-if="needsProgram">
          برنامه
          <select v-model="form.audience_program_id" required>
            <option value="" disabled>انتخاب برنامه</option>
            <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
        </label>
        <label v-if="needsInactiveDays">
          حداقل روز عدم فعالیت
          <input v-model.number="form.inactive_days" type="number" min="1" required />
        </label>

        <label>برچسب دکمه (اختیاری)<input v-model="form.button_label" maxlength="40" placeholder="مثلاً «مشاهده برنامه»" /></label>
        <label>
          برنامه‌ی مقصد دکمه (اختیاری)
          <select v-model="form.button_program_id">
            <option value="">بدون دکمه</option>
            <option v-for="p in programs" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
        </label>

        <div class="span-2 row">
          <button type="submit" class="primary" :disabled="sending || !form.message">{{ sending ? 'در حال ارسال...' : 'ارسال' }}</button>
          <p v-if="formSuccess" class="alert alert-success">{{ formSuccess }}</p>
          <p v-if="formError" class="alert alert-error">{{ formError }}</p>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>تاریخچه‌ی اعلان‌ها</h2>
      <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>پیام</th>
              <th>منبع</th>
              <th>مخاطب</th>
              <th>وضعیت</th>
              <th>ارسال‌شده / کل</th>
              <th>ناموفق</th>
              <th>تاریخ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="n in notifications" :key="n.id" class="clickable" @click="openNotification(n)">
              <td class="truncate">{{ n.message }}</td>
              <td class="muted">{{ SOURCE_LABELS[n.source] || n.source }}</td>
              <td class="muted">
                {{ AUDIENCE_LABELS[n.audience_type] || n.audience_type }}
                <span v-if="n.audience_program_title">— {{ n.audience_program_title }}</span>
                <span v-if="n.audience_platform">({{ PLATFORM_LABELS[n.audience_platform] }})</span>
              </td>
              <td><span class="badge" :class="n.status === 'completed' ? 'published' : 'draft'">{{ STATUS_LABELS[n.status] }}</span></td>
              <td>{{ n.sent_count }} / {{ n.total_recipients }}</td>
              <td class="muted">{{ n.failed_count }}</td>
              <td class="muted">{{ new Date(n.created_at).toLocaleDateString('fa-IR') }}</td>
            </tr>
            <tr v-if="notifications.length === 0" class="empty-row">
              <td colspan="7">هنوز اعلانی ارسال نشده.</td>
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
tr.clickable {
  cursor: pointer;
}
.truncate {
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 10px;
}
</style>
