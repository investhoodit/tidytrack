<?php
/*
 * teams_schema.php — shared by managerProjects.php and Project_viewing.php
 *
 * Model (from the BA review, board 2):
 *   - A team can work on many projects, and a project can have many teams  -> project_teams
 *   - A team has members (users)                                            -> team_members
 *   - A task has one project, one workflow status and an OPTIONAL team      -> tasks.team_id (optional column)
 *
 * The three tables are created on first use (CREATE TABLE IF NOT EXISTS is additive and safe to re-run).
 * tasks.team_id is NOT added automatically; see teams_migration.sql. Without it, a task is shown under
 * every team linked to its project.
 */

if (!function_exists('tt_table_exists')) {

  function tt_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
  }

  function tt_column_exists(mysqli $conn, string $table, string $column): bool {
    $stmt = $conn->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $ok;
  }

  function tt_ensure_team_tables(mysqli $conn): void {
    $conn->query("
      CREATE TABLE IF NOT EXISTS teams (
        team_id     INT AUTO_INCREMENT PRIMARY KEY,
        team_name   VARCHAR(120) NOT NULL,
        description VARCHAR(255) NULL,
        created_by  INT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_team_name (team_name)
      )
    ");
    $conn->query("
      CREATE TABLE IF NOT EXISTS team_members (
        team_id INT NOT NULL,
        user_id INT NOT NULL,
        PRIMARY KEY (team_id, user_id),
        KEY idx_tm_user (user_id)
      )
    ");
    $conn->query("
      CREATE TABLE IF NOT EXISTS project_teams (
        project_id INT NOT NULL,
        team_id    INT NOT NULL,
        PRIMARY KEY (project_id, team_id),
        KEY idx_pt_team (team_id)
      )
    ");
  }

  /* Teams with their member names and linked project ids. */
  function tt_fetch_teams(mysqli $conn): array {
    $teams = [];
    $q = $conn->query("SELECT team_id, team_name, description FROM teams ORDER BY team_name ASC");
    while ($r = $q->fetch_assoc()) {
      $r['team_id'] = (int)$r['team_id'];
      $r['members'] = [];
      $r['member_ids'] = [];
      $r['project_ids'] = [];
      $teams[$r['team_id']] = $r;
    }
    if (!$teams) return [];

    $q = $conn->query("
      SELECT tm.team_id, u.user_id, u.name
      FROM team_members tm JOIN users u ON u.user_id = tm.user_id
      ORDER BY u.name
    ");
    while ($r = $q->fetch_assoc()) {
      $tid = (int)$r['team_id'];
      if (!isset($teams[$tid])) continue;
      $teams[$tid]['members'][] = $r['name'];
      $teams[$tid]['member_ids'][] = (int)$r['user_id'];
    }

    $q = $conn->query("SELECT project_id, team_id FROM project_teams");
    while ($r = $q->fetch_assoc()) {
      $tid = (int)$r['team_id'];
      if (isset($teams[$tid])) $teams[$tid]['project_ids'][] = (int)$r['project_id'];
    }
    return array_values($teams);
  }
}
