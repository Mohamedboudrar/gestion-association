{{-- Shared corporate stylesheet for every PDF report. dompdf renders a
     subset of CSS2.1/CSS3 — no flexbox/grid, so layout below leans on
     table/block/inline-block. Colors, spacing, and type scale intentionally
     centralized here so every report stays visually consistent. --}}
<style>
    @page {
        margin: 150px 36px 76px 36px;
    }

    * {
        box-sizing: border-box;
    }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 10.5px;
        line-height: 1.5;
        color: #1F2937;
    }

    h1, h2, h3 {
        font-family: 'DejaVu Sans', sans-serif;
        font-weight: bold;
        color: #14213D;
        margin: 0;
    }

    /* ---------- Fixed, repeating page header ---------- */
    .pdf-header {
        position: fixed;
        top: -150px;
        left: 0;
        right: 0;
        height: 118px;
    }

    .pdf-header .brand-row {
        width: 100%;
        border-collapse: collapse;
    }

    .pdf-header .brand-row td {
        border: none;
        padding: 0;
        vertical-align: middle;
    }

    .pdf-header .logo-cell {
        width: 52px;
    }

    .pdf-header .logo-cell img {
        height: 44px;
        max-width: 48px;
    }

    .pdf-header .association-name {
        font-size: 14px;
        font-weight: bold;
        color: #14213D;
    }

    .pdf-header .association-contact {
        font-size: 8.5px;
        color: #64748B;
        margin-top: 2px;
    }

    .pdf-header .report-title-cell {
        text-align: right;
    }

    .pdf-header .report-title {
        font-size: 12px;
        font-weight: bold;
        color: #0F5C57;
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }

    .pdf-header .report-generated {
        font-size: 8.5px;
        color: #64748B;
        margin-top: 2px;
    }

    .pdf-header .header-rule {
        margin-top: 10px;
        border: none;
        border-top: 1.5px solid #0F5C57;
    }

    /* ---------- Fixed, repeating page footer ---------- */
    .pdf-footer {
        position: fixed;
        bottom: -76px;
        left: 0;
        right: 0;
        height: 46px;
        padding-top: 8px;
        border-top: 0.75px solid #E2E8F0;
        font-size: 8.5px;
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
       current page number auto-increments — so the footer shows a running
       page number rather than an "X / Y" pair that would silently render
       as "X / 0". */
    .pdf-footer .page-number:after {
        content: "{{ __('pdf.common.page_prefix') }}" counter(page);
    }

    /* ---------- Report metadata block (page 1 only, in-flow) ---------- */
    .report-meta {
        margin-bottom: 18px;
    }

    .report-meta h1 {
        font-size: 20px;
    }

    .report-meta .report-subtitle {
        margin-top: 4px;
        font-size: 10.5px;
        color: #475569;
    }

    .report-meta .meta-line {
        margin-top: 6px;
        font-size: 8.5px;
        color: #94A3B8;
    }

    /* ---------- Section titles ---------- */
    .section-title {
        font-size: 12.5px;
        color: #14213D;
        margin: 22px 0 10px 0;
        padding-bottom: 4px;
        border-bottom: 1px solid #E2E8F0;
    }

    .section-note {
        font-size: 9px;
        color: #64748B;
        margin: -4px 0 8px 0;
    }

    /* ---------- Executive summary cards ---------- */
    table.summary-cards {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
    }

    table.summary-cards td.card-cell {
        width: 25%;
        padding: 0 8px 0 0;
        border: none;
        vertical-align: top;
    }

    table.summary-cards td.card-cell.last {
        padding-right: 0;
    }

    table.summary-cards .card {
        background: #F8FAFC;
        border: 0.75px solid #E2E8F0;
        border-left: 3px solid #0F5C57;
        border-radius: 4px;
        padding: 10px 12px;
    }

    table.summary-cards .card-label {
        font-size: 8px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        color: #64748B;
        margin-bottom: 4px;
    }

    table.summary-cards .card-value {
        font-size: 15px;
        font-weight: bold;
        color: #14213D;
    }

    table.summary-cards .card-value.tone-warning {
        color: #B45309;
    }

    table.summary-cards .card-value.tone-danger {
        color: #B91C1C;
    }

    /* ---------- Simple CSS bar chart (no JS/canvas available in dompdf) ---------- */
    .chart-section {
        margin-bottom: 4px;
    }

    .chart-row {
        margin-bottom: 7px;
    }

    .chart-row .chart-label-row {
        width: 100%;
    }

    .chart-row .chart-label {
        font-size: 9px;
        color: #334155;
    }

    .chart-row .chart-value {
        font-size: 9px;
        color: #64748B;
        text-align: right;
    }

    .chart-track {
        background: #EEF2F6;
        border-radius: 3px;
        height: 9px;
        margin-top: 2px;
    }

    .chart-fill {
        height: 9px;
        border-radius: 3px;
        background: #0F5C57;
    }

    /* ---------- Data tables ---------- */
    table.data-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }

    table.data-table thead {
        display: table-header-group;
    }

    table.data-table th {
        background: #14213D;
        color: #FFFFFF;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        text-align: left;
        padding: 7px 9px;
        border: none;
    }

    table.data-table td {
        padding: 6px 9px;
        border: none;
        border-bottom: 0.75px solid #E9EDF2;
        font-size: 9.5px;
        vertical-align: top;
    }

    table.data-table tbody tr {
        page-break-inside: avoid;
    }

    table.data-table tbody tr.row-even {
        background: #F7F9FB;
    }

    table.data-table td.numeric {
        text-align: right;
        font-weight: bold;
        color: #14213D;
    }

    table.data-table td.empty-row {
        text-align: center;
        color: #94A3B8;
        padding: 16px;
    }

    /* ---------- Status badges ---------- */
    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 8px;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge-success {
        background: #DCFCE7;
        color: #15803D;
    }

    .badge-warning {
        background: #FEF3C7;
        color: #B45309;
    }

    .badge-danger {
        background: #FEE2E2;
        color: #B91C1C;
    }

    .badge-neutral {
        background: #F1F5F9;
        color: #475569;
    }
</style>
