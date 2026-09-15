import { Download, ExternalLink, Minus, Plus, RotateCcw } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png']

function getExtension(path) {
  if (!path) return null

  const segments = path.split('.')
  return segments.length > 1 ? segments.pop().toLowerCase() : null
}

// Shown to the right of the expense list. Renders whatever invoice file is
// attached to the selected expense — reuses the invoice_url the API already
// returns, no new backend endpoint involved.
export default function ExpenseInvoicePreview({ expense }) {
  const { t } = useTranslation('finance')
  const invoiceUrl = expense?.invoice_url
  const extension = getExtension(expense?.invoice_file)
  const isPdf = extension === 'pdf'
  const isImage = IMAGE_EXTENSIONS.includes(extension)

  const [loadState, setLoadState] = useState('loading')
  const [zoom, setZoom] = useState(1)

  useEffect(() => {
    setZoom(1)

    if (!invoiceUrl) {
      setLoadState('none')
    } else if (!isPdf && !isImage) {
      setLoadState('unsupported')
    } else {
      setLoadState('loading')
    }
  }, [invoiceUrl, isPdf, isImage])

  if (!expense) {
    return (
      <div className="flex h-full min-h-[280px] items-center justify-center p-8 text-sm text-slate-400">
        {t('expensesPage.invoicePreview.selectPrompt')}
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#eef2ff] px-4 py-3">
        <p className="min-w-0 truncate text-sm font-semibold text-slate-700">
          {expense.supplier_name || t('expensesPage.unknownSupplier')}
          {expense.invoice_number ? ` • ${t('expensesPage.invoicePreview.invoiceNumber', { number: expense.invoice_number })}` : ''}
        </p>

        {invoiceUrl ? (
          <div className="flex shrink-0 items-center gap-2">
            {isImage ? (
              <div className="flex items-center gap-1 rounded-full border border-[#dfe5ff] px-1 py-1">
                <button
                  type="button"
                  onClick={() => setZoom((current) => Math.max(0.5, Number((current - 0.25).toFixed(2))))}
                  className="rounded-full p-1.5 text-slate-500 transition hover:bg-slate-100"
                  aria-label={t('expensesPage.invoicePreview.zoomOut')}
                >
                  <Minus size={14} />
                </button>
                <button
                  type="button"
                  onClick={() => setZoom(1)}
                  className="rounded-full p-1.5 text-slate-500 transition hover:bg-slate-100"
                  aria-label={t('expensesPage.invoicePreview.resetZoom')}
                >
                  <RotateCcw size={14} />
                </button>
                <button
                  type="button"
                  onClick={() => setZoom((current) => Math.min(3, Number((current + 0.25).toFixed(2))))}
                  className="rounded-full p-1.5 text-slate-500 transition hover:bg-slate-100"
                  aria-label={t('expensesPage.invoicePreview.zoomIn')}
                >
                  <Plus size={14} />
                </button>
              </div>
            ) : null}

            <a
              href={invoiceUrl}
              target="_blank"
              rel="noreferrer"
              className="inline-flex items-center gap-1 rounded-full border border-[#dfe5ff] px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
            >
              <ExternalLink size={14} />
              {t('expensesPage.invoicePreview.open')}
            </a>
            <a
              href={invoiceUrl}
              download
              className="inline-flex items-center gap-1 rounded-full border border-[#dfe5ff] px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
            >
              <Download size={14} />
              {t('expensesPage.invoicePreview.download')}
            </a>
          </div>
        ) : null}
      </div>

      <div className="relative flex-1 overflow-auto bg-[#f7f9ff] p-4">
        {loadState === 'none' ? (
          <div className="flex h-full min-h-[420px] items-center justify-center text-sm text-slate-400">
            {t('expensesPage.invoicePreview.noInvoice')}
          </div>
        ) : null}

        {loadState === 'unsupported' ? (
          <div className="flex h-full min-h-[420px] items-center justify-center text-center text-sm text-slate-400">
            {t('expensesPage.invoicePreview.unsupported')}
          </div>
        ) : null}

        {loadState === 'loading' ? (
          <div className="absolute inset-0 z-10 flex items-center justify-center text-sm text-slate-400">
            {t('expensesPage.invoicePreview.loading')}
          </div>
        ) : null}

        {loadState === 'error' ? (
          <div className="absolute inset-0 z-10 flex items-center justify-center text-sm text-rose-500">
            {t('expensesPage.invoicePreview.loadError')}
          </div>
        ) : null}

        {isPdf ? (
          <iframe
            key={invoiceUrl}
            src={invoiceUrl}
            title={t('expensesPage.invoicePreview.previewTitle')}
            className={`h-full min-h-[420px] w-full rounded-2xl border border-[#dfe5ff] bg-white ${
              loadState === 'loaded' ? '' : 'invisible'
            }`}
            onLoad={() => setLoadState('loaded')}
            onError={() => setLoadState('error')}
          />
        ) : null}

        {isImage ? (
          <div className="flex min-h-[420px] items-center justify-center overflow-auto">
            <img
              key={invoiceUrl}
              src={invoiceUrl}
              alt={t('expensesPage.invoicePreview.previewTitle')}
              style={{ transform: `scale(${zoom})` }}
              className={`max-w-full rounded-2xl border border-[#dfe5ff] bg-white object-contain transition-transform ${
                loadState === 'loaded' ? '' : 'invisible'
              }`}
              onLoad={() => setLoadState('loaded')}
              onError={() => setLoadState('error')}
            />
          </div>
        ) : null}
      </div>
    </div>
  )
}
