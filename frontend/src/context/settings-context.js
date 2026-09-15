import { createContext, useContext } from 'react'

export const SettingsContext = createContext(null)

export function useSettings() {
  const context = useContext(SettingsContext)

  if (!context) {
    throw new Error('useSettings must be used within SettingsProvider')
  }

  return context
}
