{{-- Fixed, repeating page footer: association name + page numbers.
     Expects $settings (AssociationSetting) to be in scope. --}}
<div class="pdf-footer">
    <table>
        <tr>
            <td>{{ $settings->association_name }} &middot; {{ __('pdf.common.confidential') }}</td>
            <td class="footer-right"><span class="page-number"></span></td>
        </tr>
    </table>
</div>
