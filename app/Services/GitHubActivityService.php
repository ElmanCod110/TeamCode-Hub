<?php

declare(strict_types=1);

namespace TeamCodeHub\Services;

use Closure;
use RuntimeException;
use TeamCodeHub\Database\GitHubActivityCacheRepository;

final class GitHubActivityService
{
    private readonly string $username;

    /**
     * @param Closure(string):list<array<string,mixed>>|null $testFetcher Optional deterministic transport for tests.
     */
    public function __construct(
        string $username,
        private readonly GitHubActivityCacheRepository $cache,
        private readonly ?Closure $testFetcher = null,
    ) {
        $username = trim($username);
        if (preg_match('/^[A-Za-z0-9-]{1,39}$/', $username) !== 1) {
            throw new RuntimeException('GITHUB_USERNAME must be a valid public GitHub username.');
        }
        $this->username = $username;
    }

    /** @return array<string,mixed> */
    public function activity(): array
    {
        $today = gmdate('Y-m-d');
        $claim = $this->cache->claimDailyAttempt($this->username, $today);
        $snapshot = $claim['snapshot'];

        if ($claim['claimed']) {
            try {
                $events = $this->testFetcher !== null
                    ? ($this->testFetcher)($this->username)
                    : $this->fetchPublicEvents($this->username);
                $events = $this->normalizeEvents($events);
                $fetchedAt = gmdate('Y-m-d H:i:s');
                $this->cache->storeSuccess($this->username, $fetchedAt, $events);
                $snapshot = $this->cache->snapshot();
            } catch (\Throwable $error) {
                // Keep raw transport details in server logs, never in the public JSON response.
                error_log('[TeamCode Hub] GitHub activity refresh failed: ' . $error->getMessage());
                $this->cache->storeFailure($this->username, 'GitHub public activity could not be refreshed.');
                $snapshot = $this->cache->snapshot();
            }
        }

        $fetchedToday = $snapshot['fetched_at'] !== null
            && substr((string) $snapshot['fetched_at'], 0, 10) === $today;
        $available = $snapshot['fetched_at'] !== null;

        return [
            'username' => $this->username,
            'profile_url' => 'https://github.com/' . rawurlencode($this->username),
            'events' => $snapshot['events'],
            'fetched_at' => $snapshot['fetched_at'],
            'attempted_date' => $snapshot['attempted_date'],
            'available' => $available,
            'stale' => $available && !$fetchedToday,
            'refresh_attempted_today' => $snapshot['attempted_date'] === $today,
            'message' => $this->messageFor($snapshot, $today, $available, $fetchedToday),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchPublicEvents(string $username): array
    {
        $url = 'https://api.github.com/users/' . rawurlencode($username) . '/events/public?per_page=30';
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
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $response = @file_get_contents($url, false, $context);
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
            if (!is_string($response)) {
                throw new RuntimeException('GitHub HTTP request failed through the stream transport.');
            }
            $raw = $response;
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('GitHub API returned HTTP ' . $status . '.');
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new RuntimeException('GitHub API returned an unexpected event payload.');
        }
        return $this->normalizeEvents($decoded);
    }

    /**
     * Reduce public event payloads to a small, stable, display-safe contract.
     * @param array<mixed> $events
     * @return list<array<string,mixed>>
     */
    private function normalizeEvents(array $events): array
    {
        $normalized = [];
        foreach (array_slice($events, 0, 30) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $repo = is_array($event['repo'] ?? null) ? (string) ($event['repo']['name'] ?? '') : '';
            if (preg_match('/^[A-Za-z0-9_.-]{1,100}\/[A-Za-z0-9_.-]{1,100}$/', $repo) !== 1) {
                continue;
            }
            $type = is_string($event['type'] ?? null) ? $event['type'] : 'ActivityEvent';
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            $actor = is_array($event['actor'] ?? null) && is_string($event['actor']['login'] ?? null)
                ? substr($event['actor']['login'], 0, 39)
                : $this->username;
            $createdAt = is_string($event['created_at'] ?? null) ? $event['created_at'] : '';
            if ($createdAt !== '' && strtotime($createdAt) === false) {
                $createdAt = '';
            }
            $summary = $this->summarize($type, $payload);
            $normalized[] = [
                'id' => substr((string) ($event['id'] ?? hash('sha256', $repo . $type . $createdAt)), 0, 80),
                'type' => substr($type, 0, 60),
                'repo' => $repo,
                'repo_url' => 'https://github.com/' . $repo,
                'actor' => $actor,
                'created_at' => $createdAt,
                'summary' => substr($summary, 0, 220),
            ];
        }
        return $normalized;
    }

    /** @param array<string,mixed> $payload */
    private function summarize(string $type, array $payload): string
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
        if ($type === 'PushEvent') {
            $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];
            $count = is_numeric($payload['size'] ?? null) ? max(0, (int) $payload['size']) : count($commits);
            $message = is_array($commits[0] ?? null) && is_string($commits[0]['message'] ?? null)
                ? trim($commits[0]['message'])
                : '';
            return 'Pushed ' . $count . ' commit' . ($count === 1 ? '' : 's') . ($message !== '' ? ': ' . $message : '');
        }
        if ($type === 'PullRequestEvent') {
            $title = is_array($payload['pull_request'] ?? null) && is_string($payload['pull_request']['title'] ?? null)
                ? trim($payload['pull_request']['title'])
                : '';
            return trim(($action !== '' ? ucfirst($action) : 'Updated') . ' a pull request' . ($title !== '' ? ': ' . $title : ''));
        }
        if ($type === 'IssuesEvent') {
            $title = is_array($payload['issue'] ?? null) && is_string($payload['issue']['title'] ?? null)
                ? trim($payload['issue']['title'])
                : '';
            return trim(($action !== '' ? ucfirst($action) : 'Updated') . ' an issue' . ($title !== '' ? ': ' . $title : ''));
        }
        if ($type === 'CreateEvent') {
            $refType = is_string($payload['ref_type'] ?? null) ? $payload['ref_type'] : 'ref';
            return 'Created a ' . $refType . (is_string($payload['ref'] ?? null) && $payload['ref'] !== '' ? ': ' . $payload['ref'] : '');
        }
        if ($type === 'DeleteEvent') {
            return 'Deleted a ' . (is_string($payload['ref_type'] ?? null) ? $payload['ref_type'] : 'ref');
        }
        if ($type === 'WatchEvent') {
            return 'Starred this repository';
        }
        if ($type === 'ForkEvent') {
            return 'Forked this repository';
        }
        if ($type === 'ReleaseEvent') {
            $name = is_array($payload['release'] ?? null) && is_string($payload['release']['name'] ?? null)
                ? trim($payload['release']['name'])
                : '';
            return 'Published a release' . ($name !== '' ? ': ' . $name : '');
        }
        if ($type === 'IssueCommentEvent') {
            return ($action !== '' ? ucfirst($action) : 'Added') . ' an issue comment';
        }
        if ($type === 'PullRequestReviewEvent') {
            return ($action !== '' ? ucfirst($action) : 'Submitted') . ' a pull request review';
        }
        $label = preg_replace('/Event$/', '', $type) ?: 'Repository';
        return trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $label) . ' activity');
    }

    /** @param array<string,mixed> $snapshot */
    private function messageFor(array $snapshot, string $today, bool $available, bool $fetchedToday): ?string
    {
        if ($fetchedToday) {
            return null;
        }
        if ($snapshot['last_error'] !== null && $available) {
            return 'GitHub could not refresh today. Showing the last cached public activity.';
        }
        if ($snapshot['last_error'] !== null) {
            return 'GitHub could not refresh today. No cached public activity is available yet.';
        }
        if (!$available && $snapshot['attempted_date'] === $today) {
            return 'The daily GitHub refresh was already attempted, but no snapshot is available yet.';
        }
        return $available ? 'Showing the last cached public activity while the daily refresh is pending.' : 'GitHub activity has not been cached yet.';
    }
}
