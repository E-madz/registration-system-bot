<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import apiClient from '../api/client'

const programs = ref([])
const newTitle = ref('')
const error = ref('')
const loading = ref(true)
const creating = ref(false)
const statusFilter = ref('')
const router = useRouter()

const STATUS_LABELS = {
  draft: 'پیش‌نویس',
  published: 'منتشرشده',
  closed: 'بسته‌شده',
  canceled: 'لغوشده',
  archived: 'بایگانی',
}

async function loadPrograms() {
  loading.value = true
  try {
    const { data } = await apiClient.get('/programs', {
      params: statusFilter.value ? { status: statusFilter.value } : {},
    })
    programs.value = data.data
  } finally {
    loading.value = false
  }
}

async function createProgram() {
  error.value = ''
  creating.value = true
  try {
    await apiClient.post('/programs', { title: newTitle.value })
    newTitle.value = ''
    await loadPrograms()
  } catch (e) {
    error.value = e.response?.data?.message || 'خطا در ساخت مورد.'
  } finally {
    creating.value = false
  }
}

async function deleteProgram(program) {
  if (!confirm(`مورد «${program.title}» حذف بشه؟`)) return
  try {
    await apiClient.delete(`/programs/${program.id}`)
    await loadPrograms()
  } catch (e) {
    alert(e.response?.data?.message || 'حذف امکان‌پذیر نیست.')
  }
}

function openProgram(program) {
  router.push(`/programs/${program.id}`)
}

function openRegistrations(program) {
  router.push(`/programs/${program.id}/registrations`)
}

onMounted(loadPrograms)
</script>

<template>
  <div class="page">
    <div class="card-header">
      <h1>موارد ثبت‌نام</h1>
    </div>

    <section class="card">
      <h2>ساخت مورد جدید</h2>
      <form class="create-form" @submit.prevent="createProgram">
        <input v-model="newTitle" placeholder="عنوان مورد جدید" required maxlength="40" />
        <button type="submit" class="primary" :disabled="creating">{{ creating ? 'در حال ساخت...' : 'ساخت' }}</button>
      </form>
      <p v-if="error" class="alert alert-error">{{ error }}</p>
    </section>

    <section class="card">
      <div class="card-header">
        <h2>لیست</h2>
        <select v-model="statusFilter" @change="loadPrograms">
          <option value="">همه‌ی وضعیت‌ها</option>
          <option v-for="(label, status) in STATUS_LABELS" :key="status" :value="status">{{ label }}</option>
        </select>
      </div>
      <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
      <div v-else class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>عنوان</th>
            <th>slug</th>
            <th>وضعیت</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="program in programs" :key="program.id" class="clickable" @click="openProgram(program)">
            <td>{{ program.title }}</td>
            <td class="muted">{{ program.slug }}</td>
            <td><span class="badge" :class="program.status">{{ STATUS_LABELS[program.status] || program.status }}</span></td>
            <td class="actions" @click.stop>
              <button class="small" @click="openRegistrations(program)">ثبت‌نام‌ها</button>
              <button class="small" @click="openProgram(program)">مشاهده/ویرایش</button>
              <button v-if="program.status === 'draft'" class="danger small" @click="deleteProgram(program)">حذف</button>
            </td>
          </tr>
          <tr v-if="programs.length === 0" class="empty-row">
            <td colspan="4">موردی یافت نشد.</td>
          </tr>
        </tbody>
      </table>
      </div>
    </section>
  </div>
</template>

<style scoped>
.create-form {
  display: flex;
  gap: 10px;
}
.create-form input {
  flex: 1;
}
.actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
tr.clickable {
  cursor: pointer;
}
</style>
