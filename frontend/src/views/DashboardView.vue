<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import apiClient from '../api/client'

const PLATFORM_LABELS = { telegram: 'تلگرام', bale: 'بله', rubika: 'روبیکا' }
const STATUS_LABELS = {
  draft: 'پیش‌نویس',
  published: 'منتشرشده',
  closed: 'بسته‌شده',
  canceled: 'لغوشده',
  archived: 'بایگانی',
}

const router = useRouter()
const loading = ref(true)
const stats = reactive({
  programs_total: 0,
  programs_by_status: {},
  confirmed_registrations_total: 0,
  canceled_registrations_total: 0,
  banned_users_total: 0,
  blocked_users_total: 0,
  registrations_by_platform: {},
  programs_near_capacity: [],
  recent_registrations: [],
})

async function load() {
  loading.value = true
  const { data } = await apiClient.get('/dashboard')
  Object.assign(stats, data.data)
  loading.value = false
}

function openProgram(id) {
  router.push(`/programs/${id}`)
}

function openRegistration(reg) {
  if (reg.program) router.push(`/programs/${reg.program.id}/registrations/${reg.id}`)
}

onMounted(load)
</script>

<template>
  <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
  <div class="page" v-else>
    <h1>داشبورد</h1>

    <div class="cards-grid">
      <div class="card stat-card">
        <span class="stat-value">{{ stats.programs_total }}</span>
        <span class="muted">کل برنامه‌ها</span>
      </div>
      <div class="card stat-card">
        <span class="stat-value">{{ stats.confirmed_registrations_total }}</span>
        <span class="muted">ثبت‌نام تاییدشده</span>
      </div>
      <div class="card stat-card">
        <span class="stat-value">{{ stats.canceled_registrations_total }}</span>
        <span class="muted">ثبت‌نام لغوشده</span>
      </div>
      <div class="card stat-card">
        <span class="stat-value">{{ stats.banned_users_total }}</span>
        <span class="muted">کاربر بن‌شده</span>
      </div>
      <div class="card stat-card">
        <span class="stat-value">{{ stats.blocked_users_total }}</span>
        <span class="muted">کاربر بلاک‌کننده‌ی بات</span>
      </div>
    </div>

    <div class="two-col">
      <section class="card">
        <h2>برنامه‌ها بر اساس وضعیت</h2>
        <div class="breakdown">
          <div v-for="(count, status) in stats.programs_by_status" :key="status" class="breakdown-row">
            <span class="badge" :class="status">{{ STATUS_LABELS[status] || status }}</span>
            <span>{{ count }}</span>
          </div>
        </div>
      </section>

      <section class="card">
        <h2>ثبت‌نام‌های تاییدشده بر اساس پلتفرم</h2>
        <div v-if="Object.keys(stats.registrations_by_platform).length === 0" class="muted">هنوز ثبت‌نامی نیست.</div>
        <div class="breakdown" v-else>
          <div v-for="(count, platform) in stats.registrations_by_platform" :key="platform" class="breakdown-row">
            <span>{{ PLATFORM_LABELS[platform] || platform }}</span>
            <span>{{ count }}</span>
          </div>
        </div>
      </section>
    </div>

    <section class="card">
      <h2>برنامه‌های نزدیک به تکمیل ظرفیت</h2>
      <div v-if="stats.programs_near_capacity.length === 0" class="muted">موردی نیست.</div>
      <table v-else>
        <thead>
          <tr>
            <th>عنوان</th>
            <th>ظرفیت باقی‌مانده</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in stats.programs_near_capacity" :key="row.program.id" class="clickable" @click="openProgram(row.program.id)">
            <td>{{ row.program.title }}</td>
            <td>{{ row.remaining }}</td>
          </tr>
        </tbody>
      </table>
    </section>

    <section class="card">
      <h2>آخرین ثبت‌نام‌ها</h2>
      <div v-if="stats.recent_registrations.length === 0" class="muted">هنوز ثبت‌نامی نیست.</div>
      <table v-else>
        <thead>
          <tr>
            <th>برنامه</th>
            <th>کاربر</th>
            <th>پلتفرم</th>
            <th>وضعیت</th>
            <th>تاریخ</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="reg in stats.recent_registrations" :key="reg.id" class="clickable" @click="openRegistration(reg)">
            <td>{{ reg.program?.title }}</td>
            <td>{{ [reg.bot_user.first_name, reg.bot_user.last_name].filter(Boolean).join(' ') || '—' }}</td>
            <td>{{ PLATFORM_LABELS[reg.bot_user.platform] || reg.bot_user.platform }}</td>
            <td>
              <span class="badge" :class="reg.status === 'confirmed' ? 'published' : 'canceled'">
                {{ reg.status === 'confirmed' ? 'تاییدشده' : 'لغوشده' }}
              </span>
            </td>
            <td class="muted">{{ new Date(reg.created_at).toLocaleDateString('fa-IR') }}</td>
          </tr>
        </tbody>
      </table>
    </section>
  </div>
</template>

<style scoped>
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
  font-size: 26px;
  font-weight: 700;
  color: var(--color-primary);
}
.two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}
@media (max-width: 720px) {
  .two-col {
    grid-template-columns: 1fr;
  }
}
.breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.breakdown-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 14px;
}
tr.clickable {
  cursor: pointer;
}
</style>
