<?php

declare(strict_types=1);

namespace Nvl\Templates\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mpdf\Mpdf;
use Nvl\Content\Content;
use Nvl\Templates\Contracts\TemplateAuthorization;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Services\PdfTemporaryDirectoryResolver;
use Nvl\Templates\Services\TemplateDefinitionRegistry;
use Nvl\Templates\Services\TemplateOwnerRegistry;
use Nvl\Templates\Services\TemplateRendererRegistry;
use Nvl\Templates\Support\TemplatesConfiguration;

/**
 * Performs non-mutating core, database, route, and queue diagnostics.
 */
final class TemplatesDoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:templates:doctor
        {--strict : Return failure when a required check is unhealthy}
        {--scope=all : Inspect core, database, or all capabilities}
        {--format=text : Output text or json}';

    /** @var string */
    protected $description = 'Inspect the NVL Templates installation without changing state';

    /**
     * Inspect the requested package capabilities without mutating them.
     */
    public function handle(
        TemplateAuthorization $authorization,
        TemplateDefinitionRegistry $definitions,
        TemplateRendererRegistry $renderers,
        TemplateOwnerRegistry $owners,
        PdfTemporaryDirectoryResolver $temporaryDirectories,
        Factory $views,
        Content $content,
    ): int {
        $format = $this->option('format');
        $scope = $this->option('scope');

        if (! is_string($format) || ! in_array($format, ['text', 'json'], true)) {
            throw new InvalidArgumentException(
                'The nvl:templates:doctor format must be text or json.',
            );
        }

        if (! is_string($scope) || ! in_array($scope, ['all', 'core', 'database'], true)) {
            throw new InvalidArgumentException(
                'The nvl:templates:doctor scope must be all, core, or database.',
            );
        }

        $required = [];
        $registeredRenderers = array_keys($renderers->all());

        if ($scope === 'all' || $scope === 'core') {
            $required = [
                ...$required,
                ...$this->coreChecks(
                    $definitions,
                    $registeredRenderers,
                    $temporaryDirectories,
                    $views,
                ),
            ];
        }

        if ($scope === 'all' || $scope === 'database') {
            $required = [
                ...$required,
                ...$this->databaseChecks($definitions, $content),
            ];
        }

        $checks = [
            'scope' => $scope,
            ...$required,
            'authorization' => $authorization::class,
            'renderers' => $registeredRenderers,
            'definitions' => array_keys($definitions->all()),
            'owners' => $owners->aliases(),
            'management_routes' => (bool) config(
                'templates.routes.management.enabled',
                false,
            ),
            'render_routes' => (bool) config(
                'templates.routes.render.enabled',
                false,
            ),
            'queue' => config('templates.rendering.queue'),
            'pdf.version' => Mpdf::VERSION,
            'pdf.remote_assets' => (bool) config(
                'templates.pdf.remote_assets.enabled',
                false,
            ),
        ];
        $healthy = ! in_array(false, $required, true);
        $checks['healthy'] = $healthy;

        if ($format === 'json') {
            $this->line((string) json_encode(
                $checks,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ));
        } else {
            foreach ($checks as $check => $value) {
                $this->line(sprintf(
                    '%-34s %s',
                    $check,
                    json_encode($value, JSON_THROW_ON_ERROR),
                ));
            }
        }

        return $healthy || ! $this->option('strict')
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param  list<string>  $registeredRenderers
     * @return array<string, bool>
     */
    private function coreChecks(
        TemplateDefinitionRegistry $definitions,
        array $registeredRenderers,
        PdfTemporaryDirectoryResolver $temporaryDirectories,
        Factory $views,
    ): array {
        $defaultRenderer = config('templates.default_renderer', 'blade');

        return [
            'renderer.default' => is_string($defaultRenderer)
                && in_array($defaultRenderer, $registeredRenderers, true),
            'renderer.blade' => in_array('blade', $registeredRenderers, true),
            'renderer.pdf' => in_array('pdf', $registeredRenderers, true),
            'view.default.blade' => $this->configuredDefaultViewExists($views, 'blade'),
            'view.default.pdf' => $this->configuredDefaultViewExists($views, 'pdf'),
            'views.definitions' => $this->definitionViewsExist($definitions, $views),
            'pdf.temp_path' => $temporaryDirectories->isSafe(),
            'limits' => $this->limitsAreValid(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function databaseChecks(
        TemplateDefinitionRegistry $definitions,
        Content $content,
    ): array {
        $schema = Schema::connection(TemplatesConfiguration::connection());
        $checks = [];

        foreach ([
            'templates',
            'templates_i18n',
            'template_versions',
            'template_assignments',
            'template_renders',
        ] as $key) {
            $table = TemplatesConfiguration::table($key);
            $checks["table.{$key}"] = $schema->hasTable($table);
        }

        $renderTable = TemplatesConfiguration::table('template_renders');
        $checks['columns.template_renders.durable'] = $checks['table.template_renders']
            && $schema->hasColumns($renderTable, [
                'processing_token',
                'lease_expires_at',
                'failed_at',
                'profile',
                'settings',
                'dispatch_generation',
            ]);
        $tablesHealthy = ! in_array(false, $checks, true);
        $checks['definitions.synchronized'] = $tablesHealthy
            && $this->definitionsAreSynchronized($definitions);
        $checks['definitions.content'] = $this->contentDefinitionsAreRegistered(
            $definitions,
            $content,
        );
        $checks['queue.configuration'] = $this->queueConfigurationIsValid();
        $checks['queue.retry_after'] = $this->queueRetryAfterIsSafe();
        $checks['output.disk'] = $this->outputDiskIsConfigured();

        return $checks;
    }

    private function limitsAreValid(): bool
    {
        foreach ([
            'schema_bytes',
            'schema_depth',
            'schema_items',
            'data_bytes',
            'data_depth',
            'data_items',
            'renderer_options_bytes',
            'renderer_options_depth',
            'renderer_options_items',
            'payload_bytes',
            'payload_depth',
            'payload_items',
            'settings_bytes',
            'metadata_bytes',
            'per_page',
            'maximum_per_page',
            'output_bytes',
        ] as $key) {
            $value = config("templates.limits.{$key}");

            if (! is_int($value) || $value < 1) {
                return false;
            }
        }

        $perPage = config('templates.limits.per_page');
        $maximumPerPage = config('templates.limits.maximum_per_page');

        return is_int($perPage)
            && is_int($maximumPerPage)
            && $perPage <= $maximumPerPage;
    }

    private function configuredDefaultViewExists(
        Factory $views,
        string $renderer,
    ): bool {
        $view = config("templates.views.defaults.{$renderer}");

        return is_string($view) && $views->exists($view);
    }

    private function definitionViewsExist(
        TemplateDefinitionRegistry $definitions,
        Factory $views,
    ): bool {
        foreach ($definitions->all() as $definition) {
            if (! $views->exists($definition->view)) {
                return false;
            }
        }

        return true;
    }

    private function contentDefinitionsAreRegistered(
        TemplateDefinitionRegistry $definitions,
        Content $content,
    ): bool {
        $available = $content->definitions(
            TemplateActorData::system()->contentActor(),
        )->pluck('key')->all();

        foreach ($definitions->all() as $definition) {
            foreach ($definition->allowedContentDefinitions as $key) {
                if (! in_array($key, $available, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function definitionsAreSynchronized(
        TemplateDefinitionRegistry $definitions,
    ): bool {
        $registered = $definitions->all();
        $stored = Template::query()->get()->keyBy('key');

        foreach ($registered as $key => $definition) {
            $template = $stored->get($key);

            if (! $template instanceof Template
                || $template->renderer !== $definition->renderer
                || $template->schema !== $definition->schema
                || $template->status === TemplateStatus::Archived) {
                return false;
            }
        }

        foreach ($stored as $key => $template) {
            if (! array_key_exists((string) $key, $registered)
                && $template->status !== TemplateStatus::Archived) {
                return false;
            }
        }

        return true;
    }

    private function queueConfigurationIsValid(): bool
    {
        $tries = config('templates.rendering.tries');
        $timeout = config('templates.rendering.timeout');
        $lease = config('templates.rendering.lease_seconds');
        $uniqueFor = config('templates.rendering.unique_for');
        $pendingRecovery = config('templates.rendering.pending_recovery_seconds');
        $batchSize = config('templates.rendering.recovery_batch_size');
        $backoff = config('templates.rendering.backoff');

        if (! is_int($tries)
            || $tries < 1
            || ! is_int($timeout)
            || $timeout < 1
            || ! is_int($lease)
            || $lease <= $timeout
            || ! is_int($uniqueFor)
            || $uniqueFor < $lease
            || ! is_int($pendingRecovery)
            || $pendingRecovery <= $uniqueFor
            || ! is_int($batchSize)
            || $batchSize < 1
            || ! is_array($backoff)
            || $backoff === []) {
            return false;
        }

        foreach ($backoff as $delay) {
            if (! is_int($delay) || $delay < 1) {
                return false;
            }
        }

        return true;
    }

    private function queueRetryAfterIsSafe(): bool
    {
        $connection = config('templates.rendering.connection')
            ?? config('queue.default');

        if (! is_string($connection) || $connection === '') {
            return false;
        }

        $retryAfter = config("queue.connections.{$connection}.retry_after");
        $timeout = config('templates.rendering.timeout');

        return $retryAfter === null
            || (is_int($retryAfter)
                && is_int($timeout)
                && $retryAfter > $timeout);
    }

    private function outputDiskIsConfigured(): bool
    {
        if (! (bool) config('templates.rendering.output.persist', true)) {
            return true;
        }

        $disk = config('templates.rendering.output.disk');

        return is_string($disk)
            && $disk !== ''
            && is_array(config("filesystems.disks.{$disk}"));
    }
}
