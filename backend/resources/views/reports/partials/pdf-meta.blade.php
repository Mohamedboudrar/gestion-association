{{-- In-flow report metadata block, rendered once at the top of page 1.
     Expects $reportTitle and optionally $reportSubtitle in scope. --}}
<div class="report-meta">
    <h1>{{ $reportTitle }}</h1>
    @isset($reportSubtitle)
        <div class="report-subtitle">{{ $reportSubtitle }}</div>
    @endisset
    <div class="meta-line">
        {{ __('pdf.common.generated_by', [
            'date' => report_date(now(), 'd M Y'),
            'time' => now()->format('H:i'),
            'name' => auth()->user()->name ?? __('pdf.common.system'),
        ]) }}
    </div>
</div>
