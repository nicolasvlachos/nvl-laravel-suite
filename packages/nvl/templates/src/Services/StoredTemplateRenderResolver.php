<?php

declare(strict_types=1);

namespace Nvl\Templates\Services;

use Nvl\Content\Content;
use Nvl\Templates\Contracts\TemplatePayloadValidator;
use Nvl\Templates\Data\Mutations\RenderTemplateData;
use Nvl\Templates\Data\TemplateActorData;
use Nvl\Templates\Data\TemplateDefinitionData;
use Nvl\Templates\Enums\TemplateStatus;
use Nvl\Templates\Enums\TemplateVersionStatus;
use Nvl\Templates\Exceptions\TemplateResolutionException;
use Nvl\Templates\Models\Template;
use Nvl\Templates\Models\TemplateAssignment;
use Nvl\Templates\Models\TemplateRender;
use Nvl\Templates\Models\TemplateVersion;
use Nvl\Templates\Rendering\ResolvedStoredTemplateRender;
use Nvl\Templates\Template as RenderableTemplate;

/**
 * Resolves every stored-template invariant into one immutable render plan.
 */
final readonly class StoredTemplateRenderResolver
{
    public function __construct(
        private TemplateContentGuard $guard,
        private TemplateDefinitionRegistry $definitions,
        private TemplatePayloadValidator $payloadValidator,
        private TemplateLocaleResolver $locales,
        private TemplateVersionResolver $versions,
        private Content $content,
        private StoredTemplateOptionsFactory $optionsFactory,
    ) {}

    /**
     * Resolve and validate a stored template without producing output bytes.
     */
    public function resolve(
        Template $template,
        RenderTemplateData $data,
        TemplateActorData $actor,
    ): ResolvedStoredTemplateRender {
        $payload = $this->guard->payload($data->payload);
        $locale = $this->locales->resolve($data->locale);
        $definition = $this->definition($template);

        if ($template->status !== TemplateStatus::Active) {
            throw new TemplateResolutionException(
                "Template [{$template->key}] is not active.",
            );
        }

        if (! in_array($data->profile, $definition->profiles, true)) {
            throw new TemplateResolutionException(
                "Template profile [{$data->profile}] is not registered.",
            );
        }

        $assignment = $this->assignment($template, $data);
        $settings = $this->guard->settings(
            is_array($assignment?->settings) ? $assignment->settings : [],
        );
        $version = $this->versions->forRender($template, $data, $assignment, $actor);

        return $this->makePlan(
            template: $template,
            assignment: $assignment,
            version: $version,
            definition: $definition,
            locale: $locale,
            payload: $payload,
            settings: $settings,
            actor: $actor,
        );
    }

    /**
     * Resolve the exact immutable request captured by a durable render record.
     */
    public function resolveDurable(TemplateRender $render): ResolvedStoredTemplateRender
    {
        $template = $render->template;
        $version = $render->version;
        $payload = $this->guard->payload(is_array($render->payload) ? $render->payload : []);
        $settings = $this->guard->settings(
            is_array($render->settings) ? $render->settings : [],
        );
        $locale = $this->locales->resolve($render->locale);
        $definition = $this->definition($template);

        if ($version->template_id !== $template->id
            || ! in_array($version->status, [
                TemplateVersionStatus::Published,
                TemplateVersionStatus::Retired,
            ], true)) {
            throw new TemplateResolutionException(
                'A durable template render must reference an immutable published version.',
            );
        }

        return $this->makePlan(
            template: $template,
            assignment: $render->assignment,
            version: $version,
            definition: $definition,
            locale: $locale,
            payload: $payload,
            settings: $settings,
            actor: TemplateActorData::system(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $settings
     */
    private function makePlan(
        Template $template,
        ?TemplateAssignment $assignment,
        TemplateVersion $version,
        TemplateDefinitionData $definition,
        string $locale,
        array $payload,
        array $settings,
        TemplateActorData $actor,
    ): ResolvedStoredTemplateRender {
        $this->payloadValidator->validate($definition->schema, $payload);
        $snapshot = $version->content_snapshot;

        if ($snapshot === null) {
            throw new TemplateResolutionException(
                "Template [{$template->key}] version [{$version->version}] has no Content snapshot.",
            );
        }

        if (! hash_equals((string) $version->content_hash, $snapshot->version)
            || $snapshot->ownerType !== TemplateVersion::CONTENT_OWNER_TYPE
            || $snapshot->ownerId !== $version->id
            || $snapshot->group !== TemplateVersion::CONTENT_GROUP) {
            throw new TemplateResolutionException(
                'Template Content snapshot identity or integrity is invalid.',
            );
        }

        $composition = $this->content->renderSnapshot(
            $snapshot,
            $locale,
            $actor->contentActor(),
        );
        $subject = $definition->subjectPath === null
            ? null
            : $composition->value($definition->subjectPath);

        if ($subject !== null && ! is_string($subject)) {
            throw new TemplateResolutionException(
                "Template subject path [{$definition->subjectPath}] must resolve to text.",
            );
        }

        return new ResolvedStoredTemplateRender(
            template: $template,
            assignment: $assignment,
            version: $version,
            renderable: new RenderableTemplate(
                key: $template->key,
                view: $definition->view,
                data: $payload,
                options: $this->optionsFactory->make(
                    renderer: $template->renderer,
                    locale: $locale,
                    subject: $subject,
                    configured: $definition->rendererOptions,
                ),
                composition: $composition,
                schema: $definition->schema,
                settings: $settings,
            ),
            locale: $locale,
            payload: $payload,
        );
    }

    private function definition(Template $template): TemplateDefinitionData
    {
        $definition = $this->definitions->get($template->key);

        if ($template->renderer !== $definition->renderer
            || $template->schema !== $definition->schema) {
            throw new TemplateResolutionException(
                "Template [{$template->key}] is stale; synchronize its source definition.",
            );
        }

        return $definition;
    }

    private function assignment(
        Template $template,
        RenderTemplateData $data,
    ): ?TemplateAssignment {
        if (($data->ownerType === null) !== ($data->ownerId === null)) {
            throw new TemplateResolutionException(
                'Template assignment resolution requires both owner type and owner id.',
            );
        }

        if ($data->ownerType === null || $data->ownerId === null) {
            return null;
        }

        return TemplateAssignment::query()
            ->where('template_id', $template->id)
            ->where('owner_type', $data->ownerType)
            ->where('owner_id', $data->ownerId)
            ->where('profile', $data->profile)
            ->first()
            ?? throw new TemplateResolutionException(
                'No matching template assignment exists for the requested owner and profile.',
            );
    }
}
