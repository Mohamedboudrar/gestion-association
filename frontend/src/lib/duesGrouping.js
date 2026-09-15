// Pure presentation grouping: pairs each Due (the authoritative amount_due/
// amount_paid/balance/status already computed server-side by DuesService)
// with the subscription payments linked to it, for the subscriber's
// due-centric "Annual Dues" view. No business math is duplicated here —
// every number displayed comes straight from the Due/Subscription resources.
export function buildDueGroups(dues, subscriptions) {
  const paymentsByDueId = new Map()

  for (const subscription of subscriptions) {
    if (!subscription.due) continue

    const list = paymentsByDueId.get(subscription.due.id) ?? []
    list.push(subscription)
    paymentsByDueId.set(subscription.due.id, list)
  }

  return dues
    .map((due) => {
      const payments = (paymentsByDueId.get(due.id) ?? [])
        .slice()
        .sort((a, b) => (a.payment_date < b.payment_date ? -1 : a.payment_date > b.payment_date ? 1 : 0))

      return {
        due,
        payments,
        percentage: due.amount_due > 0 ? Math.min(100, Math.round((due.amount_paid / due.amount_due) * 100)) : 0,
      }
    })
    .sort((a, b) => b.due.year - a.due.year)
}
