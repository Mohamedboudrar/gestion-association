import { useMemo, useState } from 'react'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import heroImg from '../assets/hero.png'
import { useAuth } from '../context/auth-context'
import { useSettings } from '../context/settings-context'

const initialErrors = {
  email: '',
  password: '',
  form: '',
}

function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { t } = useTranslation(['common', 'validation'])
  const { isAuthenticated, isBootstrapping, login } = useAuth()
  const { settings } = useSettings()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const [errors, setErrors] = useState(initialErrors)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const redirectTo = useMemo(
    () => location.state?.from?.pathname ?? '/dashboard',
    [location.state],
  )

  if (!isBootstrapping && isAuthenticated) {
    return <Navigate to={redirectTo} replace />
  }

  function validateForm() {
    const nextErrors = { ...initialErrors }

    if (!email.trim()) {
      nextErrors.email = t('validation:auth.emailRequired')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      nextErrors.email = t('validation:auth.emailInvalid')
    }

    if (!password) {
      nextErrors.password = t('validation:auth.passwordRequired')
    }

    setErrors(nextErrors)
    return !nextErrors.email && !nextErrors.password
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (!validateForm()) {
      return
    }

    setIsSubmitting(true)
    setErrors(initialErrors)

    try {
      await login({ email, password }, remember)
      navigate(redirectTo, { replace: true })
    } catch (error) {
      const message =
        error.response?.data?.message ?? t('validation:auth.loginFailed')

      setErrors((current) => ({
        ...current,
        form: message,
      }))
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <main className="login-page">
      <section className="brand-panel">
        <div className="brand-copy">
          <div className="brand-mark" aria-hidden="true">
            {settings.logo_url ? (
              <img src={settings.logo_url} alt="" style={{ height: '100%', width: '100%', objectFit: 'cover', borderRadius: 'inherit' }} />
            ) : (
              settings.association_name?.[0]?.toUpperCase() ?? 'A'
            )}
          </div>
          <span className="brand-label">{settings.association_name}</span>
          <h1>{t('common:auth.tagline')}</h1>
          <p>{t('common:auth.taglineDescription')}</p>
        </div>
        <div className="brand-illustration">
          <img src={heroImg} alt={t('common:auth.heroAlt')} />
        </div>
      </section>

      <section className="login-panel">
        <div className="login-card">
          <div className="login-header">
            <h2>{t('common:auth.welcome')}</h2>
            <p>{t('common:auth.signInPrompt')}</p>
          </div>

          {errors.form ? (
            <div className="alert-error" role="alert">
              {errors.form}
            </div>
          ) : null}

          <form className="login-form" onSubmit={handleSubmit} noValidate>
            <label className="field">
              <span>{t('common:auth.email')}</span>
              <input
                type="email"
                name="email"
                placeholder={t('common:auth.emailPlaceholder')}
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                aria-invalid={Boolean(errors.email)}
                aria-describedby={errors.email ? 'email-error' : undefined}
              />
              {errors.email ? (
                <small id="email-error" className="field-error">
                  {errors.email}
                </small>
              ) : null}
            </label>

            <label className="field">
              <span>{t('common:auth.password')}</span>
              <div className="password-field">
                <input
                  type={showPassword ? 'text' : 'password'}
                  name="password"
                  placeholder="•••••••••••"
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  aria-invalid={Boolean(errors.password)}
                  aria-describedby={errors.password ? 'password-error' : undefined}
                />
                <button
                  type="button"
                  className="password-toggle"
                  onClick={() => setShowPassword((current) => !current)}
                  aria-label={showPassword ? t('common:auth.hidePassword') : t('common:auth.showPassword')}
                >
                  {showPassword ? t('common:auth.hide') : t('common:auth.show')}
                </button>
              </div>
              {errors.password ? (
                <small id="password-error" className="field-error">
                  {errors.password}
                </small>
              ) : null}
            </label>

            <label className="checkbox-field">
              <input
                type="checkbox"
                checked={remember}
                onChange={(event) => setRemember(event.target.checked)}
              />
              <span>{t('common:auth.rememberMe')}</span>
            </label>

            <button
              type="submit"
              className="primary-button"
              disabled={isSubmitting}
            >
              {isSubmitting ? (
                <span className="button-loading">
                  <span className="spinner spinner-inline" aria-hidden="true" />
                  {t('common:auth.signingIn')}
                </span>
              ) : (
                t('common:actions.login')
              )}
            </button>
          </form>

          <button type="button" className="link-button">
            {t('common:auth.forgotPassword')}
          </button>
        </div>
      </section>
    </main>
  )
}

export default LoginPage
