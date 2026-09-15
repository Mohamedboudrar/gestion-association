import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { useTranslation } from 'react-i18next'
import { formatCurrency } from '../committee/committeeUtils'

const tooltipStyle = {
  borderRadius: '16px',
  border: '1px solid #dfe5ff',
  boxShadow: '0 12px 30px rgba(148, 163, 184, 0.18)',
}

export default function RevenueChart({ monthly }) {
  const { t } = useTranslation('dashboard')

  return (
    <div className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)] xl:col-span-2">
      <div className="mb-5 flex items-center justify-between">
        <div>
          <h2 className="text-lg font-semibold text-slate-900">{t('revenueChart.title')}</h2>
          <p className="mt-0.5 text-sm text-slate-500">{t('revenueChart.subtitle')}</p>
        </div>
      </div>

      <ResponsiveContainer width="100%" height={320}>
        <ComposedChart data={monthly}>
          <CartesianGrid stroke="#eef2ff" vertical={false} />
          <XAxis dataKey="month" axisLine={false} tickLine={false} tick={{ fill: '#94a3b8', fontSize: 12 }} />
          <YAxis hide />
          <Tooltip contentStyle={tooltipStyle} formatter={(value) => formatCurrency(value)} />
          <Legend verticalAlign="top" align="right" wrapperStyle={{ fontSize: '12px', paddingBottom: '12px' }} />
          <Bar dataKey="subscriptions" stackId="revenue" name={t('revenueChart.subscriptions')} fill="#4f7cff" radius={[0, 0, 0, 0]} />
          <Bar dataKey="donations" stackId="revenue" name={t('revenueChart.donations')} fill="#34d399" radius={[8, 8, 0, 0]} />
          <Bar dataKey="expenses" name={t('revenueChart.expenses')} fill="#f87171" radius={[8, 8, 0, 0]} />
        </ComposedChart>
      </ResponsiveContainer>
    </div>
  )
}
