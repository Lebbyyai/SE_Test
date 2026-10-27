<?php

namespace App\Console\Commands;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class AuditDiagramPages extends Command
{
    protected $signature = 'demo:audit-diagram';

    protected $description = 'Read-only local checks of diagram-role dashboards and primary pages';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This audit is only available locally.');

            return self::FAILURE;
        }

        $pages = [
            'student@uic.edu.ph' => ['/dashboard', '/defense-requirements', '/comprehensive-exam', '/schedule', '/payment', '/student/documents', '/academic-records', '/settings/profile', '/settings/password', '/settings/appearance', '/settings/signatures'],
            'adviser@uic.edu.ph' => ['/dashboard', '/all-defense-requirements', '/adviser/students-list', '/honorarium-ledger', '/schedules', '/honorarium-workspace', '/defense-register', '/settings/general', '/settings/signatures', '/api/adviser/students', '/api/adviser/pending-students'],
            'acadcoor@uic.edu.ph' => ['/dashboard', '/coordinator/defense-requests', '/coordinator/defense-management', '/coordinator/adviser-list', '/coordinator/compre-exam', '/honorarium-workspace', '/panelists', '/schedule', '/coordinator/compre-payment', '/coordinator/schedule/calendar', '/student-records', '/dean/payment-rates', '/defense-register', '/settings/general', '/settings/signatures'],
            'aa@uic.edu.ph' => ['/dashboard', '/assistant/all-defense-list', '/honorarium-summary', '/honorarium-workspace', '/honorarium-documents', '/honorarium-ledger', '/aa/payment-verifications', '/coordinator/compre-exam', '/coordinator/compre-payment', '/student-records', '/dean/payment-rates', '/defense-register', '/schedules', '/generate-report', '/settings/signatures'],
            'finance@uic.edu.ph' => ['/dashboard', '/honorarium-workspace', '/honorarium-documents', '/honorarium-ledger', '/defense-register', '/settings/profile'],
            'dean@uic.edu.ph' => ['/dashboard', '/dean/payment-rates', '/settings/documents', '/dean/defense-requests', '/honorarium-workspace', '/honorarium-ledger', '/honorarium-documents', '/honorarium-summary', '/defense-register', '/student-records', '/schedules', '/settings/signatures', '/generate-report', '/panelists/honorarium-specs'],
            'superadmin@uic.edu.ph' => ['/dashboard'],
        ];
        $results = [];
        $kernel = app(Kernel::class);
        foreach ($pages as $email => $urls) {
            $user = User::where('email', $email)->when($email !== 'superadmin@uic.edu.ph', fn ($query) => $query->where('is_demo_account', true))->firstOrFail();
            foreach ($urls as $url) {
                app('auth')->forgetGuards();
                Auth::guard('web')->setUser($user);
                $request = Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_INERTIA' => 'true']);
                $request->headers->set('X-Inertia-Version', app(HandleInertiaRequests::class)->version($request) ?? '');
                $request->setUserResolver(fn () => $user);
                $response = $kernel->handle($request);
                $body = json_decode($response->getContent(), true);
                $component = $body['component'] ?? null;
                $componentExists = $component ? file_exists(resource_path('js/pages/'.$component.'.tsx')) : null;
                $results[] = [
                    'role' => $user->role,
                    'url' => $url,
                    'status' => $response->getStatusCode(),
                    'redirect_to' => $response->headers->get('Location'),
                    'component' => $component,
                    'component_exists' => $componentExists,
                    'error' => $response->getStatusCode() >= 400 ? ($body['message'] ?? 'Request failed') : null,
                ];
            }
        }
        $brokenRoutes = [];
        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$controller, $method] = explode('@', $action, 2);
            if (! class_exists($controller) || ! method_exists($controller, $method)) {
                $brokenRoutes[] = ['methods' => $route->methods(), 'uri' => $route->uri(), 'action' => $action];
            }
        }
        $report = ['checked_at' => now()->toIso8601String(), 'pages' => $results, 'broken_routes' => $brokenRoutes, 'tables' => [
            'settings' => Schema::hasTable('settings'),
            'adviser_settings' => Schema::hasTable('adviser_settings'),
        ]];
        file_put_contents(base_path('docs/diagram-audit-results.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->table(['Role', 'URL', 'HTTP', 'Component'], collect($results)->map(fn ($result) => [
            $result['role'], $result['url'], $result['status'], $result['component'] ?? $result['error'],
        ])->all());
        $this->info('Saved docs/diagram-audit-results.json. These GET checks do not verify workflow transitions or browser rendering.');
        $this->warn(count($brokenRoutes).' routes refer to missing controllers or methods; see the JSON report.');

        return self::SUCCESS;
    }
}
