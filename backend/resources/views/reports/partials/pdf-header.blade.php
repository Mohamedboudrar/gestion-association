{{-- Fixed, repeating page header: association branding + report title.
     Expects $settings (AssociationSetting) and $reportTitle to be in scope. --}}
<div class="pdf-header">
    <table class="brand-row">
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
