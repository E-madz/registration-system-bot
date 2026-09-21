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

const STATUS_LABELS = {
  draft: 'پیش‌نویس',
  published: 'منتشرشده',
  closed: 'بسته‌شده',
  canceled: 'لغوشده',
  archived: 'بایگانی',
}

const FIELD_TYPES = ['text', 'textarea', 'number', 'email', 'phone', 'date', 'select', 'multiselect', 'file']
const FIELD_TYPE_LABELS = {
  text: 'متن کوتاه',
  textarea: 'متن بلند',
  number: 'عدد',
  email: 'ایمیل',
  phone: 'شماره تماس',
  date: 'تاریخ',
  select: 'انتخاب تکی',
  multiselect: 'انتخاب چندتایی',
  file: 'فایل/عکس',
}
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
const saving = ref(false)
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
const addingField = ref(false)

const editingFieldId = ref(null)
const editField = reactive({
  label: '',
  key: '',
  type: 'text',
  help_text: '',
  options: '',
  is_required: false,
})
const editFieldError = ref('')
const savingField = ref(false)

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
  saving.value = true
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
  } finally {
    saving.value = false
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
  addingField.value = true
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
    const errors = e.response?.data?.errors
    fieldError.value = errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || 'خطا در افزودن فیلد.')
  } finally {
    addingField.value = false
  }
}

async function deleteField(field) {
  if (!confirm(`فیلد «${field.label}» حذف بشه؟`)) return
  await apiClient.delete(`/programs/${programId}/fields/${field.id}`)
  await loadProgram()
}

function startEditField(field) {
  editingFieldId.value = field.id
  editFieldError.value = ''
  Object.assign(editField, {
    label: field.label,
    key: field.key,
    type: field.type,
    help_text: field.help_text || '',
    options: (field.options || []).join(', '),
    is_required: field.is_required,
  })
}

function cancelEditField() {
  editingFieldId.value = null
  editFieldError.value = ''
}

