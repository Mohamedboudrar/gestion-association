{{-- Executive summary card row. Expects $cards = [['label' => ..., 'value' => ..., 'tone' => 'warning'|'danger'|null], ...] --}}
@php($cardWidth = round(100 / max(count($cards), 1), 2))
<table class="summary-cards">
    <tr>
        @foreach($cards as $index => $card)
            <td class="card-cell {{ $loop->last ? 'last' : '' }}" style="width: {{ $cardWidth }}%;">
                <div class="card">
                    <div class="card-label">{{ $card['label'] }}</div>
                    <div class="card-value {{ isset($card['tone']) ? 'tone-'.$card['tone'] : '' }}">{{ $card['value'] }}</div>
                </div>
            </td>
        @endforeach
    </tr>
</table>
