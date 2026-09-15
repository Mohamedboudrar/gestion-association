{{-- Simple CSS bar chart (dompdf has no canvas/JS, so bars are just sized divs).
     Expects $chartTitle and $bars = [['label' => ..., 'value' => float, 'display' => 'text'], ...] --}}
@php
    $maxValue = collect($bars)->max('value') ?: 1;
@endphp
<div class="chart-section">
    <div class="section-title">{{ $chartTitle }}</div>
    @foreach($bars as $bar)
        <div class="chart-row">
            <table class="chart-label-row">
                <tr>
                    <td class="chart-label">{{ $bar['label'] }}</td>
                    <td class="chart-value">{{ $bar['display'] }}</td>
                </tr>
            </table>
            <div class="chart-track">
                <div class="chart-fill" style="width: {{ max(2, round(($bar['value'] / $maxValue) * 100)) }}%; background: {{ $bar['color'] ?? '#0F5C57' }};"></div>
            </div>
        </div>
    @endforeach
</div>
