<?php

declare(strict_types=1);

use App\CallableEventDispatcherInterface;
use App\Event;
use App\Middleware;
use Plugin\BranchTracker\Console;
use Plugin\BranchTracker\Controller;
use Plugin\BranchTracker\Permissions;
use Slim\Routing\RouteCollectorProxy;

/*
 * Every listener is wrapped in guard(): an exception in plugin wiring must not take AzuraCast down.
 * (Phase 0 showed a plugin fatal at boot leaves nginx, the stations and now-playing stopped,
 * and sync_disabled=1.) A failure is logged and the plugin's part is skipped.
 */
$guard = static function (string $what, callable $fn): callable {
    return static function (...$args) use ($what, $fn): void {
        try {
            $fn(...$args);
        } catch (\Throwable $e) {
            error_log(sprintf('[branch-tracker] %s failed: %s in %s:%d', $what, $e->getMessage(), $e->getFile(), $e->getLine()));
        }
    };
};

return static function (CallableEventDispatcherInterface $dispatcher) use ($guard) {
    $dispatcher->addListener(
        Event\BuildDoctrineMappingPaths::class,
        $guard('mapping paths', static function (Event\BuildDoctrineMappingPaths $event) {
            $paths = $event->getMappingClassesPaths();
            $paths[] = __DIR__ . '/src/Entity';
            $event->setMappingClassesPaths($paths);
        })
    );

    $dispatcher->addListener(
        Event\BuildMigrationConfigurationArray::class,
        $guard('migrations', static function (Event\BuildMigrationConfigurationArray $event) {
            $config = $event->getMigrationConfigurations();
            $config['migrations_paths']['Plugin\BranchTracker\Migration'] = __DIR__ . '/src/Migration';
            $event->setMigrationConfigurations($config);
        })
    );

    $dispatcher->addListener(
        Event\BuildPermissions::class,
        $guard('permissions', static function (Event\BuildPermissions $event) {
            $permissions = $event->getPermissions();
            foreach (Permissions::NAMES as $key => $label) {
                $permissions['station'][$key] = $label;
            }
            $event->setPermissions($permissions);
        })
    );

    $dispatcher->addListener(
        Event\BuildConsoleCommands::class,
        $guard('console commands', static function (Event\BuildConsoleCommands $event) {
            $event->addAliases([
                'branch-tracker:import' => Console\ImportBranchesCommand::class,
                'branch-tracker:stream-auth' => Console\StreamAuthCommand::class,
                'branch-tracker:presence' => Console\PresenceCommand::class,
                'branch-tracker:public-page' => Console\PublicPageCommand::class,
            ]);
        })
    );

    $dispatcher->addListener(
        Event\GetSyncTasks::class,
        $guard('sync tasks', static function (Event\GetSyncTasks $event) {
            $event->addTask(\Plugin\BranchTracker\Sync\PresenceTask::class);
        })
    );

    $dispatcher->addListener(
        Event\BuildRoutes::class,
        $guard('routes', static function (Event\BuildRoutes $event) {
            $app = $event->getApp();

            // Route-group callbacks must NOT be static: Slim binds them to the container.
            $app->group(
                '/api/branch-tracker',
                function (RouteCollectorProxy $group) {
                    $group->get('/ping', Controller\PingAction::class)->setName('branch-tracker:ping');

                    // Icecast listener_add target (set on mounts by branch-tracker:stream-auth).
                    $group->map(
                        ['GET', 'POST'],
                        '/internal/{station_id}/listener-auth/{mode}/{api_auth}',
                        Controller\IcecastAuthAction::class
                    )->setName('branch-tracker:listener-auth')
                        ->add(Middleware\GetStation::class);
                }
            )->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\PublicAuth::class);

            // Public branch API used by the player page. PIN only in POST bodies.
            $app->group(
                '/api/station/{station_id}/branch',
                function (RouteCollectorProxy $group) {
                    $c = Controller\BranchAuthController::class;
                    $group->post('/login', $c . ':loginAction')->setName('branch-tracker:login');
                    $group->post('/heartbeat', $c . ':heartbeatAction')->setName('branch-tracker:heartbeat');
                    $group->post('/logout', $c . ':logoutAction')->setName('branch-tracker:logout');
                }
            )->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\PublicAuth::class);

            // Admin dashboard (logged-in AzuraCast users with "view branches"). Not under /station/{id}/...:
            // core's dashboard.php has a catch-all /station/{station_id}/{routes:.+} for its Vue app.
            $app->get('/branch-tracker/{station_id}', Controller\DashboardPageAction::class)
                ->setName('branch-tracker:dashboard')
                ->add(new Middleware\Permissions(Permissions::VIEW, true))
                ->add(Middleware\RequireLogin::class)
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Auth\StandardAuth::class)
                ->add(Middleware\InjectSession::class);

            // "Branches" button on AzuraCast's own station pages (no menu hook exists; see InjectNavButton).
            $app->get(\Plugin\BranchTracker\Middleware\InjectNavButton::SCRIPT_PATH, Controller\NavScriptAction::class)
                ->setName('branch-tracker:nav-js');
            $app->add(new \Plugin\BranchTracker\Middleware\InjectNavButton());
            // Branch PIN login + player on the station's own /public/{station} page, when switched on per station
            // (or forced by stream-auth enforce). Added by class name so the container injects dependencies.
            $app->add(\Plugin\BranchTracker\Middleware\PublicPageTakeover::class);

            // The branch player page.
            $app->get('/public/{station_id}/branch', Controller\PlayerPageAction::class)
                ->setName('branch-tracker:player')
                ->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class);

            // Admin API, same middleware stack as core's /api/station/{station_id} routes.
            $app->group(
                '/api/station/{station_id}/branches',
                function (RouteCollectorProxy $group) {
                    $c = Controller\BranchesController::class;
                    $view = new Middleware\Permissions(Permissions::VIEW, true);
                    $manage = new Middleware\Permissions(Permissions::MANAGE, true);

                    $r = Controller\ReportsController::class;
                    $group->get('/live', $r . ':liveAction')->setName('branch-tracker:live')->add($view);
                    $group->get('/sessions', $r . ':sessionsAction')->setName('branch-tracker:sessions')->add($view);
                    $group->get('/report', $r . ':reportAction')->setName('branch-tracker:report')->add($view);

                    $st = Controller\SettingsController::class;
                    $group->get('/settings', $st . ':getAction')->setName('branch-tracker:settings')->add($view);
                    $group->put('/settings', $st . ':putAction')->add($manage);

                    $group->get('', $c . ':listAction')->setName('branch-tracker:branches')->add($view);
                    $group->post('', $c . ':createAction')->add($manage);
                    $group->get('/{branch_id:\d+}', $c . ':getAction')->setName('branch-tracker:branch')->add($view);
                    $group->put('/{branch_id:\d+}', $c . ':updateAction')->add($manage);
                    $group->delete('/{branch_id:\d+}', $c . ':deleteAction')->add($manage);
                    $group->post('/{branch_id:\d+}/pin', $c . ':setPinAction')->add($manage);
                }
            )->add(Middleware\RequireStation::class)
                ->add(Middleware\GetStation::class)
                ->add(Middleware\Module\Api::class)
                ->add(Middleware\Auth\ApiAuth::class)
                ->add(Middleware\InjectSession::class);
        })
    );
};
