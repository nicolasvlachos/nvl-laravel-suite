<?php

declare(strict_types=1);

namespace Nvl\Translations\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nvl\Data\Data\PaginatedCollection;
use Nvl\Filterable\Http\QueryFilterSetFactory;
use Nvl\Translations\Actions\Entries\ListTranslationEntriesAction;
use Nvl\Translations\Actions\Entries\ListTranslationFilterOptionsAction;
use Nvl\Translations\Actions\Entries\UpdateTranslationEntryAction;
use Nvl\Translations\Actions\Sync\ExportTranslationsAction;
use Nvl\Translations\Actions\Sync\ImportTranslationsAction;
use Nvl\Translations\Actions\Sync\ScanTranslationsAction;
use Nvl\Translations\Contracts\TranslationsAuthorization;
use Nvl\Translations\Data\TranslationEntryPayload;
use Nvl\Translations\Data\UpdateTranslationEntryPayload;
use Nvl\Translations\Enums\TranslationsAbility;
use Nvl\Translations\Enums\TranslationsResponseCode;
use Nvl\Translations\Http\Requests\ExportTranslationsRequest;
use Nvl\Translations\Http\Requests\ImportTranslationsRequest;
use Nvl\Translations\Http\Requests\TranslationIndexRequest;
use Nvl\Translations\Models\TranslationEntry;

/**
 * Provides canonical JSON endpoints for the Translations management module.
 */
final class TranslationsApiController extends Controller
{
    /**
     * Create the management API controller with its consumer-owned authorization boundary.
     */
    public function __construct(
        private readonly TranslationsAuthorization $authorization,
    ) {}

    /**
     * Return paginated translation entries for API consumers.
     *
     * @param  TranslationIndexRequest  $request  Validated query request
     * @param  ListTranslationEntriesAction  $action  Listing action
     * @return JsonResponse Paginated translation entries
     */
    public function index(
        TranslationIndexRequest $request,
        ListTranslationEntriesAction $action,
        ListTranslationFilterOptionsAction $optionsAction,
        QueryFilterSetFactory $filterFactory,
    ): JsonResponse {
        $this->authorization->authorize(TranslationsAbility::ListEntries);

        $entries = $action->execute(
            perPage: $request->perPage(),
            filters: $filterFactory->fromHttpQuery(
                $request->filterQuery(),
                (new TranslationEntry)->filterSchema(),
            ),
        );

        return response()->json([
            'data' => [
                'entries' => PaginatedCollection::fromPaginator($entries, TranslationEntryPayload::class)->toArray(),
                ...$optionsAction->execute(),
            ],
        ], 200);
    }

    /**
     * Update a single translation entry value.
     *
     * @param  TranslationEntry  $entry  Route-bound translation entry
     * @param  UpdateTranslationEntryPayload  $data  Validated update data
     * @param  UpdateTranslationEntryAction  $action  Update action
     * @return JsonResponse Updated translation entry
     */
    public function update(
        TranslationEntry $entry,
        UpdateTranslationEntryPayload $data,
        UpdateTranslationEntryAction $action,
    ): JsonResponse {
        $this->authorization->authorize(TranslationsAbility::UpdateEntry, $entry);

        $updated = $action->execute($entry, $data);

        return response()->json([
            'data' => TranslationEntryPayload::fromModel($updated)->toArray(),
            'code' => TranslationsResponseCode::Updated->value,
        ], 200);
    }

    /**
     * Trigger translation import from files into the database.
     *
     * @param  ImportTranslationsRequest  $request  Validated import request
     * @param  ImportTranslationsAction  $action  Import action
     * @return JsonResponse Import summary
     */
    public function import(
        ImportTranslationsRequest $request,
        ImportTranslationsAction $action,
    ): JsonResponse {
        $this->authorization->authorize(TranslationsAbility::Synchronize);

        return response()->json([
            'data' => $action->execute(
                $request->scopeTokens(),
                $request->translationFormat(),
                $request->dryRun(),
            ),
            'code' => TranslationsResponseCode::Imported->value,
        ], 200);
    }

    /**
     * Trigger translation export from the database back to files.
     *
     * @param  ExportTranslationsRequest  $request  Validated export request
     * @param  ExportTranslationsAction  $action  Export action
     * @return JsonResponse Export summary
     */
    public function export(
        ExportTranslationsRequest $request,
        ExportTranslationsAction $action,
    ): JsonResponse {
        $this->authorization->authorize(TranslationsAbility::Export);

        if ($request->prune()) {
            $this->authorization->authorize(TranslationsAbility::Prune);
        }

        return response()->json([
            'data' => $action->execute(
                $request->scopeTokens(),
                $request->locales(),
                $request->translationFormat(),
                $request->target(),
                $request->prune(),
                $request->dryRun(),
            ),
            'code' => TranslationsResponseCode::Exported->value,
        ], 200);
    }

    /**
     * Trigger translation usage scanning.
     *
     * @param  ScanTranslationsAction  $action  Scan action
     * @return JsonResponse Scan summary
     */
    public function scan(ScanTranslationsAction $action): JsonResponse
    {
        $this->authorization->authorize(TranslationsAbility::Scan);

        $result = $action->execute();

        return response()->json([
            'data' => [
                'files' => $result['files'],
                'hits' => $result['hits'],
                'scannedAt' => $result['scanned_at']->toIso8601String(),
            ],
            'code' => TranslationsResponseCode::Scanned->value,
        ], 200);
    }
}
