import { Building2, ImagePlus } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { useAuth } from '../context/auth-context'
import { useSettings } from '../context/settings-context'
import { updateSettings } from '../api/settings.api'
import { hasRole } from '../lib/roles'

const CURRENCY_OPTIONS = ['MAD', 'EUR', 'USD', 'GBP', 'CAD']

const emptyForm = {
  association_name: '',
  description: '',
  address: '',
  phone: '',
  email: '',
  website: '',
  annual_subscription_amount: '',
  currency: 'MAD',
}

function Field({ label, children }) {
  return (
    <label className="block">
      <span className="mb-1.5 block text-sm font-semibold text-slate-700">{label}</span>
      {children}
    </label>
  )
}

const inputClass =
  'h-11 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm text-slate-800 outline-none transition focus:border-blue-400'

export default function SettingsPage() {
  const { t } = useTranslation('settings')
  const { user } = useAuth()
  const { settings, loading: settingsLoading, refresh } = useSettings()
  const [form, setForm] = useState(emptyForm)
  const [logoFile, setLogoFile] = useState(null)
  const [logoPreview, setLogoPreview] = useState(null)
  const [saving, setSaving] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [successMessage, setSuccessMessage] = useState('')
  const fileInputRef = useRef(null)

  const isPresident = hasRole(user, 'president')

  useEffect(() => {
    if (settingsLoading) return

    setForm({
      association_name: settings.association_name ?? '',
      description: settings.description ?? '',
      address: settings.address ?? '',
      phone: settings.phone ?? '',
      email: settings.email ?? '',
      website: settings.website ?? '',
      annual_subscription_amount: settings.annual_subscription_amount ?? '',
      currency: settings.currency ?? 'MAD',
    })
  }, [settingsLoading, settings])

  if (!isPresident) {
    return <Navigate to="/dashboard" replace />
  }

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
  }

  function handleLogoChange(event) {
    const file = event.target.files?.[0]
    if (!file) return

    setLogoFile(file)
    setLogoPreview(URL.createObjectURL(file))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    setErrorMessage('')
    setSuccessMessage('')

    try {
      await updateSettings(form, logoFile)
      await refresh()
      setLogoFile(null)
      setLogoPreview(null)
      setSuccessMessage(t('saveSuccess'))
    } catch (error) {
      const validation = error.response?.data?.errors
      const firstValidationMessage = validation ? Object.values(validation)[0]?.[0] : null

      setErrorMessage(
        firstValidationMessage ?? error.response?.data?.message ?? t('saveError'),
      )
    } finally {
      setSaving(false)
    }
  }

  const displayedLogo = logoPreview ?? settings.logo_url

  return (
    <PresidentLayout
      title={t('title')}
      description={t('description')}
    >
      <form onSubmit={handleSubmit} className="space-y-7">
        {errorMessage ? (
          <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
            {errorMessage}
          </div>
        ) : null}

        {successMessage ? (
          <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
            {successMessage}
          </div>
        ) : null}

        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{t('associationInfo.title')}</h2>
          <p className="mt-1 text-sm text-slate-500">{t('associationInfo.subtitle')}</p>

          <div className="mt-6 grid grid-cols-1 gap-6 md:grid-cols-[160px_minmax(0,1fr)]">
            <div className="flex flex-col items-center gap-3">
              <div className="flex h-28 w-28 items-center justify-center overflow-hidden rounded-2xl border border-[#dfe5ff] bg-[#f7f9ff]">
                {displayedLogo ? (
                  <img src={displayedLogo} alt={t('associationInfo.logoAlt')} className="h-full w-full object-contain" />
                ) : (
                  <Building2 size={36} className="text-blue-300" />
                )}
              </div>
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="inline-flex items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition duration-150 hover:bg-slate-50"
              >
                <ImagePlus size={14} />
                {displayedLogo ? t('associationInfo.replaceLogo') : t('associationInfo.uploadLogo')}
              </button>
              <input
                ref={fileInputRef}
                type="file"
                accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml"
                onChange={handleLogoChange}
                className="hidden"
              />
              <p className="text-center text-[11px] text-slate-400">{t('associationInfo.fileTypes')}</p>
            </div>

            <div className="space-y-4">
              <Field label={t('associationInfo.associationName')}>
                <input
                  type="text"
                  name="association_name"
                  value={form.association_name}
                  onChange={handleChange}
                  required
                  className={inputClass}
                />
              </Field>

              <Field label={t('associationInfo.descriptionLabel')}>
                <textarea
                  name="description"
                  value={form.description}
                  onChange={handleChange}
                  rows={3}
                  className="w-full rounded-xl border border-[#dfe5ff] bg-white px-3 py-2 text-sm text-slate-800 outline-none transition focus:border-blue-400"
                />
              </Field>
            </div>
          </div>
        </section>

        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{t('contactInfo.title')}</h2>
          <p className="mt-1 text-sm text-slate-500">{t('contactInfo.subtitle')}</p>

          <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label={t('contactInfo.address')}>
              <input type="text" name="address" value={form.address} onChange={handleChange} required className={inputClass} />
            </Field>
            <Field label={t('contactInfo.phone')}>
              <input type="text" name="phone" value={form.phone} onChange={handleChange} required className={inputClass} />
            </Field>
            <Field label={t('contactInfo.email')}>
              <input type="email" name="email" value={form.email} onChange={handleChange} required className={inputClass} />
            </Field>
            <Field label={t('contactInfo.website')}>
              <input
                type="text"
                name="website"
                value={form.website}
                onChange={handleChange}
                placeholder="https://"
                className={inputClass}
              />
            </Field>
          </div>
        </section>

        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-sm">
          <h2 className="text-lg font-semibold text-slate-900">{t('membership.title')}</h2>
          <p className="mt-1 text-sm text-slate-500">{t('membership.subtitle')}</p>

          <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            <Field label={t('membership.annualSubscriptionAmount')}>
              <input
                type="number"
                name="annual_subscription_amount"
                value={form.annual_subscription_amount}
                onChange={handleChange}
                min="0"
                step="0.01"
                required
                className={inputClass}
              />
            </Field>
            <Field label={t('membership.currency')}>
              <select name="currency" value={form.currency} onChange={handleChange} className={inputClass}>
                {CURRENCY_OPTIONS.map((code) => (
                  <option key={code} value={code}>
                    {code}
                  </option>
                ))}
              </select>
            </Field>
          </div>
        </section>

        <div className="flex justify-end">
          <button
            type="submit"
            disabled={saving || settingsLoading}
            className="rounded-xl bg-blue-600 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-200 transition duration-150 hover:bg-blue-700 active:scale-[0.97] disabled:opacity-60"
          >
            {saving ? t('saving') : t('saveSettings')}
          </button>
        </div>
      </form>
    </PresidentLayout>
  )
}
