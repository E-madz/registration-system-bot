<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '../api/client'

const TRANSITIONS = {
  draft: ['published'],
  published: ['closed', 'canceled'],
  closed: ['published', 'canceled', 'archived'],
  canceled: ['archived'],
  archived: [],
}

const FIELD_TYPES = ['text', 'textarea', 'number', 'email', 'phone', 'date', 'select', 'multiselect', 'file']
const CHOICE_TYPES = ['select', 'multiselect']

const route = useRoute()
const router = useRouter()
const programId = route.params.id

const program = reactive({
  title: '',
  description: '',
  registration_starts_at: '',
  registration_ends_at: '',
  capacity: null,
  requires_approval: false,
  success_message: '',
  status: 'draft',
  media_url: null,
})
const fields = ref([])
const loading = ref(true)
const saveError = ref('')
const saveMessage = ref('')
const newStatus = ref('')
const mediaError = ref('')
const mediaUploading = ref(false)

const newField = reactive({
  label: '',
  key: '',
  type: 'text',
  help_text: '',
  options: '',
  is_required: false,
})
const fieldError = ref('')

const availableTransitions = computed(() => TRANSITIONS[program.status] || [])

async function loadProgram() {
  loading.value = true
  const { data } = await apiClient.get(`/programs/${programId}`)
  Object.assign(program, data.data)
  fields.value = data.data.fields
  loading.value = false
}

async function saveProgram() {
  saveError.value = ''
  saveMessage.value = ''
  try {
    const { data } = await apiClient.put(`/programs/${programId}`, {
      title: program.title,
      description: program.description,
      registration_starts_at: program.registration_starts_at || null,
      registration_ends_at: program.registration_ends_at || null,
      capacity: program.capacity || null,
      requires_approval: program.requires_approval,
      success_message: program.success_message,
    })
    Object.assign(program, data.data)
    saveMessage.value = 'ذخیره شد.'
  } catch (e) {
    const errors = e.response?.data?.errors
    saveError.value = errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || 'خطا در ذخیره‌سازی.')
  }
}

async function uploadMedia(event) {
  const file = event.target.files[0]
  if (!file) return
  mediaError.value = ''
  mediaUploading.value = true
  try {
    const formData = new FormData()
    formData.append('media', file)
    const { data } = await apiClient.post(`/programs/${programId}/media`, formData)
    program.media_url = data.data.media_url
  } catch (e) {
    mediaError.value = e.response?.data?.message || 'خطا در آپلود عکس.'
  } finally {
    mediaUploading.value = false
    event.target.value = ''
  }
}

async function deleteMedia() {
  if (!confirm('عکس حذف بشه؟')) return
  const { data } = await apiClient.delete(`/programs/${programId}/media`)
  program.media_url = data.data.media_url
}

async function changeStatus() {
  if (!newStatus.value) return
  try {
    const { data } = await apiClient.patch(`/programs/${programId}/status`, { status: newStatus.value })
    program.status = data.data.status
    newStatus.value = ''
  } catch (e) {
    alert(e.response?.data?.message || 'تغییر وضعیت امکان‌پذیر نیست.')
  }
}

async function addField() {
  fieldError.value = ''
  try {
    const payload = {
      label: newField.label,
      key: newField.key,
      type: newField.type,
      help_text: newField.help_text || null,
      is_required: newField.is_required,
    }
    if (CHOICE_TYPES.includes(newField.type)) {
      payload.options = newField.options.split(',').map((o) => o.trim()).filter(Boolean)
    }
    await apiClient.post(`/programs/${programId}/fields`, payload)
    Object.assign(newField, { label: '', key: '', type: 'text', help_text: '', options: '', is_required: false })
    await loadProgram()
  } catch (e) {
    fieldError.value = e.response?.data?.message || 'خطا در افزودن فیلد.'
  }
}

async function deleteField(field) {
  if (!confirm(`فیلد «${field.label}» حذف بشه؟`)) return
  await apiClient.delete(`/programs/${programId}/fields/${field.id}`)
  await loadProgram()
}

async function moveField(index, direction) {
  const target = index + direction
  if (target < 0 || target >= fields.value.length) return
  const order = fields.value.map((f) => f.id)
  ;[order[index], order[target]] = [order[target], order[index]]
  await apiClient.post(`/programs/${programId}/fields/reorder`, { order })
  await loadProgram()
}

function goBack() {
  router.push('/programs')
}

onMounted(loadProgram)
</script>

