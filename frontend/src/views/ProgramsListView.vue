<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import apiClient from '../api/client'

const programs = ref([])
const newTitle = ref('')
const error = ref('')
const router = useRouter()

async function loadPrograms() {
  const { data } = await apiClient.get('/programs')
  programs.value = data.data
}

async function createProgram() {
  error.value = ''
  try {
    await apiClient.post('/programs', { title: newTitle.value })
    newTitle.value = ''
    await loadPrograms()
  } catch (e) {
    error.value = e.response?.data?.message || 'خطا در ساخت مورد.'
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

onMounted(loadPrograms)
</script>

<template>
  <div class="page">
    <h1>موارد ثبت‌نام (Programs)</h1>

    <form class="create-form" @submit.prevent="createProgram">
      <input v-model="newTitle" placeholder="عنوان مورد جدید" required maxlength="40" />
      <button type="submit">ساخت</button>
    </form>
    <p v-if="error" class="error">{{ error }}</p>

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
        <tr v-for="program in programs" :key="program.id">
          <td>{{ program.title }}</td>
          <td>{{ program.slug }}</td>
          <td><span class="badge" :class="program.status">{{ program.status }}</span></td>
          <td class="actions">
            <button @click="openProgram(program)">مشاهده/ویرایش</button>
            <button v-if="program.status === 'draft'" class="danger" @click="deleteProgram(program)">حذف</button>
          </td>
        </tr>
        <tr v-if="programs.length === 0">
          <td colspan="4">هنوز موردی ثبت نشده.</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.page {
  max-width: 900px;
  margin: 40px auto;
  padding: 0 16px;
}
.create-form {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
}
.create-form input {
  flex: 1;
  padding: 8px;
}
table {
  width: 100%;
  border-collapse: collapse;
}
th, td {
  text-align: right;
  padding: 8px;
  border-bottom: 1px solid #eee;
}
.badge {
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 12px;
  background: #eee;
}
.badge.published { background: #d4edda; }
.badge.draft { background: #fff3cd; }
.badge.closed { background: #f8d7da; }
.badge.canceled { background: #f5c6cb; }
.badge.archived { background: #e2e3e5; }
.actions {
  display: flex;
  gap: 8px;
}
.danger {
  color: #c0392b;
}
.error {
  color: #c0392b;
  font-size: 13px;
}
button {
  cursor: pointer;
  padding: 6px 10px;
}
</style>
