import '@tomtom-international/web-sdk-maps/dist/maps.css'
import * as tt from '@tomtom-international/web-sdk-maps'
import { MapPin } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'

const TOMTOM_API_KEY = import.meta.env.VITE_TOMTOM_API_KEY
const VIEW_ZOOM = 14
const OVERVIEW_ZOOM = 5

function buildPopupContent(name, href, untitledProjectLabel, openProjectLabel) {
  const container = document.createElement('div')
  container.className = 'text-sm'

  const title = document.createElement('p')
  title.className = 'font-semibold text-slate-800'
  title.textContent = name || untitledProjectLabel
  container.appendChild(title)

  if (href) {
    const link = document.createElement('a')
    link.href = href
    link.textContent = openProjectLabel
    link.className = 'text-blue-600 font-semibold'
    container.appendChild(link)
  }

  return container
}

// Read-only map for displaying project location(s) — no search, no
// click-to-place, no dragging. Two modes:
//   - single: pass `latitude`/`longitude` (e.g. one project's saved spot) —
//     static, non-interactive, matches the original single-project viewer.
//   - multi: pass `markers` (array of `{id, latitude, longitude, name, href}`)
//     — e.g. every project with a location, on one map — interactive
//     (pan/zoom) with a popup per marker, and the view fits all of them.
// Renders a friendly placeholder instead of a crash if the API key isn't configured.
export default function TomTomMapViewer({ latitude, longitude, markers }) {
  const { t } = useTranslation('common')
  const containerRef = useRef(null)
  const isMultiMode = Array.isArray(markers)

  const points = isMultiMode
    ? markers.filter((marker) => marker.latitude != null && marker.longitude != null)
    : latitude != null && longitude != null
      ? [{ id: 'single', latitude, longitude }]
      : []

  useEffect(() => {
    if (!TOMTOM_API_KEY || !containerRef.current || points.length === 0) return

    const map = tt.map({
      key: TOMTOM_API_KEY,
      container: containerRef.current,
      center: [points[0].longitude, points[0].latitude],
      zoom: isMultiMode ? OVERVIEW_ZOOM : VIEW_ZOOM,
      interactive: isMultiMode,
    })

    const bounds = new tt.LngLatBounds()

    points.forEach((point) => {
      const marker = new tt.Marker()

      if (isMultiMode) {
        marker.setPopup(
          new tt.Popup({ offset: 24 }).setDOMContent(
            buildPopupContent(point.name, point.href, t('map.untitledProject'), t('map.openProject')),
          ),
        )
      }

      marker.setLngLat([point.longitude, point.latitude]).addTo(map)
      bounds.extend([point.longitude, point.latitude])
    })

    if (isMultiMode && points.length > 1) {
      map.fitBounds(bounds, { padding: 60, maxZoom: 15 })
    }

    return () => {
      map.remove()
    }
    // Re-init whenever the point set actually changes in content, not just by
    // reference — `markers`/`points` are rebuilt fresh on every render by callers.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isMultiMode, JSON.stringify(points)])

  if (!TOMTOM_API_KEY) {
    return (
      <div className="flex min-h-[180px] flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#dfe5ff] bg-[#f7f9ff] px-6 py-8 text-center">
        <MapPin className="text-slate-300" size={24} />
        <p className="text-xs text-slate-400">
          {t('map.previewUnavailable')}
        </p>
      </div>
    )
  }

  return <div ref={containerRef} className="h-56 w-full overflow-hidden rounded-2xl border border-[#dfe5ff]" />
}
