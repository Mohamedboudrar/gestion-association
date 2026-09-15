<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('pdf.subscriptions.title') }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>

@include('reports.partials.pdf-helpers')

@php
    $reportTitle = __('pdf.subscriptions.title');
    $totalAmount = $subscriptions->sum('amount');
    $verifiedCount = $subscriptions->where('status', 'verified')->count();
    $pendingCount = $subscriptions->where('status', 'pending')->count();
    $statusBreakdown = $subscriptions->groupBy('status')->map->count()->sortDesc();
    $statusColors = ['verified' => '#0F5C57', 'pending' => '#B45309', 'rejected' => '#B91C1C'];
@endphp

@include('reports.partials.pdf-header')
@include('reports.partials.pdf-footer')

@include('reports.partials.pdf-meta')

@include('reports.partials.pdf-summary-cards', [
    'cards' => [
        ['label' => __('pdf.subscriptions.total_subscriptions'), 'value' => $subscriptions->count()],
        ['label' => __('pdf.subscriptions.total_amount'), 'value' => report_currency($totalAmount, $settings->currency)],
        ['label' => __('pdf.subscriptions.verified'), 'value' => $verifiedCount],
        ['label' => __('pdf.subscriptions.pending'), 'value' => $pendingCount, 'tone' => $pendingCount > 0 ? 'warning' : null],
    ],
])

@if($statusBreakdown->isNotEmpty())
    @include('reports.partials.pdf-bar-chart', [
        'chartTitle' => __('pdf.subscriptions.chart_title'),
        'bars' => $statusBreakdown->map(fn ($count, $status) => [
            'label' => report_status_label($status),
            'value' => $count,
            'display' => $count.' ('.round(($count / $subscriptions->count()) * 100).'%)',
            'color' => $statusColors[$status] ?? '#0F5C57',
        ])->values()->all(),
    ])
@endif

<div class="section-title">{{ __('pdf.subscriptions.detail_title') }}</div>
<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('pdf.subscriptions.table.member') }}</th>
            <th>{{ __('pdf.subscriptions.table.email') }}</th>
            <th>{{ __('pdf.subscriptions.table.amount') }}</th>
            <th>{{ __('pdf.subscriptions.table.status') }}</th>
            <th>{{ __('pdf.subscriptions.table.payment_date') }}</th>
            <th>{{ __('pdf.subscriptions.table.expiration') }}</th>
            <th>{{ __('pdf.subscriptions.table.verified_by') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($subscriptions as $subscription)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td>{{ $subscription->member->user->name }}</td>
                <td>{{ $subscription->member->user->email }}</td>
                <td class="numeric">{{ report_currency($subscription->amount, $settings->currency) }}</td>
                <td><span class="badge {{ report_status_class($subscription->status) }}">{{ report_status_label($subscription->status) }}</span></td>
                <td>{{ report_date($subscription->payment_date) }}</td>
                <td>{{ report_date($subscription->expires_at) }}</td>
                <td>{{ optional($subscription->verifier)->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="7">{{ __('pdf.subscriptions.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