<template>
  <div class="page" v-if="!loading">
    <button class="back" @click="goBack">→ بازگشت به لیست</button>
    <h1>{{ program.title }}</h1>

    <section class="card">
      <h2>اطلاعات پایه</h2>
      <label>عنوان<input v-model="program.title" maxlength="40" /></label>
      <label>توضیحات<textarea v-model="program.description"></textarea></label>
      <label>شروع ثبت‌نام<input v-model="program.registration_starts_at" type="datetime-local" /></label>
      <label>پایان ثبت‌نام<input v-model="program.registration_ends_at" type="datetime-local" /></label>
      <label>ظرفیت<input v-model.number="program.capacity" type="number" min="1" /></label>
      <label class="checkbox"><input v-model="program.requires_approval" type="checkbox" /> نیاز به تایید ادمین</label>
      <label>پیام موفقیت<textarea v-model="program.success_message"></textarea></label>
      <button @click="saveProgram">ذخیره</button>
      <p v-if="saveMessage" class="success">{{ saveMessage }}</p>
      <p v-if="saveError" class="error">{{ saveError }}</p>
    </section>

    <section class="card">
      <h2>عکس برنامه</h2>
      <img v-if="program.media_url" :src="program.media_url" alt="" class="media-preview" />
      <p v-else>عکسی تنظیم نشده.</p>
      <input type="file" accept="image/png,image/jpeg,image/webp" @change="uploadMedia" :disabled="mediaUploading" />
      <button v-if="program.media_url" class="danger" @click="deleteMedia">حذف عکس</button>
      <p v-if="mediaUploading">در حال آپلود...</p>
      <p v-if="mediaError" class="error">{{ mediaError }}</p>
    </section>

    <section class="card">
      <h2>وضعیت: <span class="badge" :class="program.status">{{ program.status }}</span></h2>
      <div v-if="availableTransitions.length" class="status-change">
        <select v-model="newStatus">
          <option value="" disabled>انتخاب وضعیت جدید</option>
          <option v-for="s in availableTransitions" :key="s" :value="s">{{ s }}</option>
        </select>
        <button @click="changeStatus">تغییر وضعیت</button>
      </div>
      <p v-else>این وضعیت نهایی است و قابل تغییر نیست.</p>
    </section>

    <section class="card">
      <h2>فیلدهای ثبت‌نام</h2>
      <table>
        <thead>
          <tr>
            <th>ترتیب</th>
            <th>برچسب</th>
            <th>key</th>
            <th>نوع</th>
            <th>اجباری</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(field, index) in fields" :key="field.id">
            <td class="order-cell">
              <button @click="moveField(index, -1)" :disabled="index === 0">↑</button>
              <button @click="moveField(index, 1)" :disabled="index === fields.length - 1">↓</button>
            </td>
            <td>{{ field.label }}</td>
            <td>{{ field.key }}</td>
            <td>{{ field.type }}</td>
            <td>{{ field.is_required ? 'بله' : 'خیر' }}</td>
            <td><button class="danger" @click="deleteField(field)">حذف</button></td>
          </tr>
          <tr v-if="fields.length === 0">
            <td colspan="6">هنوز فیلدی اضافه نشده.</td>
          </tr>
        </tbody>
      </table>

      <h3>افزودن فیلد جدید</h3>
      <form class="field-form" @submit.prevent="addField">
        <input v-model="newField.label" placeholder="برچسب" maxlength="40" required />
        <input v-model="newField.key" placeholder="key (فقط حروف لاتین کوچک و _)" required />
        <select v-model="newField.type">
          <option v-for="t in FIELD_TYPES" :key="t" :value="t">{{ t }}</option>
        </select>
        <input v-model="newField.help_text" placeholder="متن راهنما" maxlength="50" />
        <input
          v-if="CHOICE_TYPES.includes(newField.type)"
          v-model="newField.options"
          placeholder="گزینه‌ها با کاما جدا شوند"
        />
        <label class="checkbox"><input v-model="newField.is_required" type="checkbox" /> اجباری</label>
        <button type="submit">افزودن</button>
      </form>
      <p v-if="fieldError" class="error">{{ fieldError }}</p>
    </section>
  </div>
</template>

<style scoped>
.page {
  max-width: 900px;
  margin: 40px auto;
  padding: 0 16px;
}
.back {
  margin-bottom: 12px;
  cursor: pointer;
}
.card {
  border: 1px solid #ddd;
  border-radius: 8px;
  padding: 16px;
  margin-bottom: 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 14px;
}
label.checkbox {
  flex-direction: row;
  align-items: center;
  gap: 6px;
}
input, textarea, select {
  padding: 8px;
  font-size: 14px;
}
button {
  cursor: pointer;
  padding: 6px 10px;
  width: fit-content;
}
table {
  width: 100%;
  border-collapse: collapse;
}
th, td {
  text-align: right;
  padding: 6px;
  border-bottom: 1px solid #eee;
}
.order-cell button {
  padding: 2px 6px;
}
.field-form {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}
.field-form input, .field-form select {
  flex: 1 1 150px;
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
.danger {
  color: #c0392b;
}
.success {
  color: #27632a;
  font-size: 13px;
}
.error {
  color: #c0392b;
  font-size: 13px;
}
.media-preview {
  max-width: 100%;
  max-height: 240px;
  border-radius: 6px;
  object-fit: cover;
}
</style>
