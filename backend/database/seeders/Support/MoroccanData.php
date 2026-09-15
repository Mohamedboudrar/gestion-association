<?php

namespace Database\Seeders\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Static data pools for realistic Moroccan demo data (names, cities, phone
 * numbers, project/expense/donor vocabulary) plus tiny valid file payloads
 * so seeded receipts/invoices/reports/proofs are real, openable files on the
 * "public" disk rather than dangling paths. Used only by the Demo/* seeders.
 */
class MoroccanData
{
    public const MALE_FIRST_NAMES = [
        'Mohamed', 'Ahmed', 'Youssef', 'Hamza', 'Karim', 'Omar', 'Rachid', 'Said',
        'Abdellah', 'Khalid', 'Mustapha', 'Hicham', 'Younes', 'Anas', 'Amine',
        'Tarik', 'Nabil', 'Adil', 'Reda', 'Soufiane', 'Ismail', 'Mehdi', 'Yassine',
        'Abderrahim', 'Brahim', 'Driss', 'Fouad', 'Hassan', 'Jalal', 'Zakaria',
    ];

    public const FEMALE_FIRST_NAMES = [
        'Fatima', 'Khadija', 'Amina', 'Sara', 'Nadia', 'Latifa', 'Salma', 'Imane',
        'Meryem', 'Zineb', 'Houda', 'Naima', 'Karima', 'Samira', 'Malika', 'Loubna',
        'Asmaa', 'Ghita', 'Ilham', 'Rajae', 'Souad', 'Hanane', 'Fadwa', 'Wafaa',
        'Btissam', 'Chaimae', 'Douae', 'Fatiha', 'Hind', 'Siham',
    ];

    public const LAST_NAMES = [
        'El Amrani', 'Benali', 'Tazi', 'Idrissi', 'Bennani', 'El Fassi', 'Chraibi',
        'Alaoui', 'Berrada', 'Cherkaoui', 'Sekkat', 'El Khatib', 'Guessous', 'Lahlou',
        'Filali', 'Benjelloun', 'El Ouazzani', 'Squalli', 'Bouazza', 'Tahiri',
        'Zerouali', 'Kabbaj', 'El Mansouri', 'Belkadi', 'Ouahbi', 'Rachidi',
        'Naciri', 'El Yacoubi', 'Bensouda', 'Amrani', 'Saidi', 'Boukhris',
        'El Gharbi', 'Rifai', 'Zniber', 'El Idrissi', 'Bouzoubaa', 'Karimi',
        'El Malki', 'Fassi Fihri',
    ];

    public const CITIES = [
        'Casablanca', 'Rabat', 'Marrakech', 'Fès', 'Tanger', 'Agadir', 'Meknès',
        'Oujda', 'Kénitra', 'Tétouan', 'Safi', 'El Jadida', 'Béni Mellal', 'Nador',
        'Khouribga', 'Settat', 'Larache', 'Taza', 'Essaouira', 'Ouarzazate',
    ];

    public const STREET_PREFIXES = [
        'Rue', 'Avenue', 'Boulevard', 'Lotissement', 'Résidence', 'Hay',
    ];

    public const STREET_NAMES = [
        'Mohammed V', 'Hassan II', 'Al Massira', 'Moulay Ismail', 'des FAR',
        'Zerktouni', 'Ibn Sina', 'Allal Ben Abdellah', 'Al Qods', 'An Nakhil',
        'Al Wahda', 'Ibn Khaldoun', 'Anfa', 'Al Amal', 'Riad',
    ];

    public const PROJECT_NAMES = [
        'Water Well Construction',
        'Mosque Renovation',
        'Widow House Renovation',
        'Orphan Support',
        'School Supplies Distribution',
        'Food Basket Ramadan',
        'Medical Caravan',
        'Drinking Water Network',
        'Mosque Construction',
        'Village Road Repair',
        'Solar Panels Installation',
        'Community Center',
        'Quran School',
        'Well Maintenance',
        'Emergency Relief',
        'Housing Repairs',
        'Water Pump Installation',
        'Tree Plantation',
        'Scholarship Program',
        'Local Clinic Equipment',
    ];

