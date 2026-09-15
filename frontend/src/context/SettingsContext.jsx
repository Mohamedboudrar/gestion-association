import { useEffect, useMemo, useState } from 'react'
import { getSettings } from '../api/settings.api'
import { setActiveCurrency } from '../components/committee/committeeUtils'
import { SettingsContext } from './settings-context'

const FALLBACK_SETTINGS = {
  association_name: 'Association',
  logo_url: null,
  description: null,
  address: '',
  phone: '',
  email: '',
  website: null,
  annual_subscription_amount: 0,
  currency: 'MAD',
}

export function SettingsProvider({ children }) {
  const [settings, setSettings] = useState(FALLBACK_SETTINGS)
  const [loading, setLoading] = useState(true)

  async function refresh() {
    try {
      const data = await getSettings()
      setSettings(data)
      setActiveCurrency(data.currency)
    } catch {
      // Keep the current (or fallback) settings — every consumer already
      // tolerates the fallback shape, so a failed fetch just means the app
      // keeps showing generic branding instead of breaking.
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    refresh()
  }, [])

  useEffect(() => {
    document.title = settings.association_name
  }, [settings.association_name])

  const value = useMemo(() => ({ settings, loading, refresh }), [settings, loading])

  return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>
}
