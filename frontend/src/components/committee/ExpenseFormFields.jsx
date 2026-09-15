import { useTranslation } from 'react-i18next'

const NUMBERED_FIELDS = ['amount', 'payment_method', 'invoice_number', 'expense_date']

function fieldClass(hasError) {
  return `h-11 w-full rounded-2xl border bg-white px-4 text-sm outline-none ${
    hasError ? 'border-rose-400 focus:border-rose-500' : 'border-[#dfe5ff]'
  }`
}

function FieldError({ message }) {
  if (!message) return null
  return <p className="mt-1 text-xs font-medium text-rose-600">{message}</p>
}

// Shared expense field set — used by the normal create/edit form and by the
// bulk-import review panel (one draft bound in at a time), so both stay in sync.
export default function ExpenseFormFields({ values, onChange, errors = {} }) {
  const { t } = useTranslation('association')

  return (
    <div className="space-y-4">
      <div>
        <input
          type="text"
          name="supplier_name"
          value={values.supplier_name}
          onChange={onChange}
          placeholder={t('committeeProject.expenseFields.supplier_name')}
          className={fieldClass(errors.supplier_name)}
        />
        <FieldError message={errors.supplier_name} />
      </div>

      <div>
        <textarea
          name="description"
          value={values.description}
          onChange={onChange}
          placeholder={t('committeeProject.expenseFields.description')}
          className={`min-h-[72px] w-full rounded-2xl border bg-white px-4 py-3 text-sm outline-none ${
            errors.description ? 'border-rose-400 focus:border-rose-500' : 'border-[#dfe5ff]'
          }`}
        />
        <FieldError message={errors.description} />
      </div>

      {NUMBERED_FIELDS.map((field) => (
        <div key={field}>
          <input
            type={field === 'amount' ? 'number' : field.includes('date') ? 'date' : 'text'}
            name={field}
            value={values[field]}
            onChange={onChange}
            placeholder={t(`committeeProject.expenseFields.${field}`)}
            className={fieldClass(errors[field])}
          />
          <FieldError message={errors[field]} />
        </div>
      ))}

      <div>
        <textarea
          name="notes"
          value={values.notes}
          onChange={onChange}
          placeholder={t('committeeProject.expenseFields.notes')}
          className={`min-h-[96px] w-full rounded-2xl border bg-white px-4 py-3 text-sm outline-none ${
            errors.notes ? 'border-rose-400 focus:border-rose-500' : 'border-[#dfe5ff]'
          }`}
        />
        <FieldError message={errors.notes} />
      </div>
    </div>
  )
}
