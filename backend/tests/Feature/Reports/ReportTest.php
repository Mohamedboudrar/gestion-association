<?php

use App\Models\Due;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subscription;

function seedReportFixtures(): void
{
    $member = Member::factory()->create();
    Subscription::factory()->for($member)->verified()->create();
    Due::factory()->for($member)->partial()->create();
    Project::factory()->active()->create();
}

function bureauReportActors(): array
{
    return [
        'president' => fn () => presidentActor(),
        'vice-president' => fn () => vicePresidentActor(),
        'tresorier' => fn () => treasurerActor(),
        'vice-tresorier' => fn () => viceTreasurerActor(),
        'secretaire-general' => fn () => secretaireGeneralActor(),
        'vice-secretaire-general' => fn () => viceSecretaireGeneralActor(),
        'conseiller' => fn () => conseillerActor(),
    ];
}

$pdfRoutes = [
    'members' => '/api/reports/members',
    'subscriptions' => '/api/reports/subscriptions',
    'projects' => '/api/reports/projects',
    'dues' => '/api/reports/dues',
];

$excelRoutes = [
    'members' => '/api/reports/members/excel',
    'subscriptions' => '/api/reports/subscriptions/excel',
    'projects' => '/api/reports/projects/excel',
    'dues' => '/api/reports/dues/excel',
];

foreach (bureauReportActors() as $role => $factory) {
    it("lets a {$role} download every PDF report with a non-empty pdf body", function () use ($factory, $pdfRoutes) {
        seedReportFixtures();
        $user = $factory();

        foreach ($pdfRoutes as $route) {
            $response = $this->actingAs($user, 'sanctum')->get($route);

            $response->assertOk();
            expect($response->headers->get('content-type'))->toContain('application/pdf');
            expect(strlen($response->getContent()))->toBeGreaterThan(0);
        }
    });

    it("lets a {$role} download every Excel report with a non-empty body", function () use ($factory, $excelRoutes) {
        seedReportFixtures();
        $user = $factory();

        foreach ($excelRoutes as $route) {
            $response = $this->actingAs($user, 'sanctum')->get($route);

            $response->assertOk();

            // Excel::download() returns a BinaryFileResponse, which streams
            // the file straight from disk — unlike a normal Response, its
            // getContent() returns false rather than the file bytes, so the
            // actual body has to be read off the underlying SplFileInfo.
            $file = $response->baseResponse->getFile();
            expect($file->getSize())->toBeGreaterThan(0);
        }
    });
}

it('forbids a plain subscriber from every PDF and Excel report endpoint', function () use ($pdfRoutes, $excelRoutes) {
    seedReportFixtures();
    $subscriber = subscriberActor();

    foreach (array_merge($pdfRoutes, $excelRoutes) as $route) {
        $this->actingAs($subscriber, 'sanctum')->get($route)->assertStatus(403);
    }
});

it('rejects unauthenticated access to every report endpoint', function () use ($pdfRoutes, $excelRoutes) {
    foreach (array_merge($pdfRoutes, $excelRoutes) as $route) {
        $this->getJson($route)->assertStatus(401);
    }
});
