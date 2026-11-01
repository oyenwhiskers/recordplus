import Swal from 'sweetalert2'
import { i18n } from '@/i18n'

const base = {
  buttonsStyling: false,
  reverseButtons: true,
  focusCancel: true,
  customClass: {
    popup: 'rp-swal-popup',
    title: 'rp-swal-title',
    htmlContainer: 'rp-swal-html',
    actions: 'rp-swal-actions',
    confirmButton: 'rp-swal-confirm',
    cancelButton: 'rp-swal-cancel',
    icon: 'rp-swal-icon',
  },
}

function tt(key: string, params?: Record<string, unknown>): string {
  return i18n.global.t(key, params as any) as string
}

export async function confirmAction(options: {
  title: string
  text: string
  confirmText?: string
  /** Use danger styling for destructive actions */
  danger?: boolean
}): Promise<boolean> {
  const result = await Swal.fire({
    ...base,
    icon: 'warning',
    title: options.title,
    text: options.text,
    showCancelButton: true,
    confirmButtonText: options.confirmText ?? tt('action_delete'),
    cancelButtonText: tt('action_cancel'),
    customClass: {
      ...base.customClass,
      confirmButton: options.danger === false ? 'rp-swal-confirm' : 'rp-swal-confirm rp-swal-confirm-danger',
    },
  })

  return result.isConfirmed
}

export async function showInfo(options: {
  title: string
  html: string
}): Promise<void> {
  await Swal.fire({
    ...base,
    icon: undefined,
    title: options.title,
    html: options.html,
    showCancelButton: false,
    confirmButtonText: tt('action_close'),
    customClass: {
      ...base.customClass,
      confirmButton: 'rp-swal-confirm',
    },
  })
}

export async function showSuccess(options: {
  title: string
  text?: string
}): Promise<void> {
  await Swal.fire({
    ...base,
    icon: 'success',
    title: options.title,
    text: options.text,
    showCancelButton: false,
    confirmButtonText: tt('action_ok'),
    customClass: {
      ...base.customClass,
      confirmButton: 'rp-swal-confirm',
      icon: 'rp-swal-icon rp-swal-icon-success',
    },
  })
}

export async function showError(options: {
  title: string
  text?: string
  icon?: 'error' | 'warning'
}): Promise<void> {
  const icon = options.icon ?? 'error'
  await Swal.fire({
    ...base,
    icon,
    title: options.title,
    text: options.text,
    showCancelButton: false,
    confirmButtonText: tt('action_ok'),
    customClass: {
      ...base.customClass,
      confirmButton: 'rp-swal-confirm',
      icon: icon === 'warning' ? 'rp-swal-icon' : 'rp-swal-icon rp-swal-icon-error',
    },
  })
}

export function notify(options: {
  title: string
  icon?: 'success' | 'warning' | 'error' | 'info'
}): void {
  const icon = options.icon ?? 'success'
  if (icon === 'success') {
    void showSuccess({ title: options.title })
    return
  }

  void Swal.fire({
    toast: true,
    position: 'bottom-end',
    icon,
    title: options.title,
    showConfirmButton: false,
    timer: 2800,
    timerProgressBar: true,
    customClass: {
      popup: 'rp-swal-toast',
      title: 'rp-swal-toast-title',
      icon: 'rp-swal-toast-icon',
    },
  })
}

export async function promptMoveToProfile(options: {
  profiles: { id: number; name: string; categories?: { id: number; name: string }[] }[]
  currentProfileId: number
}): Promise<
  | { profileId: number; categoryId: number }
  | { profileId: number; newCategoryName: string }
  | null
> {
  const targets = options.profiles.filter((p) => p.id !== options.currentProfileId)
  if (targets.length === 0) {
    await Swal.fire({
      ...base,
      icon: 'info',
      title: tt('confirm_create_profile_first_title'),
      text: tt('confirm_create_profile_first_text'),
      confirmButtonText: tt('action_ok'),
    })
    return null
  }

  const profileResult = await Swal.fire({
    ...base,
    icon: undefined,
    title: tt('title_move_to_profile'),
    html: `
      <label class="rp-swal-field">
        <span>${escapeHtml(tt('label_target_profile'))}</span>
        <select id="rp-move-profile" class="rp-swal-input">
          ${targets
            .map((p) => `<option value="${p.id}">${escapeHtml(p.name)}</option>`)
            .join('')}
        </select>
      </label>
    `,
    showCancelButton: true,
    confirmButtonText: tt('action_next'),
    cancelButtonText: tt('action_cancel'),
    focusConfirm: false,
    preConfirm: () => {
      const select = document.getElementById('rp-move-profile') as HTMLSelectElement | null
      const profileId = select?.value ? Number(select.value) : NaN
      if (!Number.isFinite(profileId) || profileId <= 0) {
        Swal.showValidationMessage(tt('msg_choose_profile'))
        return false
      }
      return { profileId }
    },
  })

  if (!profileResult.isConfirmed || !profileResult.value) {
    return null
  }

  const profileId = (profileResult.value as { profileId: number }).profileId
  const target = targets.find((p) => p.id === profileId)
  if (!target) {
    return null
  }

  const categoryChoice = await promptCategoryChoice({
    categories: target.categories ?? [],
    allowNew: true,
    title: tt('title_choose_category_in_profile', { name: target.name }),
  })
  if (!categoryChoice) {
    return null
  }

  if ('categoryId' in categoryChoice) {
    return { profileId, categoryId: categoryChoice.categoryId }
  }

  return { profileId, newCategoryName: categoryChoice.newCategoryName }
}

export async function promptCategoryChoice(options: {
  categories: { id: number; name: string }[]
  selectedId?: number | ''
  allowNew?: boolean
  title?: string
}): Promise<{ categoryId: number } | { newCategoryName: string } | null> {
  const optionsHtml = options.categories
    .map(
      (cat) =>
        `<option value="${cat.id}" ${cat.id === options.selectedId ? 'selected' : ''}>${escapeHtml(cat.name)}</option>`,
    )
    .join('')

  const result = await Swal.fire({
    ...base,
    icon: undefined,
    title: options.title ?? tt('title_change_category'),
    html: `
      <label class="rp-swal-field">
        <span>${escapeHtml(tt('label_existing_category'))}</span>
        <select id="rp-category-select" class="rp-swal-input">
          <option value="">${escapeHtml(tt('label_select_ellipsis'))}</option>
          ${optionsHtml}
        </select>
      </label>
      ${
        options.allowNew !== false
          ? `<label class="rp-swal-field">
              <span>${escapeHtml(tt('label_or_new_category'))}</span>
              <input id="rp-category-new" class="rp-swal-input" type="text" maxlength="100" placeholder="${escapeHtml(tt('placeholder_optional'))}" />
            </label>`
          : ''
      }
    `,
    showCancelButton: true,
    confirmButtonText: tt('action_use_category'),
    cancelButtonText: tt('action_cancel'),
    focusConfirm: false,
    preConfirm: () => {
      const select = document.getElementById('rp-category-select') as HTMLSelectElement | null
      const input = document.getElementById('rp-category-new') as HTMLInputElement | null
      const categoryId = select?.value ? Number(select.value) : NaN
      const newCategoryName = input?.value.trim() ?? ''

      if (Number.isFinite(categoryId) && categoryId > 0) {
        return { categoryId }
      }
      if (newCategoryName) {
        return { newCategoryName }
      }

      Swal.showValidationMessage(tt('msg_choose_category_or_new'))
      return false
    },
  })

  if (!result.isConfirmed || !result.value) {
    return null
  }

  return result.value as { categoryId: number } | { newCategoryName: string }
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}
