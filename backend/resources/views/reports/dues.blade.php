<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('pdf.dues.title') }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>

@include('reports.partials.pdf-helpers')

@php
    $reportTitle = __('pdf.dues.title');
    $statusBreakdown = $dues->groupBy('status')->map->count()->sortDesc();
    $statusColors = ['paid' => '#0F5C57', 'overdue' => '#B91C1C', 'partial' => '#B45309', 'pending' => '#B45309', 'waived' => '#64748B'];
@endphp

@include('reports.partials.pdf-header')
@include('reports.partials.pdf-footer')

@include('reports.partials.pdf-meta')

@include('reports.partials.pdf-summary-cards', [
    'cards' => [
        ['label' => __('pdf.dues.expected'), 'value' => report_currency($expected, $settings->currency)],
        ['label' => __('pdf.dues.collected'), 'value' => report_currency($collected, $settings->currency)],
        ['label' => __('pdf.dues.outstanding'), 'value' => report_currency($outstanding, $settings->currency), 'tone' => $outstanding > 0 ? 'warning' : null],
        ['label' => __('pdf.dues.collection_rate'), 'value' => number_format($collectionRate, 1).'%'],
    ],
])

@if($statusBreakdown->isNotEmpty())
    @include('reports.partials.pdf-bar-chart', [
        'chartTitle' => __('pdf.dues.chart_title'),
        'bars' => $statusBreakdown->map(fn ($count, $status) => [
            'label' => report_status_label($status),
            'value' => $count,
            'display' => $count.' ('.round(($count / $dues->count()) * 100).'%)',
            'color' => $statusColors[$status] ?? '#0F5C57',
        ])->values()->all(),
    ])
@endif

<div class="section-title">{{ __('pdf.dues.detail_title') }}</div>
<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('pdf.dues.table.member') }}</th>
            <th>{{ __('pdf.dues.table.email') }}</th>
            <th>{{ __('pdf.dues.table.year') }}</th>
            <th>{{ __('pdf.dues.table.amount_due') }}</th>
            <th>{{ __('pdf.dues.table.amount_paid') }}</th>
            <th>{{ __('pdf.dues.table.balance') }}</th>
            <th>{{ __('pdf.dues.table.status') }}</th>
            <th>{{ __('pdf.dues.table.due_date') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($dues as $due)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td>{{ $due->member->user->name }}</td>
                <td>{{ $due->member->user->email }}</td>
                <td>{{ $due->year }}</td>
                <td class="numeric">{{ report_currency($due->amount_due, $settings->currency) }}</td>
                <td class="numeric">{{ report_currency($due->amount_paid, $settings->currency) }}</td>
                <td class="numeric">{{ report_currency($due->balance, $settings->currency) }}</td>
                <td><span class="badge {{ report_status_class($due->status) }}">{{ report_status_label($due->status) }}</span></td>
                <td>{{ report_date($due->due_date) }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="8">{{ __('pdf.dues.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