    public const EXPENSE_CATEGORIES = [
        'Building materials' => ['Matériaux Atlas', 'Dépôt Al Baraka', 'Ciments du Maroc SARL', 'Quincaillerie Errahma'],
        'Transportation' => ['Transport Al Amana', 'Location Véhicules Sahara', 'Transport Bennani'],
        'Fuel' => ['Station Afriquia', 'Station Shell', 'Station Winxo'],
        'Food' => ['Épicerie Al Khair', 'Boulangerie Ennour', 'Marché Central'],
        'Medical supplies' => ['Pharmacie Al Chifa', 'Fournitures Médicales Atlas', 'Pharmacie Ennasr'],
        'Electricity' => ['ONEE Branchement', 'Électricité Générale du Sud'],
        'Labor' => ['Coopérative Ouvriers Al Ihsane', 'Main d\'oeuvre journalière'],
        'Equipment rental' => ['Location Matériel BTP Atlas', 'Location Sahara Engins'],
        'Administrative fees' => ['Frais Notariat', 'Frais Administration Communale'],
        'Printing' => ['Imprimerie Al Wafa', 'Copy Service Anfa'],
        'Construction materials' => ['Négoce Matériaux El Ghali', 'Fer et Béton Maroc'],
    ];

    public const DONOR_STATE = [
        'Ministère de l\'Intérieur', 'Ministère de la Solidarité', 'Commune Urbaine',
        'Conseil Régional', 'Entraide Nationale',
    ];

    public const DONOR_OTHER_ASSOCIATIONS = [
        'Association Al Ihsane', 'Fondation Mohammed V pour la Solidarité',
        'Association Bayti', 'Croissant Rouge Marocain', 'Association Ennour',
        'Fondation Zakoura',
    ];

    public const DONOR_BENEFACTORS = [
        'Haj Abdelkader Bennis', 'Lalla Fatima Zahra Tazi', 'M. Driss El Amrani',
        'Mme Khadija Berrada', 'Haj Mustapha Idrissi', 'Famille Alaoui',
        'M. Rachid Squalli',
    ];

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'card', 'cheque'];

    public static function randomFullName(?string $gender = null): array
    {
        $gender ??= random_int(0, 1) ? 'male' : 'female';
        $first = $gender === 'male'
            ? self::MALE_FIRST_NAMES[array_rand(self::MALE_FIRST_NAMES)]
            : self::FEMALE_FIRST_NAMES[array_rand(self::FEMALE_FIRST_NAMES)];
        $last = self::LAST_NAMES[array_rand(self::LAST_NAMES)];

        return ['first' => $first, 'last' => $last, 'name' => "{$first} {$last}"];
    }

    public static function randomPhone(): string
    {
        $prefix = random_int(0, 1) ? '6' : '7';

        return '+212 '.$prefix.' '.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT)
            .' '.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT)
            .' '.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT)
            .' '.str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
    }

    public static function randomAddress(): string
    {
        $number = random_int(1, 250);
        $prefix = self::STREET_PREFIXES[array_rand(self::STREET_PREFIXES)];
        $street = self::STREET_NAMES[array_rand(self::STREET_NAMES)];
        $city = self::CITIES[array_rand(self::CITIES)];

        return "N°{$number}, {$prefix} {$street}, {$city}";
    }

    public static function randomCity(): string
    {
        return self::CITIES[array_rand(self::CITIES)];
    }

    /**
     * A minimal, structurally valid single-page PDF — small enough to
     * generate thousands of times, but a real file a PDF viewer will open
     * (not just bytes with a .pdf extension).
     */
    public static function fakePdfBytes(string $title): string
    {
        $stream = "BT /F1 18 Tf 40 760 Td ({$title}) Tj ET";
        $streamLength = strlen($stream);

        return <<<PDF
%PDF-1.4
1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Resources<</Font<</F1 4 0 R>>>>/Contents 5 0 R>>endobj
4 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj
5 0 obj<</Length {$streamLength}>>stream
{$stream}
endstream
endobj
xref
0 6
trailer<</Size 6/Root 1 0 R>>
startxref
0
%%EOF
PDF;
    }

    /**
     * A 1x1 transparent PNG — a real, valid image file for receipt/invoice
     * "photo" placeholders.
     */
    public static function fakeImageBytes(): string
    {
        return base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        );
    }

    // Writes a small real PDF to the public disk under $folder and returns
    // its relative path — used for receipts/invoices/reports/proofs so
    // seeded records point at real, openable files instead of dead paths.
    public static function storeFakePdf(string $folder, string $title): string
    {
        $path = "{$folder}/".Str::uuid().'.pdf';
        Storage::disk('public')->put($path, self::fakePdfBytes($title));

        return $path;
    }

    public static function storeFakeImage(string $folder): string
    {
        $path = "{$folder}/".Str::uuid().'.png';
        Storage::disk('public')->put($path, self::fakeImageBytes());

        return $path;
    }
}
