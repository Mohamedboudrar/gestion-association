<?php

namespace App\Http\Controllers\Api;

use App\Exports\DuesExport;
use App\Exports\MembersExport;
use App\Exports\ProjectsExport;
use App\Exports\SubscriptionsExport;
use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Models\Due;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subscription;
use App\Services\DuesService;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function members()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        $members = Member::with('user')->get();
        $settings = SettingsService::get();

        $pdf = Pdf::loadView('reports.members', compact('members', 'settings'));

        return $pdf->download('members-report.pdf');
    }

    public function subscriptions()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        $subscriptions = Subscription::with([
            'member.user',
            'verifier',
        ])->get();
        $settings = SettingsService::get();

        $pdf = Pdf::loadView(
            'reports.subscriptions',
            compact('subscriptions', 'settings')
        );

        return $pdf->download('subscriptions-report.pdf');
    }

    public function dues(DuesService $duesService)
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        $dues = Due::with('member.user')->orderByDesc('year')->get();
        $settings = SettingsService::get();
        $summary = $duesService->summary();
        $expected = $summary['expected'];
        $collected = $summary['collected'];
        $outstanding = $summary['outstanding'];
        $collectionRate = $summary['collection_rate'];

        $pdf = Pdf::loadView(
            'reports.dues',
            compact('dues', 'settings', 'expected', 'collected', 'outstanding', 'collectionRate')
        );

        return $pdf->download('dues-report.pdf');
    }

    public function projects()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        $projects = Project::with([
            'manager',
            'members.user',
        ])->get();
        $settings = SettingsService::get();

        $pdf = Pdf::loadView(
            'reports.projects',
            compact('projects', 'settings')
        );

        return $pdf->download('projects-report.pdf');
    }

    public function membersExcel()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        return Excel::download(
            new MembersExport,
            'members.xlsx'
        );
    }

    public function subscriptionsExcel()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        return Excel::download(
            new SubscriptionsExport,
            'subscriptions.xlsx'
        );
    }

    public function projectsExcel()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        return Excel::download(
            new ProjectsExport,
            'projects.xlsx'
        );
    }

    public function duesExcel()
    {
        abort_unless(AuthorizationHelper::isBureauMember(auth()->user()), 403);

        return Excel::download(
            new DuesExport,
            'dues.xlsx'
        );
    }
}
