import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import resourcesToBackend from 'i18next-resources-to-backend'

// French is the application's only language — no language switcher, no
// detection, no second locale. Translation strings still live in
// per-namespace JSON files (rather than being hardcoded in components) so
// the app stays consistent, maintainable, and easy to re-extend if a
// second language is ever needed.
export const LANGUAGE = 'fr'

// One namespace per feature area — kept small and topic-scoped so a page
// only pulls the translations it actually needs (see the lazy-loaded
// dynamic import below) instead of one giant bundle.
export const NAMESPACES = [
  'common',
  'dashboard',
  'association',
  'finance',
  'approvals',
  'reports',
  'notifications',
  'profile',
  'settings',
  'pdf',
  'validation',
]

i18n
  // Lazy-loads each namespace JSON file as its own chunk via Vite's dynamic
  // import/code-splitting, instead of bundling every namespace upfront.
  .use(
    resourcesToBackend((language, namespace, callback) => {
      import(`./locales/${language}/${namespace}.json`)
        .then((resource) => callback(null, resource.default))
        .catch((error) => callback(error, null))
    }),
  )
  .use(initReactI18next)
  .init({
    lng: LANGUAGE,
    fallbackLng: LANGUAGE,
    supportedLngs: [LANGUAGE],
    ns: NAMESPACES,
    defaultNS: 'common',
    interpolation: { escapeValue: false },
    react: {
      useSuspense: true,
    },
  })

export default i18n
