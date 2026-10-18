<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use Closure;
use RuntimeException;
use TeamCodeHub\Database\GitHubRepositoryCacheRepository;

final class GitHubRepositoryService
{
    /**
     * @param Closure(string):array<string,mixed>|null $testFetcher Deterministic transport used by integration tests.
     */
    public function __construct(
        private readonly GitHubRepositoryCacheRepository $cache,
        private readonly ?Closure $testFetcher = null,
    ) {
    }

    /**
     * Accept only a direct HTTPS URL on github.com; reject redirects, query strings,
     * fragments, alternate hosts, credentials, and path traversal before storing anything.
     */
    public static function parseRepositoryReference(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || strlen($input) > 255 || preg_match('//u', $input) !== 1) {
            return null;
        }
        if (preg_match(
            '~^https://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?)/([A-Za-z0-9_.-]{1,100})(?:\.git)?/?$~i',
            $input,
            $matches
        ) !== 1) {
            return null;
        }

        $repository = preg_replace('/\.git$/i', '', $matches[2]) ?? '';
        if ($repository === '' || $repository === '.' || $repository === '..') {
            return null;
        }

        return strtolower($matches[1] . '/' . $repository);
    }

    /** @return array<string,mixed> */
    public function repository(string $repository, ?string $utcDate = null): array
    {
        $repository = strtolower($repository);
        if (preg_match('~^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?/([A-Za-z0-9_.-]{1,100})$~', $repository) !== 1) {
            throw new RuntimeException('GitHub repository reference is invalid.');
        }
        [$owner, $name] = explode('/', $repository, 2);
        if ($name === '.' || $name === '..' || str_ends_with($name, '.git')) {
            throw new RuntimeException('GitHub repository reference is invalid.');
        }

        $today = $utcDate ?? gmdate('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1
            || gmdate('Y-m-d', strtotime($today . ' UTC')) !== $today) {
            throw new RuntimeException('GitHub repository cache date must use UTC YYYY-MM-DD format.');
        }

        $claim = $this->cache->claimDailyAttempt($repository, $today);
        $snapshot = $claim['snapshot'];
        if ($claim['claimed']) {
            try {
                $raw = $this->testFetcher !== null
                    ? ($this->testFetcher)($repository)
                    : $this->fetchPublicRepository($owner, $name);
                $data = $this->normalizeSnapshot($repository, $raw);
                $this->cache->storeSuccess($repository, gmdate('Y-m-d H:i:s'), $data);
                $snapshot = $this->cache->snapshot($repository);
            } catch (\Throwable $error) {
                if ($this->testFetcher === null) {
                    error_log('[TeamCode Hub] GitHub repository refresh failed: ' . $error->getMessage());
                }
                $this->cache->storeFailure($repository, 'GitHub repository data could not be refreshed.');
                $snapshot = $this->cache->snapshot($repository);
            }
        }

        $available = $snapshot['fetched_at'] !== null && is_array($snapshot['data']);
        $fetchedToday = $snapshot['fetched_at'] !== null
            && substr((string) $snapshot['fetched_at'], 0, 10) === $today;
        $rateLimited = (bool) $claim['rate_limited'];
        $message = $available
            ? ($rateLimited
                ? 'The daily public GitHub refresh budget is exhausted; cached repository data is shown.'
                : ($fetchedToday
                    ? 'Repository data was refreshed today and is shared from cache.'
                    : 'Cached repository data is shown; refresh is limited to one attempt per UTC day.'))
            : ($rateLimited
                ? 'The daily public GitHub refresh budget is exhausted; no repository snapshot is available yet.'
                : 'No repository snapshot is available. The next eligible refresh can retry automatically.');

        return [
            'repository' => $repository,
            'available' => $available,
            'stale' => $available && !$fetchedToday,
            'fetched_at' => $snapshot['fetched_at'],
            'attempted_date' => $snapshot['attempted_date'],
            'refresh_attempted_today' => $snapshot['attempted_date'] === $today,
            'refresh_limited' => $rateLimited,
            'message' => $message,
            'data' => $available ? $this->normalizeSnapshot($repository, $snapshot['data']) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function fetchPublicRepository(string $owner, string $name): array
    {
        $base = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($name);
        $metadata = $this->fetchJson($base);
        if (($metadata['private'] ?? true) !== false) {
            throw new RuntimeException('Only public GitHub repositories can be connected.');
        }
        $commitRows = $this->fetchJson($base . '/commits?per_page=5');
        if (!array_is_list($commitRows)) {
            throw new RuntimeException('GitHub returned an unexpected commit response.');
        }

        $commits = [];
        foreach (array_slice($commitRows, 0, 5) as $row) {
            if (!is_array($row) || !is_array($row['commit'] ?? null)) {
                continue;
            }
            $sha = $row['sha'] ?? null;
            if (!is_string($sha) || preg_match('/^[a-f0-9]{7,64}$/i', $sha) !== 1) {
                continue;
            }
            $message = trim((string) ($row['commit']['message'] ?? ''));
            $author = $row['author']['login'] ?? $row['commit']['author']['name'] ?? 'Unknown contributor';
            $date = $row['commit']['author']['date'] ?? null;
            $commits[] = [
                'sha' => substr($sha, 0, 7),
                'message' => substr(strtok($message, "\n") ?: 'Commit message unavailable', 0, 180),
                'author' => substr(is_string($author) ? $author : 'Unknown contributor', 0, 80),
                'committed_at' => is_string($date) ? substr($date, 0, 40) : null,
            ];
        }

        return [
            'description' => is_string($metadata['description'] ?? null) ? $metadata['description'] : null,
            'stars' => $metadata['stargazers_count'] ?? 0,
            'forks' => $metadata['forks_count'] ?? 0,
            'open_issues' => $metadata['open_issues_count'] ?? 0,
            'default_branch' => $metadata['default_branch'] ?? 'main',
            'language' => $metadata['language'] ?? null,
            'pushed_at' => $metadata['pushed_at'] ?? null,
            'commits' => $commits,
        ];
    }

    /** @return array<string,mixed> */
    private function fetchJson(string $url): array
    {
        $raw = false;
        $status = 0;
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                throw new RuntimeException('Could not initialize the GitHub HTTP client.');
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXFILESIZE => 1000000,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'User-Agent: TeamCode-Hub/0.2.0-dev',
                    'X-GitHub-Api-Version: 2022-11-28',
                ],
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);
            if (!is_string($response)) {
                throw new RuntimeException('GitHub HTTP request failed: ' . ($curlError !== '' ? $curlError : 'unknown transport error'));
            }
            $raw = $response;
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'header' => implode("\r\n", [
                        'Accept: application/vnd.github+json',
                        'User-Agent: TeamCode-Hub/0.2.0-dev',
                        'X-GitHub-Api-Version: 2022-11-28',
                    ]),
                ],
            ]);
            $response = @file_get_contents($url, false, $context);
            $raw = is_string($response) ? $response : false;
            foreach ($http_response_header ?? [] as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
        }

        if (!is_string($raw) || strlen($raw) > 1000000 || $status !== 200) {
            throw new RuntimeException('GitHub public API request returned an unavailable or oversized response.');
        }
        $decoded = json_decode($raw, true, 32);
        if (!is_array($decoded)) {
            throw new RuntimeException('GitHub returned invalid JSON.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $raw
     *  @return array<string,mixed>
     */
    private function normalizeSnapshot(string $repository, array $raw): array
    {
        $commits = [];
        foreach (array_slice(is_array($raw['commits'] ?? null) ? $raw['commits'] : [], 0, 5) as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = $commit['sha'] ?? null;
            if (!is_string($sha) || preg_match('/^[a-f0-9]{7,64}$/i', $sha) !== 1) {
                continue;
            }
            $commits[] = [
                'sha' => substr($sha, 0, 7),
                'message' => substr(trim((string) ($commit['message'] ?? 'Commit message unavailable')), 0, 180),
                'author' => substr(trim((string) ($commit['author'] ?? 'Unknown contributor')), 0, 80),
                'committed_at' => is_string($commit['committed_at'] ?? null) ? substr($commit['committed_at'], 0, 40) : null,
            ];
        }

        $description = $raw['description'] ?? null;
        $language = $raw['language'] ?? null;
        $defaultBranch = $raw['default_branch'] ?? 'main';
        $pushedAt = $raw['pushed_at'] ?? null;

        return [
            'full_name' => $repository,
            'html_url' => 'https://github.com/' . $repository,
            'description' => is_string($description) ? substr(trim($description), 0, 500) : null,
            'stars' => self::nonNegativeInt($raw['stars'] ?? 0),
            'forks' => self::nonNegativeInt($raw['forks'] ?? 0),
            'open_issues' => self::nonNegativeInt($raw['open_issues'] ?? 0),
            'default_branch' => is_string($defaultBranch) ? substr($defaultBranch, 0, 100) : 'main',
            'language' => is_string($language) ? substr($language, 0, 80) : null,
            'pushed_at' => is_string($pushedAt) ? substr($pushedAt, 0, 40) : null,
            'commits' => $commits,
        ];
    }

    private static function nonNegativeInt(mixed $value): int
    {
        return is_numeric($value) ? max(0, min(1000000000, (int) $value)) : 0;
    }
}
