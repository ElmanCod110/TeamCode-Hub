<?php

declare(strict_types=1);

namespace TeamCodeHub\Database;

use PDO;

/**
 * Read-only, site-wide operational summaries for the system administration view.
 * Never select credential hashes, invitation tokens, or raw chat/report contents here.
 */
final class AdminRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Return a bounded administrative snapshot suitable for the dashboard.
     *
     * @return array{
     *   metrics:array<string,int>,
     *   recent_users:list<array<string,mixed>>,
     *   recent_teams:list<array<string,mixed>>,
     *   recent_projects:list<array<string,mixed>>
     * }
     */
    public function overview(): array
    {
        // Use separate aggregate queries to avoid row multiplication across unrelated relations.
        $metrics = [
            'total_users' => $this->count('SELECT COUNT(*) FROM users'),
            'active_users' => $this->count("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'pending_users' => $this->count("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
            'active_teams' => $this->count("SELECT COUNT(*) FROM teams WHERE status = 'active'"),
            'active_projects' => $this->count("SELECT COUNT(*) FROM projects WHERE status = 'active'"),
            'open_tasks' => $this->count("SELECT COUNT(*) FROM tasks WHERE status <> 'done'"),
            'overdue_tasks' => $this->count("SELECT COUNT(*) FROM tasks WHERE status <> 'done' AND due_at IS NOT NULL AND due_at < UTC_TIMESTAMP()"),
            'open_reports' => $this->count("SELECT COUNT(*) FROM chat_reports WHERE status IN ('pending', 'valid')"),
            'retained_messages' => $this->count("SELECT COUNT(*) FROM project_messages WHERE expires_at IS NULL OR expires_at > UTC_TIMESTAMP()"),
        ];

        // Keep each feed short and predictable even when the installation grows.
        $recentUsers = $this->pdo->query(
            'SELECT id, email, display_name, status, created_at, last_login_at
             FROM users
             ORDER BY created_at DESC, id DESC
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);

        $recentTeams = $this->pdo->query(
            'SELECT t.id, t.name, t.status, t.created_at, u.display_name AS owner_name
             FROM teams t
             INNER JOIN users u ON u.id = t.created_by
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);

        $recentProjects = $this->pdo->query(
            'SELECT p.id, p.name, p.status, p.created_at, t.name AS team_name, u.display_name AS creator_name
             FROM projects p
             INNER JOIN teams t ON t.id = p.team_id
             INNER JOIN users u ON u.id = p.created_by
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ([$recentUsers, $recentTeams, $recentProjects] as $rows) {
            foreach ($rows as $row) {
                // IDs are normalized below in each dedicated loop.
            }
        }
        foreach ($recentUsers as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        foreach ($recentTeams as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        foreach ($recentProjects as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return [
            'metrics' => $metrics,
            'recent_users' => $recentUsers,
            'recent_teams' => $recentTeams,
            'recent_projects' => $recentProjects,
        ];
    }

    /** Run one fixed aggregate query and normalize its result to an integer. */
    private function count(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
