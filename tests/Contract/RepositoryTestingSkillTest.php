<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('ships a valid repository testing-practices skill with resolvable resources', function (): void {
    $root = dirname(__DIR__, 2);
    $skillRoot = $root.'/.agents/skills/testing-practices-and-optimization';
    $skillContents = file_get_contents($skillRoot.'/SKILL.md');
    $metadataContents = file_get_contents($skillRoot.'/agents/openai.yaml');

    expect($skillContents)->toBeString()->not->toBeEmpty()
        ->and($metadataContents)->toBeString()->not->toBeEmpty();

    $frontmatterBoundary = strpos($skillContents, "\n---\n", 4);

    expect($skillContents)->toStartWith("---\n")
        ->and($frontmatterBoundary)->toBeInt();

    $frontmatter = Yaml::parse(substr(
        $skillContents,
        4,
        $frontmatterBoundary - 4,
    ));
    $metadata = Yaml::parse($metadataContents);

    expect($frontmatter)->toBeArray()
        ->and($frontmatter['name'] ?? null)
        ->toBe('testing-practices-and-optimization')
        ->and($frontmatter['description'] ?? null)->toBeString()->not->toBeEmpty()
        ->and($metadata)->toBeArray()
        ->and($metadata['interface']['display_name'] ?? null)
        ->toBe('Testing Practices & Optimization')
        ->and($metadata['interface']['default_prompt'] ?? null)
        ->toContain('$testing-practices-and-optimization');

    foreach (
        [
            'references/layer-ownership.md',
            'references/ci-coverage-performance.md',
        ] as $reference
    ) {
        expect($skillContents)->toContain("({$reference})")
            ->and($skillRoot.'/'.$reference)->toBeFile();
    }
});
