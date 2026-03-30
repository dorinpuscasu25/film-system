<?php

use App\Services\ContentSearchService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('search:reindex-content', function (ContentSearchService $contentSearch) {
    $indexed = $contentSearch->reindex();
    $driver = config('search.driver');

    if ($driver !== 'meilisearch') {
        $this->warn("Search driver is set to [{$driver}]. Set SEARCH_DRIVER=meilisearch to build the index.");

        return self::SUCCESS;
    }

    $this->info("Reindexed {$indexed} published titles into [".config('search.indexes.content.uid').'].');

    return self::SUCCESS;
})->purpose('Rebuild the public content index used by storefront search');
