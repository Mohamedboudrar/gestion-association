import '@tomtom-international/web-sdk-maps/dist/maps.css'
import * as tt from '@tomtom-international/web-sdk-maps'
import * as ttServices from '@tomtom-international/web-sdk-services'
import { MapPin, Search, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

const TOMTOM_API_KEY = import.meta.env.VITE_TOMTOM_API_KEY
const DEFAULT_CENTER = [-7.5898, 33.5731] // Casablanca — just a sane default, not a stored value
const DEFAULT_ZOOM = 5
const SELECTED_ZOOM = 14

// Lets a committee/president user pick a project's location on a TomTom map:
// search by address/city/landmark, click the map, or drag the marker. Only
// ever reports coordinates up via onChange — it never talks to the backend
// itself, so it's equally usable from the create form or a location-edit flow.
export default function TomTomMapPicker({ latitude, longitude, onChange }) {
  const { t } = useTranslation('common')
  const containerRef = useRef(null)
  const mapRef = useRef(null)
  const markerRef = useRef(null)
  const [searchQuery, setSearchQuery] = useState('')
  const [searchResults, setSearchResults] = useState([])
  const [searching, setSearching] = useState(false)
  const [searchError, setSearchError] = useState('')

  useEffect(() => {
    if (!TOMTOM_API_KEY || !containerRef.current) return

    const hasInitialCoords = latitude != null && longitude != null
    const map = tt.map({
      key: TOMTOM_API_KEY,
      container: containerRef.current,
      center: hasInitialCoords ? [longitude, latitude] : DEFAULT_CENTER,
      zoom: hasInitialCoords ? SELECTED_ZOOM : DEFAULT_ZOOM,
    })

    mapRef.current = map

    if (hasInitialCoords) {
      placeMarker(map, longitude, latitude)
    }

    map.on('click', (event) => {
      placeMarker(map, event.lngLat.lng, event.lngLat.lat)
      reportChange(event.lngLat.lat, event.lngLat.lng)
    })

    return () => {
      markerRef.current?.remove()
      markerRef.current = null
      map.remove()
      mapRef.current = null
    }
    // Only initialize once — subsequent coordinate updates are driven by user
    // interaction (click/drag/search), not by re-running map setup.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function placeMarker(map, lng, lat) {
    if (!markerRef.current) {
      markerRef.current = new tt.Marker({ draggable: true })
        .setLngLat([lng, lat])
        .addTo(map)

      markerRef.current.on('dragend', () => {
        const lngLat = markerRef.current.getLngLat()
        reportChange(lngLat.lat, lngLat.lng)
      })
    } else {
      markerRef.current.setLngLat([lng, lat])
    }
  }

  function reportChange(lat, lng) {
    onChange?.(lat, lng)
  }

  function handleClear() {
    markerRef.current?.remove()
    markerRef.current = null
    onChange?.(null, null)
  }

  async function handleSearch(event) {
    event.preventDefault()

    if (!searchQuery.trim() || !TOMTOM_API_KEY) return

    setSearching(true)
    setSearchError('')

    try {
      const response = await ttServices.services.fuzzySearch({
        key: TOMTOM_API_KEY,
        query: searchQuery,
      })

      setSearchResults(response.results ?? [])

      if (!response.results?.length) {
        setSearchError(t('map.noResults'))
      }
    } catch (error) {
      setSearchError(error?.message ?? t('map.searchFailed'))
    } finally {
      setSearching(false)
    }
  }

  function handleSelectResult(result) {
    const { lat, lng } = result.position
    const map = mapRef.current

    if (map) {
      map.setCenter([lng, lat])
      map.setZoom(SELECTED_ZOOM)
      placeMarker(map, lng, lat)
    }

    reportChange(lat, lng)
    setSearchResults([])
    setSearchQuery(result.address?.freeformAddress ?? result.poi?.name ?? '')
  }

  if (!TOMTOM_API_KEY) {
    return (
      <div className="flex min-h-[220px] flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-[#dfe5ff] bg-[#f7f9ff] px-6 py-10 text-center">
        <MapPin className="text-slate-300" size={28} />
        <p className="text-sm font-semibold text-slate-600">{t('map.pickerUnavailableTitle')}</p>
        <p className="text-xs text-slate-400">
          {t('map.pickerUnavailableBody')}
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <form onSubmit={handleSearch} className="relative">
        <input
          type="text"
          value={searchQuery}
          onChange={(event) => setSearchQuery(event.target.value)}
          placeholder={t('map.searchPlaceholder')}
          className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 pr-11 text-sm outline-none focus:border-blue-500"
        />
        <button
          type="submit"
          disabled={searching}
          className="absolute right-2 top-1/2 flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 disabled:opacity-50"
        >
          <Search size={16} />
        </button>
      </form>

      {searchError ? <p className="text-xs font-medium text-rose-600">{searchError}</p> : null}

      {searchResults.length ? (
        <ul className="max-h-40 space-y-1 overflow-y-auto rounded-2xl border border-[#dfe5ff] bg-white p-2">
          {searchResults.map((result, index) => (
            <li key={`${result.id ?? index}`}>
              <button
                type="button"
                onClick={() => handleSelectResult(result)}
                className="w-full rounded-xl px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-blue-50"
              >
                {result.address?.freeformAddress ?? result.poi?.name ?? t('map.unnamedLocation')}
              </button>
            </li>
          ))}
        </ul>
      ) : null}

      <div ref={containerRef} className="h-64 w-full overflow-hidden rounded-2xl border border-[#dfe5ff]" />

      <div className="flex items-center justify-between gap-3 text-xs text-slate-500">
        <span>
          {latitude != null && longitude != null
            ? t('map.selected', { lat: Number(latitude).toFixed(6), lng: Number(longitude).toFixed(6) })
            : t('map.noLocationSelected')}
        </span>
        {latitude != null && longitude != null ? (
          <button
            type="button"
            onClick={handleClear}
            className="inline-flex items-center gap-1 rounded-full border border-[#dfe5ff] bg-white px-3 py-1 font-semibold text-slate-600 transition hover:bg-slate-50"
          >
            <X size={12} />
            {t('map.clear')}
          </button>
        ) : null}
      </div>
    </div>
  )
}
