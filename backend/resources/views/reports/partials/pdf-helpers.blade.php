{{-- Presentation-only formatting helpers shared by every PDF report view.
     No business logic: pure string/number formatting of data the controllers
     already fetched. --}}
@php
if (! function_exists('report_currency')) {
    function report_currency($amount, string $currency = 'MAD'): string
    {
        return number_format((float) $amount, 2, ',', ' ').' '.$currency;
    }
}

if (! function_exists('report_date')) {
    function report_date($date, string $format = 'd M Y'): string
    {
        if (! $date) {
            return '—';
        }

        $carbon = $date instanceof \Carbon\Carbon ? $date : \Carbon\Carbon::parse($date);

        return $carbon->format($format);
    }
}

if (! function_exists('report_status_class')) {
    function report_status_class(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'verified', 'approved', 'paid', 'active', 'completed' => 'badge-success',
            'pending', 'in_progress', 'partial' => 'badge-warning',
            'rejected', 'cancelled', 'expired', 'overdue' => 'badge-danger',
            default => 'badge-neutral',
        };
    }
}

if (! function_exists('report_status_label')) {
    function report_status_label(?string $status): string
    {
        if (! $status) {
            return '—';
        }

        $key = 'pdf.status.'.strtolower($status);

        return \Illuminate\Support\Facades\Lang::has($key) ? __($key) : ucfirst(str_replace('_', ' ', $status));
    }
}
@endphp
