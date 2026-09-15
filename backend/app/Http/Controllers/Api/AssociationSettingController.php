<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAssociationSettingRequest;
use App\Http\Resources\AssociationSettingResource;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;

class AssociationSettingController extends Controller
{
    public function show()
    {
        // No authorize() call: this route is intentionally public (see
        // routes/api.php) so the login page and member-portal login page
        // can render branding before any session exists. There is no
        // "guest" case to deny — every visitor may read it.
        return new AssociationSettingResource(SettingsService::get());
    }

    public function update(UpdateAssociationSettingRequest $request)
    {
        $setting = SettingsService::get();

        $this->authorize('update', $setting);

        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($setting->association_logo) {
                Storage::disk('public')->delete($setting->association_logo);
            }

            $data['association_logo'] = $request->file('logo')->store('association', 'public');
        }

        unset($data['logo']);

        $setting->update($data);

        SettingsService::refresh();

        return new AssociationSettingResource($setting->fresh());
    }
}
