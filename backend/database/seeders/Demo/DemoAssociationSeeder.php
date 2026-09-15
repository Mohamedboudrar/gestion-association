<?php

namespace Database\Seeders\Demo;

use App\Models\AssociationSetting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

// The "1 association" requirement — updates the existing singleton settings
// row (see SettingsService) with realistic content instead of creating a
// second row, since AssociationSetting is enforced as a singleton in
// application code.
class DemoAssociationSeeder extends Seeder
{
    public function run(): void
    {
        AssociationSetting::query()->delete();

        AssociationSetting::create([
            'association_name' => 'Association Al Amal pour le Développement Social',
            'association_logo' => null,
            'address' => 'N°45, Avenue Mohammed V, Rabat',
            'phone' => '+212 5 37 76 54 32',
            'email' => 'contact@alamal-dev.ma',
            'website' => 'https://www.alamal-dev.ma',
            'annual_subscription_amount' => 200,
            'currency' => 'MAD',
            'description' => 'Association à but non lucratif oeuvrant pour le développement social, '
                .'l\'éducation et la solidarité auprès des familles et communautés les plus vulnérables '
                .'du Royaume.',
        ]);

        SettingsService::refresh();
    }
}
