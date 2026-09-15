<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('pdf.projects.title') }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>

@include('reports.partials.pdf-helpers')

@php
    $reportTitle = __('pdf.projects.title');
    $totalBudget = $projects->sum('budget');
    $activeCount = $projects->where('status', 'active')->count();
    $completedCount = $projects->where('status', 'completed')->count();
    $statusBreakdown = $projects->groupBy('status')->map->count()->sortDesc();
    $statusColors = ['active' => '#0F5C57', 'completed' => '#2563EB', 'cancelled' => '#B91C1C', 'draft' => '#64748B'];
@endphp

@include('reports.partials.pdf-header')
@include('reports.partials.pdf-footer')

@include('reports.partials.pdf-meta')

@include('reports.partials.pdf-summary-cards', [
    'cards' => [
        ['label' => __('pdf.projects.total_projects'), 'value' => $projects->count()],
        ['label' => __('pdf.projects.active'), 'value' => $activeCount],
        ['label' => __('pdf.projects.completed'), 'value' => $completedCount],
        ['label' => __('pdf.projects.total_budget'), 'value' => report_currency($totalBudget, $settings->currency)],
    ],
])

@if($statusBreakdown->isNotEmpty())
    @include('reports.partials.pdf-bar-chart', [
        'chartTitle' => __('pdf.projects.chart_title'),
        'bars' => $statusBreakdown->map(fn ($count, $status) => [
            'label' => report_status_label($status),
            'value' => $count,
            'display' => $count.' ('.round(($count / $projects->count()) * 100).'%)',
            'color' => $statusColors[$status] ?? '#0F5C57',
        ])->values()->all(),
    ])
@endif

<div class="section-title">{{ __('pdf.projects.detail_title') }}</div>
<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('pdf.projects.table.name') }}</th>
            <th>{{ __('pdf.projects.table.manager') }}</th>
            <th>{{ __('pdf.projects.table.status') }}</th>
            <th>{{ __('pdf.projects.table.budget') }}</th>
            <th>{{ __('pdf.projects.table.start') }}</th>
            <th>{{ __('pdf.projects.table.end') }}</th>
            <th>{{ __('pdf.projects.table.members') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($projects as $project)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td>{{ $project->name }}</td>
                <td>{{ optional($project->manager)->name ?? '—' }}</td>
                <td><span class="badge {{ report_status_class($project->status) }}">{{ report_status_label($project->status) }}</span></td>
                <td class="numeric">{{ report_currency($project->budget, $settings->currency) }}</td>
                <td>{{ report_date($project->start_date) }}</td>
                <td>{{ report_date($project->end_date) }}</td>
                <td>{{ $project->members->count() }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="7">{{ __('pdf.projects.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
