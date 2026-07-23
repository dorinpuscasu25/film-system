<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class MediaUrlMigrationService
{
    /**
     * Columns that may contain uploaded media URLs, including URLs nested in
     * JSON or HTML content.
     *
     * @var array<string, list<string>>
     */
    private const TABLE_COLUMNS = [
        'contents' => [
            'poster_url',
            'backdrop_url',
            'hero_desktop_url',
            'hero_mobile_url',
            'preview_images',
            'cast_members',
            'crew_members',
            'videos',
            'seasons',
            'meta',
        ],
        'home_page_sections' => [
            'hero_slides',
            'meta',
        ],
        'users' => [
            'avatar_url',
        ],
        'cms_pages' => [
            'excerpt',
            'content',
        ],
        'ad_creatives' => [
            'media_url',
        ],
        'subtitle_tracks' => [
            'file_url',
        ],
        'content_formats' => [
            'stream_url',
        ],
    ];

    /**
     * Replace a legacy media origin without changing object paths.
     *
     * The command scans raw database values so URLs embedded in JSON and HTML
     * are migrated without triggering model events or changing JSON structure.
     *
     * @return array{
     *     records: int,
     *     urls: int,
     *     by_table: array<string, array{records: int, urls: int}>
     * }
     */
    public function migrate(string $from, string $to, bool $apply = false): array
    {
        $from = $this->normalizeBaseUrl($from, 'Source');
        $to = $this->normalizeBaseUrl($to, 'Destination');

        if ($from === $to) {
            throw new InvalidArgumentException('Source and destination CDN URLs must be different.');
        }

        $run = fn (): array => $this->scanAndReplace($from, $to, $apply);

        return $apply ? DB::transaction($run) : $run();
    }

    /**
     * @return array{
     *     records: int,
     *     urls: int,
     *     by_table: array<string, array{records: int, urls: int}>
     * }
     */
    private function scanAndReplace(string $from, string $to, bool $apply): array
    {
        $result = [
            'records' => 0,
            'urls' => 0,
            'by_table' => [],
        ];

        foreach (self::TABLE_COLUMNS as $table => $configuredColumns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $existingColumns = Schema::getColumnListing($table);
            $columns = array_values(array_intersect($configuredColumns, $existingColumns));

            if ($columns === []) {
                continue;
            }

            $tableStats = ['records' => 0, 'urls' => 0];

            DB::table($table)
                ->select(array_merge(['id'], $columns))
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    $table,
                    $columns,
                    $from,
                    $to,
                    $apply,
                    &$tableStats,
                ): void {
                    foreach ($rows as $row) {
                        $updates = [];
                        $recordUrlCount = 0;

                        foreach ($columns as $column) {
                            $value = $row->{$column};

                            if (! is_string($value)) {
                                continue;
                            }

                            [$replaced, $columnUrlCount] = $this->replaceStoredValue(
                                $value,
                                $from,
                                $to,
                            );

                            if ($columnUrlCount > 0) {
                                $updates[$column] = $replaced;
                                $recordUrlCount += $columnUrlCount;
                            }
                        }

                        if ($updates === []) {
                            continue;
                        }

                        $tableStats['records']++;
                        $tableStats['urls'] += $recordUrlCount;

                        if ($apply) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                }, 'id');

            if ($tableStats['records'] > 0) {
                $result['by_table'][$table] = $tableStats;
                $result['records'] += $tableStats['records'];
                $result['urls'] += $tableStats['urls'];
            }
        }

        return $result;
    }

    /**
     * JSON strings can store slashes either escaped or unescaped depending on
     * the database driver and the code that originally encoded the value.
     *
     * @return array{string, int}
     */
    private function replaceStoredValue(string $value, string $from, string $to): array
    {
        $count = 0;
        $value = str_replace($from, $to, $value, $plainCount);
        $count += $plainCount;

        $escapedFrom = str_replace('/', '\\/', $from);

        if ($escapedFrom !== $from) {
            $escapedTo = str_replace('/', '\\/', $to);
            $value = str_replace($escapedFrom, $escapedTo, $value, $escapedCount);
            $count += $escapedCount;
        }

        return [$value, $count];
    }

    private function normalizeBaseUrl(string $url, string $label): string
    {
        $url = rtrim(trim($url), '/');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new InvalidArgumentException("{$label} CDN URL must be a valid absolute http(s) URL.");
        }

        return $url;
    }
}
