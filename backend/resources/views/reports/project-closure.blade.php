<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{{ __('pdf.project_closure.title') }}</title>

    {{-- This view is intentionally self-contained (its own styles, header,
         footer, cards, chart) rather than reusing reports/partials/pdf-*,
         so this report's polish pass can't shift the look of the other 4
         report views that still share those partials. --}}
    <style>
        @page {
            margin: 96px 26px 42px 26px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5px;
            line-height: 1.4;
            color: #1F2937;
        }

        h1, h2 {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            color: #14213D;
        }

        /* ---------- Fixed, repeating page header ---------- */
        .pdf-header {
            position: fixed;
            top: -96px;
            left: 0;
            right: 0;
            height: 66px;
        }

        .pdf-header table {
            width: 100%;
            border-collapse: collapse;
        }

        .pdf-header td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }

        .pdf-header .logo-cell {
            width: 42px;
        }

        .pdf-header .logo-cell img {
            height: 34px;
            max-width: 38px;
        }

        .pdf-header .association-name {
            font-size: 12px;
            font-weight: bold;
            color: #14213D;
        }

        .pdf-header .association-contact {
            font-size: 7px;
            color: #64748B;
            margin-top: 1px;
        }

        .pdf-header .report-title-cell {
            text-align: right;
        }

        .pdf-header .report-title {
            font-size: 11px;
            font-weight: bold;
            color: #0F5C57;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .pdf-header .report-generated {
            font-size: 7px;
            color: #64748B;
            margin-top: 1px;
        }

        .pdf-header .header-rule {
            margin-top: 6px;
            border: none;
            border-top: 1.25px solid #0F5C57;
        }

        /* ---------- Fixed, repeating page footer ---------- */
        .pdf-footer {
            position: fixed;
            bottom: -42px;
            left: 0;
            right: 0;
            height: 26px;
            padding-top: 6px;
            border-top: 0.75px solid #E2E8F0;
            font-size: 7.5px;
            color: #94A3B8;
        }

        .pdf-footer table {
            width: 100%;
            border-collapse: collapse;
        }

        .pdf-footer td {
            border: none;
            padding: 0;
            vertical-align: middle;
        }

        .pdf-footer .footer-right {
            text-align: right;
        }

        /* dompdf has no CSS support for a total-page counter() — only the
           current page number auto-increments — so the footer shows a
           running page number rather than an "X / Y" pair that would
           silently render as "X / 0". */
        .pdf-footer .page-number:after {
            content: "{{ __('pdf.common.page_prefix') }}" counter(page);
        }

        /* ---------- Report metadata (page 1 only, in-flow) ---------- */
        .report-meta {
            margin-bottom: 10px;
        }

        .report-meta h1 {
            font-size: 23px;
            font-weight: bold;
        }

        .report-meta .report-subtitle {
            margin-top: 3px;
            font-size: 10px;
            color: #475569;
            font-weight: bold;
        }

        .report-meta .meta-line {
            margin-top: 4px;
            font-size: 7.5px;
            color: #94A3B8;
        }

        /* ---------- Section titles ---------- */
        .section-title {
            font-size: 13.5px;
            font-weight: bold;
            color: #14213D;
            margin: 14px 0 3px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #E2E8F0;
        }

        .section-note {
            font-size: 8px;
            color: #64748B;
            margin: 0 0 5px 0;
        }

        /* ---------- KPI cards ----------
           The icon is a plain colored circle (no glyph) centered against the
           value via inline-block + vertical-align: middle. This is deliberate:
           dompdf centers text glyphs by line-height, and different Unicode
           icon characters (◆ ▲ ● ▼ ↺) sit at different visual heights within
           their own em-box, so each card drifted by a different amount. A
           glyph-free dot removes that per-character variance entirely, so
           every card centers identically. Flexbox was tried first and
           rejected — dompdf's flex row laid children out stacked, not
           side-by-side. */
        table.kpi-cards {
            width: 100%;
            border-collapse: separate;
            border-spacing: 6px 0;
            margin-bottom: 4px;
        }

        table.kpi-cards td.kpi-cell {
            width: 20%;
            background: #F8FAFC;
            border: 0.75px solid #E2E8F0;
            border-radius: 4px;
            padding: 8px 5px;
            text-align: center;
            vertical-align: middle;
        }

        .kpi-label {
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #64748B;
            margin-bottom: 5px;
        }

        /* white-space: nowrap keeps the icon and value on one line at this
           card's actual width (~125px, 5 cards per row) — without it, an
           inline-block pair wraps onto two lines exactly like the text-glyph
           version did, just for a different reason (column width, not font
           metrics). */
        .kpi-metric {
            text-align: center;
            white-space: nowrap;
        }

        .kpi-icon {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 5px;
            vertical-align: middle;
            margin-right: 4px;
        }

        .kpi-value {
            display: inline-block;
            vertical-align: middle;
            font-size: 11.5px;
            font-weight: bold;
            color: #14213D;
            white-space: nowrap;
        }

        /* ---------- Financial breakdown chart ---------- */
        .chart-section {
            margin-bottom: 2px;
        }

        .chart-row {
            margin-bottom: 4px;
        }

        .chart-row table {
            width: 100%;
            border-collapse: collapse;
        }

        .chart-row td {
            border: none;
            padding: 0;
        }

        .chart-row .chart-label {
            font-size: 8.5px;
            color: #334155;
            font-weight: bold;
        }

        .chart-row .chart-value {
            font-size: 8.5px;
            color: #334155;
            font-weight: bold;
            text-align: right;
        }

        .chart-track {
            background: #EEF2F6;
            border-radius: 3px;
            height: 7px;
            margin-top: 1px;
        }

        .chart-fill {
            height: 7px;
            border-radius: 3px;
        }

        /* ---------- Data tables ---------- */
        table.data-table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin-bottom: 3px;
        }

        table.data-table thead {
            display: table-header-group;
        }

        table.data-table th {
            background: #14213D;
            color: #FFFFFF;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.25px;
            padding: 5px 8px;
            border: none;
        }

        table.data-table td {
            padding: 3.5px 8px;
            border: none;
            border-bottom: 0.5px solid #E9EDF2;
            font-size: 8.5px;
            vertical-align: middle;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        table.data-table tbody tr {
            page-break-inside: avoid;
        }

        table.data-table tbody tr.row-even {
            background: #F7F9FB;
        }

        table.data-table .text-left {
            text-align: left;
        }

        table.data-table .text-right {
            text-align: right;
        }

        table.data-table .text-center {
            text-align: center;
        }

        table.data-table td.amount {
            text-align: right;
            font-weight: bold;
            color: #14213D;
        }

        table.data-table td.empty-row {
            text-align: center;
            color: #94A3B8;
            padding: 12px;
        }

        /* ---------- Status badges ---------- */
        .badge {
            display: inline-block;
            min-width: 46px;
            padding: 2px 0;
            border-radius: 7px;
            font-size: 7px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.25px;
            text-align: center;
        }

        .badge-draft {
            background: #F1F5F9;
            color: #475569;
        }

        .badge-pending {
            background: #FEF3C7;
            color: #B45309;
        }

        .badge-approved {
            background: #D1FAE5;
            color: #047857;
        }

        .badge-paid {
            background: #CCFBF1;
            color: #0F766E;
        }

        .badge-rejected {
            background: #FEE2E2;
            color: #B91C1C;
        }
    </style>
</head>
<body>

@include('reports.partials.pdf-helpers')

@php
    // Local, view-scoped helpers (guarded, since Blade views render once
    // per project closure and can run multiple times in the same PHP
    // process across tests) — kept separate from reports/partials/pdf-helpers'
    // report_currency()/report_status_class() so this report's format/badge
    // choices don't change the other 4 report views that still use those.
    if (! function_exists('closure_currency')) {
        function closure_currency($amount, string $currency = 'MAD'): string
        {
            return number_format((float) $amount, 2, '.', ',').' '.$currency;
        }
    }

    if (! function_exists('closure_badge_class')) {
        function closure_badge_class(?string $status): string
        {
            return match (strtolower((string) $status)) {
                'approved' => 'badge-approved',
                'paid' => 'badge-paid',
                'rejected' => 'badge-rejected',
                'pending' => 'badge-pending',
                default => 'badge-draft',
            };
        }
    }

    $reportTitle = __('pdf.project_closure.title');
    $reportSubtitle = __('pdf.project_closure.subtitle', [
        'name' => $project->name,
        'start' => report_date($project->start_date),
        'end' => report_date($project->end_date),
    ]);

    $kpis = [
        ['label' => __('pdf.project_closure.kpi.budget'), 'value' => $summary['budget'], 'accent' => '#14213D'],
        ['label' => __('pdf.project_closure.kpi.donations'), 'value' => $summary['donations_total'], 'accent' => '#0F5C57'],
        ['label' => __('pdf.project_closure.kpi.allocations'), 'value' => $summary['allocations_total'], 'accent' => '#2563EB'],
        ['label' => __('pdf.project_closure.kpi.expenses'), 'value' => $summary['expenses_total'], 'accent' => '#B45309'],
        ['label' => __('pdf.project_closure.kpi.returned_to_pool'), 'value' => $summary['returned_to_pool'], 'accent' => '#475569'],
    ];

    $financialBars = [
        ['label' => __('pdf.project_closure.kpi.budget'), 'value' => $summary['budget'], 'color' => '#14213D'],
        ['label' => __('pdf.project_closure.kpi.donations'), 'value' => $summary['donations_total'], 'color' => '#0F5C57'],
        ['label' => __('pdf.project_closure.kpi.allocations'), 'value' => $summary['allocations_total'], 'color' => '#2563EB'],
        ['label' => __('pdf.project_closure.kpi.expenses'), 'value' => $summary['expenses_total'], 'color' => '#B45309'],
        ['label' => __('pdf.project_closure.kpi.returned_to_pool'), 'value' => $summary['returned_to_pool'], 'color' => '#64748B'],
    ];
    $maxBarValue = collect($financialBars)->max('value') ?: 1;
@endphp

{{-- Header --}}
<div class="pdf-header">
    <table>
        <tr>
            <td class="logo-cell">
                @if($settings->association_logo)
                    <img src="{{ public_path('storage/'.$settings->association_logo) }}">
                @endif
            </td>
            <td>
                <div class="association-name">{{ $settings->association_name }}</div>
                <div class="association-contact">
                    {{ $settings->address }}
                    @if($settings->phone) &middot; {{ $settings->phone }} @endif
                    @if($settings->email) &middot; {{ $settings->email }} @endif
                    @if($settings->website) &middot; {{ $settings->website }} @endif
                </div>
            </td>
            <td class="report-title-cell">
                <div class="report-title">{{ $reportTitle }}</div>
                <div class="report-generated">{{ __('pdf.common.generated', ['date' => report_date(now(), 'd M Y \a\t H:i')]) }}</div>
            </td>
        </tr>
    </table>
    <hr class="header-rule">
</div>

{{-- Footer --}}
<div class="pdf-footer">
    <table>
        <tr>
            <td>{{ $settings->association_name }} &middot; {{ __('pdf.common.confidential') }}</td>
            <td class="footer-right"><span class="page-number"></span></td>
        </tr>
    </table>
</div>

{{-- Report metadata --}}
<div class="report-meta">
    <h1>{{ $reportTitle }}</h1>
    <div class="report-subtitle">{{ $reportSubtitle }}</div>
    <div class="meta-line">
        {{ __('pdf.common.generated_by', [
            'date' => report_date(now(), 'd M Y'),
            'time' => now()->format('H:i'),
            'name' => auth()->user()->name ?? __('pdf.common.system'),
        ]) }}
    </div>
</div>

{{-- KPI cards: one reusable component, identical markup per card — only
     the accent color and figures change. --}}
<table class="kpi-cards">
    <tr>
        @foreach($kpis as $kpi)
            <td class="kpi-cell">
                <div class="kpi-label">{{ $kpi['label'] }}</div>
                <div class="kpi-metric">
                    <span class="kpi-icon" style="background: {{ $kpi['accent'] }};"></span>
                    <span class="kpi-value">{{ closure_currency($kpi['value'], $settings->currency) }}</span>
                </div>
            </td>
        @endforeach
    </tr>
</table>

{{-- Financial breakdown --}}
<div class="chart-section">
    <div class="section-title">{{ __('pdf.project_closure.financial_breakdown_title') }}</div>
    @foreach($financialBars as $bar)
        <div class="chart-row">
            <table>
                <tr>
                    <td class="chart-label">{{ $bar['label'] }}</td>
                    <td class="chart-value">{{ closure_currency($bar['value'], $settings->currency) }}</td>
                </tr>
            </table>
            <div class="chart-track">
                <div class="chart-fill" style="width: {{ max(2, round(($bar['value'] / $maxBarValue) * 100)) }}%; background: {{ $bar['color'] }};"></div>
            </div>
        </div>
    @endforeach
</div>

{{-- Donations --}}
<div class="section-title">{{ __('pdf.project_closure.donations_title', ['count' => $summary['donation_count']]) }}</div>
<div class="section-note">{{ __('pdf.project_closure.donations_note') }}</div>
<table class="data-table">
    <colgroup>
        <col style="width: 40%">
        <col style="width: 20%">
        <col style="width: 20%">
        <col style="width: 20%">
    </colgroup>
    <thead>
        <tr>
            <th class="text-left">{{ __('pdf.project_closure.donations_table.donor') }}</th>
            <th class="text-right">{{ __('pdf.project_closure.donations_table.amount') }}</th>
            <th class="text-center">{{ __('pdf.project_closure.donations_table.status') }}</th>
            <th class="text-center">{{ __('pdf.project_closure.donations_table.date') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($donations as $donation)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td class="text-left">{{ $donation->member?->user?->name ?? $donation->donor_name ?? __('pdf.common.unknown_donor') }}</td>
                <td class="amount">{{ closure_currency($donation->amount, $settings->currency) }}</td>
                <td class="text-center"><span class="badge {{ closure_badge_class($donation->status) }}">{{ report_status_label($donation->status) }}</span></td>
                <td class="text-center">{{ report_date($donation->donation_date) }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="4">{{ __('pdf.project_closure.donations_empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Expenses --}}
<div class="section-title">{{ __('pdf.project_closure.expenses_title', ['count' => $summary['expense_count']]) }}</div>
<div class="section-note">{{ __('pdf.project_closure.expenses_note') }}</div>
<table class="data-table">
    <colgroup>
        <col style="width: 22%">
        <col style="width: 16%">
        <col style="width: 32%">
        <col style="width: 15%">
        <col style="width: 15%">
    </colgroup>
    <thead>
        <tr>
            <th class="text-left">{{ __('pdf.project_closure.expenses_table.supplier') }}</th>
            <th class="text-right">{{ __('pdf.project_closure.expenses_table.amount') }}</th>
            <th class="text-left">{{ __('pdf.project_closure.expenses_table.description') }}</th>
            <th class="text-center">{{ __('pdf.project_closure.expenses_table.status') }}</th>
            <th class="text-center">{{ __('pdf.project_closure.expenses_table.date') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($expenses as $expense)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td class="text-left">{{ $expense->supplier_name }}</td>
                <td class="amount">{{ closure_currency($expense->amount, $settings->currency) }}</td>
                <td class="text-left">{{ $expense->description }}</td>
                <td class="text-center"><span class="badge {{ closure_badge_class($expense->status) }}">{{ report_status_label($expense->status) }}</span></td>
                <td class="text-center">{{ report_date($expense->expense_date) }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="5">{{ __('pdf.project_closure.expenses_empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

{{-- Fund allocations --}}
<div class="section-title">{{ __('pdf.project_closure.allocations_title') }}</div>
<table class="data-table">
    <colgroup>
        <col style="width: 25%">
        <col style="width: 25%">
        <col style="width: 50%">
    </colgroup>
    <thead>
        <tr>
            <th class="text-right">{{ __('pdf.project_closure.allocations_table.amount') }}</th>
            <th class="text-center">{{ __('pdf.project_closure.allocations_table.date') }}</th>
            <th class="text-left">{{ __('pdf.project_closure.allocations_table.recorded_by') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse($allocations as $allocation)
            <tr class="{{ $loop->even ? 'row-even' : '' }}">
                <td class="amount">{{ closure_currency($allocation->amount, $settings->currency) }}</td>
                <td class="text-center">{{ report_date($allocation->allocation_date) }}</td>
                <td class="text-left">{{ $allocation->recorder?->name ?? '—' }}</td>
            </tr>
        @empty
            <tr><td class="empty-row" colspan="3">{{ __('pdf.project_closure.allocations_empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