async function saveEditField(field) {
  editFieldError.value = ''
  savingField.value = true
  try {
    const payload = {
      label: editField.label,
      key: editField.key,
      type: editField.type,
      help_text: editField.help_text || null,
      is_required: editField.is_required,
    }
    if (CHOICE_TYPES.includes(editField.type)) {
      payload.options = editField.options.split(',').map((o) => o.trim()).filter(Boolean)
    }
    await apiClient.put(`/programs/${programId}/fields/${field.id}`, payload)
    editingFieldId.value = null
    await loadProgram()
  } catch (e) {
    const errors = e.response?.data?.errors
    editFieldError.value = errors ? Object.values(errors).flat().join(' ') : (e.response?.data?.message || 'خطا در ذخیره فیلد.')
  } finally {
    savingField.value = false
  }
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
  <div v-if="loading" class="spinner-wrap"><div class="spinner"></div></div>
  <div class="page" v-else>
    <div class="card-header">
      <button class="ghost small" @click="goBack">→ بازگشت به لیست</button>
    </div>
    <h1>{{ program.title }}</h1>

    <section class="card">
      <h2>اطلاعات پایه</h2>
      <div class="form-grid">
        <label class="span-2">عنوان<input v-model="program.title" maxlength="40" /></label>
        <label class="span-2">توضیحات<textarea v-model="program.description"></textarea></label>
        <label>شروع ثبت‌نام<input v-model="program.registration_starts_at" type="datetime-local" /></label>
        <label>پایان ثبت‌نام<input v-model="program.registration_ends_at" type="datetime-local" /></label>
        <label>ظرفیت<input v-model.number="program.capacity" type="number" min="1" placeholder="نامحدود" /></label>
        <label class="checkbox align-end"><input v-model="program.requires_approval" type="checkbox" /> نیاز به تایید ادمین</label>
        <label class="span-2">پیام موفقیت<textarea v-model="program.success_message" placeholder="پس از ثبت‌نام موفق به کاربر نمایش داده می‌شود"></textarea></label>
      </div>
      <div class="row">
        <button class="primary" @click="saveProgram" :disabled="saving">{{ saving ? 'در حال ذخیره...' : 'ذخیره' }}</button>
        <p v-if="saveMessage" class="alert alert-success">{{ saveMessage }}</p>
        <p v-if="saveError" class="alert alert-error">{{ saveError }}</p>
      </div>
    </section>

    <section class="card">
      <h2>عکس برنامه</h2>
      <img v-if="program.media_url" :src="program.media_url" alt="" class="media-preview" />
      <p v-else class="muted">عکسی تنظیم نشده.</p>
      <div class="row">
        <input type="file" accept="image/png,image/jpeg,image/webp" @change="uploadMedia" :disabled="mediaUploading" />
        <button v-if="program.media_url" class="danger small" @click="deleteMedia">حذف عکس</button>
      </div>
      <p v-if="mediaUploading" class="muted">در حال آپلود...</p>
      <p v-if="mediaError" class="alert alert-error">{{ mediaError }}</p>
    </section>

    <section class="card">
      <div class="card-header">
        <h2>وضعیت</h2>
        <span class="badge" :class="program.status">{{ STATUS_LABELS[program.status] || program.status }}</span>
      </div>
      <div v-if="availableTransitions.length" class="row">
        <select v-model="newStatus">
          <option value="" disabled>انتخاب وضعیت جدید</option>
          <option v-for="s in availableTransitions" :key="s" :value="s">{{ STATUS_LABELS[s] || s }}</option>
        </select>
        <button class="primary" @click="changeStatus" :disabled="!newStatus">تغییر وضعیت</button>
      </div>
      <p v-else class="muted">این وضعیت نهایی است و قابل تغییر نیست.</p>
    </section>

    <section class="card">
      <h2>فیلدهای ثبت‌نام</h2>
      <div class="table-wrap">
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
          <template v-for="(field, index) in fields" :key="field.id">
            <tr v-if="editingFieldId !== field.id">
              <td class="order-cell">
                <button class="small ghost" @click="moveField(index, -1)" :disabled="index === 0">↑</button>
                <button class="small ghost" @click="moveField(index, 1)" :disabled="index === fields.length - 1">↓</button>
              </td>
              <td>{{ field.label }}</td>
              <td class="muted">{{ field.key }}</td>
              <td>{{ FIELD_TYPE_LABELS[field.type] || field.type }}</td>
              <td>{{ field.is_required ? 'بله' : 'خیر' }}</td>
              <td class="actions">
                <button class="small" @click="startEditField(field)">ویرایش</button>
                <button class="danger small" @click="deleteField(field)">حذف</button>
              </td>
            </tr>
            <tr v-else class="edit-row">
              <td colspan="6">
                <div class="field-form">
                  <input v-model="editField.label" placeholder="برچسب" maxlength="40" required />
                  <input v-model="editField.key" placeholder="key (فقط حروف لاتین کوچک و _)" required />
                  <select v-model="editField.type">
                    <option v-for="t in FIELD_TYPES" :key="t" :value="t">{{ FIELD_TYPE_LABELS[t] }}</option>
                  </select>
                  <input v-model="editField.help_text" placeholder="متن راهنما" maxlength="50" />
                  <input
                    v-if="CHOICE_TYPES.includes(editField.type)"
                    v-model="editField.options"
                    placeholder="گزینه‌ها با کاما جدا شوند"
                  />
                  <label class="checkbox"><input v-model="editField.is_required" type="checkbox" /> اجباری</label>
                  <div class="row">
                    <button class="primary small" @click="saveEditField(field)" :disabled="savingField">ذخیره</button>
                    <button class="ghost small" @click="cancelEditField">انصراف</button>
                  </div>
                </div>
                <p v-if="editFieldError" class="alert alert-error">{{ editFieldError }}</p>
              </td>
            </tr>
          </template>
          <tr v-if="fields.length === 0" class="empty-row">
            <td colspan="6">هنوز فیلدی اضافه نشده.</td>
          </tr>
        </tbody>
      </table>
      </div>

      <h3>افزودن فیلد جدید</h3>
      <form class="field-form" @submit.prevent="addField">
        <input v-model="newField.label" placeholder="برچسب" maxlength="40" required />
        <input v-model="newField.key" placeholder="key (فقط حروف لاتین کوچک و _)" required />
        <select v-model="newField.type">
          <option v-for="t in FIELD_TYPES" :key="t" :value="t">{{ FIELD_TYPE_LABELS[t] }}</option>
        </select>
        <input v-model="newField.help_text" placeholder="متن راهنما" maxlength="50" />
        <input
          v-if="CHOICE_TYPES.includes(newField.type)"
          v-model="newField.options"
          placeholder="گزینه‌ها با کاما جدا شوند"
        />
        <label class="checkbox"><input v-model="newField.is_required" type="checkbox" /> اجباری</label>
        <button type="submit" class="primary" :disabled="addingField">{{ addingField ? 'در حال افزودن...' : 'افزودن' }}</button>
      </form>
      <p v-if="fieldError" class="alert alert-error">{{ fieldError }}</p>
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
label.align-end {
  align-self: end;
}
.order-cell {
  display: flex;
  gap: 4px;
}
.actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
}
.field-form {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  padding-top: 4px;
}
.field-form input, .field-form select {
  flex: 1 1 150px;
}
.edit-row {
  background: var(--color-primary-light);
}
.media-preview {
  max-width: 100%;
  max-height: 240px;
  border-radius: var(--radius-md);
  object-fit: cover;
}
</style>
