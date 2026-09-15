import { Building2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { requestPasskeyReset } from '../api/auth.api'
import { useAuth } from '../context/auth-context'
import { useSettings } from '../context/settings-context'

export default function MemberPortalLoginPage() {
  const { t } = useTranslation(['common', 'validation'])
  const navigate = useNavigate()
  const { loginWithPasskey } = useAuth()
  const { settings } = useSettings()
  const [passkey, setPasskey] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const [showForgot, setShowForgot] = useState(false)
  const [email, setEmail] = useState('')
  const [forgotMessage, setForgotMessage] = useState('')
  const [forgotSubmitting, setForgotSubmitting] = useState(false)

  function handlePasskeyChange(event) {
    const digitsOnly = event.target.value.replace(/\D/g, '').slice(0, 6)
    setPasskey(digitsOnly)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')

    if (passkey.length !== 6) {
      setError(t('validation:auth.passkeyRequired'))
      return
    }

    setSubmitting(true)

    try {
      // No "remember me" control on this form (unlike the bureau login) —
      // the old Member Portal always persisted to localStorage, so keep
      // that same always-remembered behavior here.
      await loginWithPasskey(passkey, true)
      navigate('/dashboard', { replace: true })
    } catch (submitError) {
      setError(
        submitError.response?.status === 429
          ? (submitError.response?.data?.message ?? t('validation:auth.tooManyAttempts'))
          : t('validation:auth.invalidPasskey'),
      )
    } finally {
      setSubmitting(false)
    }
  }

  async function handleForgotSubmit(event) {
    event.preventDefault()
    setForgotMessage('')
    setForgotSubmitting(true)

    try {
      const { message } = await requestPasskeyReset(email)
      setForgotMessage(message)
    } catch (submitError) {
      // Rate-limit responses are the one case worth surfacing distinctly —
      // everything else always returns the same generic success message,
      // by design, regardless of whether the email exists or is verified.
      setForgotMessage(
        submitError.response?.status === 429
          ? (submitError.response?.data?.message ?? t('validation:auth.tooManyRequests'))
          : t('validation:auth.passkeyResetGenericMessage'),
      )
    } finally {
      setForgotSubmitting(false)
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-[#f5f7ff] px-4">
      <div className="w-full max-w-sm rounded-3xl border border-[#dfe5ff] bg-white p-8 text-center shadow-[0_10px_24px_rgba(148,163,184,0.12)]">
        <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl bg-blue-600 text-white">
          {settings.logo_url ? (
            <img src={settings.logo_url} alt={settings.association_name} className="h-full w-full object-cover" />
          ) : (
            <Building2 size={26} />
          )}
        </div>
        <h1 className="text-xl font-semibold text-slate-900">
          {t('common:auth.memberPortalTitle', { association: settings.association_name })}
        </h1>
        <p className="mt-1 text-sm text-slate-500">{t('common:auth.memberPortalSubtitle')}</p>

        <form className="mt-6 space-y-4" onSubmit={handleSubmit}>
          <input
            type="text"
            inputMode="numeric"
            pattern="[0-9]*"
            autoComplete="one-time-code"
            maxLength={6}
            value={passkey}
            onChange={handlePasskeyChange}
            placeholder="••••••"
            aria-label={t('common:auth.passkeyAriaLabel')}
            className="h-14 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] text-center text-2xl font-semibold tracking-[0.5em] text-slate-900 outline-none focus:border-blue-500"
          />

          {error ? <p className="text-sm font-medium text-rose-600">{error}</p> : null}

          <button
            type="submit"
            disabled={submitting}
            className="w-full rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
          >
            {submitting ? t('common:auth.checking') : t('common:auth.continue')}
          </button>
        </form>

        <button
          type="button"
          onClick={() => {
            setShowForgot((current) => !current)
            setForgotMessage('')
          }}
          className="mt-4 text-sm font-semibold text-blue-600 hover:text-blue-700"
        >
          {t('common:auth.forgotPasskey')}
        </button>

        {showForgot ? (
          <form className="mt-4 space-y-3 border-t border-[#eef2ff] pt-4 text-left" onSubmit={handleForgotSubmit}>
            <label className="block text-sm text-slate-600">
              {t('common:auth.registeredEmail')}
              <input
                type="email"
                required
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                placeholder={t('common:auth.emailPlaceholder')}
                className="mt-1 h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none focus:border-blue-500"
              />
            </label>

            {forgotMessage ? <p className="text-sm text-slate-600">{forgotMessage}</p> : null}

            <button
              type="submit"
              disabled={forgotSubmitting}
              className="w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-60"
            >
              {forgotSubmitting ? t('common:auth.sending') : t('common:auth.sendNewPasskey')}
            </button>
          </form>
        ) : null}
      </div>
    </main>
  )
}
