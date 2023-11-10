<?php

namespace App\Jobs;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Notifications\Container\ContainerRestarted;
use App\Notifications\Container\ContainerStopped;
use App\Notifications\Server\Revived;
use App\Notifications\Server\Unreachable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;

class ContainerStatusJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Server $server)
    {
        $this->handle();
    }
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->server->id))->dontRelease()];
    }

    public function uniqueId(): int
    {
        return $this->server->id;
    }

    public function handle(): void
    {
        // ray("checking server status for {$this->server->id}");
        try {
            // ray()->clearAll();
            $serverUptimeCheckNumber = $this->server->unreachable_count;
            $serverUptimeCheckNumberMax = 3;

            // ray('checking # ' . $serverUptimeCheckNumber);
            if ($serverUptimeCheckNumber >= $serverUptimeCheckNumberMax) {
                if ($this->server->unreachable_email_sent === false) {
                    ray('Server unreachable, sending notification...');
                    $this->server->team->notify(new Unreachable($this->server));
                    $this->server->update(['unreachable_email_sent' => true]);
                }
                $this->server->settings()->update([
                    'is_reachable' => false,
                ]);
                $this->server->update([
                    'unreachable_count' => 0,
                ]);
                // Update all applications, databases and services to exited
                foreach ($this->server->applications() as $application) {
                    $application->update(['status' => 'exited']);
                }
                foreach ($this->server->databases() as $database) {
                    $database->update(['status' => 'exited']);
                }
                foreach ($this->server->services() as $service) {
                    $apps = $service->applications()->get();
                    $dbs = $service->databases()->get();
                    foreach ($apps as $app) {
                        $app->update(['status' => 'exited']);
                    }
                    foreach ($dbs as $db) {
                        $db->update(['status' => 'exited']);
                    }
                }
                return;
            }
            $result = $this->server->validateConnection();
            if ($result) {
                $this->server->settings()->update([
                    'is_reachable' => true,
                ]);
                $this->server->update([
                    'unreachable_count' => 0,
                ]);
            } else {
                $serverUptimeCheckNumber++;
                $this->server->settings()->update([
                    'is_reachable' => false,
                ]);
                $this->server->update([
                    'unreachable_count' => $serverUptimeCheckNumber,
                ]);
                return;
            }

            if (data_get($this->server, 'unreachable_email_sent') === true) {
                ray('Server is reachable again, sending notification...');
                $this->server->team->notify(new Revived($this->server));
                $this->server->update(['unreachable_email_sent' => false]);
            }
            if (
                data_get($this->server, 'settings.is_reachable') === false ||
                data_get($this->server, 'settings.is_usable') === false
            ) {
                $this->server->settings()->update([
                    'is_reachable' => true,
                    'is_usable' => true
                ]);
            }
            // $this->server->validateDockerEngine(true);
            $containers = instant_remote_process(["docker container ls -q"], $this->server);
            if (!$containers) {
                return;
            }
            $containers = instant_remote_process(["docker container inspect $(docker container ls -q) --format '{{json .}}'"], $this->server);
            $containers = format_docker_command_output_to_json($containers);
            $applications = $this->server->applications();
            $databases = $this->server->databases();
            $services = $this->server->services()->get();
            $previews = $this->server->previews();
            $this->server->proxyType();
            /// Check if proxy is running
            $foundProxyContainer = $containers->filter(function ($value, $key) {
                return data_get($value, 'Name') === '/coolify-proxy';
            })->first();
            if (!$foundProxyContainer) {
                try {
                    $shouldStart = CheckProxy::run($this->server);
                    if ($shouldStart) {
                        StartProxy::run($this->server, false);
                        $this->server->team->notify(new ContainerRestarted('coolify-proxy', $this->server));
                    } else {
                        ray('Proxy could not be started.');
                    }
                } catch (\Throwable $e) {
                    ray($e);
                }
            } else {
                $this->server->proxy->status = data_get($foundProxyContainer, 'State.Status');
                $this->server->save();
                $connectProxyToDockerNetworks = connectProxyToNetworks($this->server);
                instant_remote_process($connectProxyToDockerNetworks, $this->server, false);
            }
            $foundApplications = [];
            $foundApplicationPreviews = [];
            $foundDatabases = [];
            $foundServices = [];

            foreach ($containers as $container) {
                $containerStatus = data_get($container, 'State.Status');
                $containerHealth = data_get($container, 'State.Health.Status', 'unhealthy');
                $containerStatus = "$containerStatus ($containerHealth)";
                $labels = data_get($container, 'Config.Labels');
                $labels = Arr::undot(format_docker_labels_to_json($labels));
                $applicationId = data_get($labels, 'coolify.applicationId');
                if ($applicationId) {
                    $pullRequestId = data_get($labels, 'coolify.pullRequestId');
                    if ($pullRequestId) {
                        $preview = ApplicationPreview::where('application_id', $applicationId)->where('pull_request_id', $pullRequestId)->first();
                        if ($preview) {
                            $foundApplicationPreviews[] = $preview->id;
                            $statusFromDb = $preview->status;
                            if ($statusFromDb !== $containerStatus) {
                                $preview->update(['status' => $containerStatus]);
                            }
                        } else {
                            //Notify user that this container should not be there.
                        }
                    } else {
                        $application = $applications->where('id', $applicationId)->first();
                        if ($application) {
                            $foundApplications[] = $application->id;
                            $statusFromDb = $application->status;
                            if ($statusFromDb !== $containerStatus) {
                                $application->update(['status' => $containerStatus]);
                            }
                        } else {
                            //Notify user that this container should not be there.
                        }
                    }
                } else {
                    $uuid = data_get($labels, 'com.docker.compose.service');
                    if ($uuid) {
                        $database = $databases->where('uuid', $uuid)->first();
                        if ($database) {
                            $foundDatabases[] = $database->id;
                            $statusFromDb = $database->status;
                            if ($statusFromDb !== $containerStatus) {
                                $database->update(['status' => $containerStatus]);
                            }
                        } else {
                            // Notify user that this container should not be there.
                        }
                    }
                }
                $serviceLabelId = data_get($labels, 'coolify.serviceId');
                if ($serviceLabelId) {
                    $subType = data_get($labels, 'coolify.service.subType');
                    $subId = data_get($labels, 'coolify.service.subId');
                    $service = $services->where('id', $serviceLabelId)->first();
                    if (!$service) {
                        continue;
                    }
                    if ($subType === 'application') {
                        $service =  $service->applications()->where('id', $subId)->first();
                    } else {
                        $service =  $service->databases()->where('id', $subId)->first();
                    }
                    if ($service) {
                        $foundServices[] = "$service->id-$service->name";
                        $statusFromDb = $service->status;
                        if ($statusFromDb !== $containerStatus) {
                            // ray('Updating status: ' . $containerStatus);
                            $service->update(['status' => $containerStatus]);
                        }
                    }
                }
            }
            $exitedServices = collect([]);
            foreach ($services as $service) {
                $apps = $service->applications()->get();
                $dbs = $service->databases()->get();
                foreach ($apps as $app) {
                    if (in_array("$app->id-$app->name", $foundServices)) {
                        continue;
                    } else {
                        $exitedServices->push($app);
                    }
                }
                foreach ($dbs as $db) {
                    if (in_array("$db->id-$db->name", $foundServices)) {
                        continue;
                    } else {
                        $exitedServices->push($db);
                    }
                }
            }
            $exitedServices = $exitedServices->unique('id');
            foreach ($exitedServices as $exitedService) {
                if ($exitedService->status === 'exited') {
                    continue;
                }
                $name = data_get($exitedService, 'name');
                $fqdn = data_get($exitedService, 'fqdn');
                $containerName = $name ? "$name ($fqdn)" : $fqdn;
                $projectUuid = data_get($service, 'environment.project.uuid');
                $serviceUuid = data_get($service, 'uuid');
                $environmentName = data_get($service, 'environment.name');

                if ($projectUuid && $serviceUuid && $environmentName) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/service/" . $serviceUuid;
                }
                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
                $exitedService->update(['status' => 'exited']);
            }

            $notRunningApplications = $applications->pluck('id')->diff($foundApplications);
            foreach ($notRunningApplications as $applicationId) {
                $application = $applications->where('id', $applicationId)->first();
                if ($application->status === 'exited') {
                    continue;
                }
                $application->update(['status' => 'exited']);

                $name = data_get($application, 'name');
                $fqdn = data_get($application, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $projectUuid = data_get($application, 'environment.project.uuid');
                $applicationUuid = data_get($application, 'uuid');
                $environment = data_get($application, 'environment.name');

                if ($projectUuid && $applicationUuid && $environment) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environment . "/application/" . $applicationUuid;
                }

                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningApplicationPreviews = $previews->pluck('id')->diff($foundApplicationPreviews);
            foreach ($notRunningApplicationPreviews as $previewId) {
                $preview = $previews->where('id', $previewId)->first();
                if ($preview->status === 'exited') {
                    continue;
                }
                $preview->update(['status' => 'exited']);

                $name = data_get($preview, 'name');
                $fqdn = data_get($preview, 'fqdn');

                $containerName = $name ? "$name ($fqdn)" : $fqdn;

                $projectUuid = data_get($preview, 'application.environment.project.uuid');
                $environmentName = data_get($preview, 'application.environment.name');
                $applicationUuid = data_get($preview, 'application.uuid');

                if ($projectUuid && $applicationUuid && $environmentName) {
                    $url =  base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/application/" . $applicationUuid;
                }

                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }
            $notRunningDatabases = $databases->pluck('id')->diff($foundDatabases);
            foreach ($notRunningDatabases as $database) {
                $database = $databases->where('id', $database)->first();
                if ($database->status === 'exited') {
                    continue;
                }
                $database->update(['status' => 'exited']);

                $name = data_get($database, 'name');
                $fqdn = data_get($database, 'fqdn');

                $containerName = $name;

                $projectUuid = data_get($database, 'environment.project.uuid');
                $environmentName = data_get($database, 'environment.name');
                $databaseUuid = data_get($database, 'uuid');

                if ($projectUuid && $databaseUuid && $environmentName) {
                    $url = base_url() . '/project/' . $projectUuid . "/" . $environmentName . "/database/" . $databaseUuid;
                }
                $this->server->team->notify(new ContainerStopped($containerName, $this->server, $url));
            }
        } catch (\Throwable $e) {
            send_internal_notification('ContainerStatusJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            handleError($e);
        }
    }
}