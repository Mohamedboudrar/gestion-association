<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('pdf.members.title') }}</title>
    @include('reports.partials.pdf-styles')
</head>
<body>

@include('reports.partials.pdf-helpers')

@php($reportTitle = __('pdf.members.title'))
@include('reports.partials.pdf-header')
@include('reports.partials.pdf-footer')

@include('reports.partials.pdf-meta')

@include('reports.partials.pdf-summary-cards', [
    'cards' => [
        ['label' => __('pdf.members.total_members'), 'value' => $members->count()],
    ],
])

<div class="section-title">{{ __('pdf.members.directory_title') }}</div>
<table class="data-table">
    <thead>
        <tr>
            <th>{{ __('pdf.members.table.name') }}</th>
            <th>{{ __('pdf.members.table.email') }}</th>
            <th>{{ __('pdf.members.table.phone') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($members as $member)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td>{{ $member->user->name }}</td>
                <td>{{ $member->user->email }}</td>
                <td>{{ $member->phone ?: '—' }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="3">{{ __('pdf.members.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
