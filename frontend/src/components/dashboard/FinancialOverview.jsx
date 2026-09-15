import RevenueChart from './RevenueChart'
import ActionCenter from './ActionCenter'

export default function FinancialOverview({ monthly }) {
  return (
    <section className="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <RevenueChart monthly={monthly} />
      <ActionCenter />
    </section>
  )
}
