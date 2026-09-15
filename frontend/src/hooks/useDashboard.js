import { useEffect, useState } from 'react'
import { getDashboard } from '../api/dashboard.api'

export default function useDashboard(enabled = true) {
  const [dashboard, setDashboard] = useState(null)
  const [loading, setLoading] = useState(enabled)

  useEffect(() => {
    let cancelled = false

    if (!enabled) {
      setLoading(false)
      setDashboard(null)
      return undefined
    }

    setLoading(true)

    async function load() {
      try {
        const res = await getDashboard()

        if (!cancelled) {
          setDashboard(res)
        }
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    load()

    return () => {
      cancelled = true
    }
  }, [enabled])

  return {
    dashboard,
    loading,
  }
}
