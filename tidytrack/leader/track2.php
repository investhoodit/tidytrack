<?php
// Ensure db connection is available. Assumes dp.php initializes $conn (mysqli object).
require_once 'dp.php';

// --- Global Constants ---
// Define the maximum allowed session minutes per day (9 hours)
const MAX_DAILY_MINUTES = 540;
// Define the minimum minutes required for attendance (4 hours)
const ATTENDANCE_MINUTES_REQUIRED = 240;
// ------------------------

// Set MySQLi to throw exceptions on errors, which is generally better than silent failures.
// This is typically done globally in dp.php, but it's good practice to ensure.
// if (defined('MYSQLI_REPORT_OFF')) {
//    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
// }

/**
 * Logs the start of a user session, or confirms an existing one.
 * It prevents creating multiple open sessions (logout_time IS NULL) for the same user on the same day.
 * * @param int $userId The ID of the user.
 */
function log_session_start($userId) {
    global $conn;
    $today = date('Y-m-d');
    
    // 1. Check for existing open session
    $stmt = $conn->prepare("
        SELECT session_id
        FROM user_sessions
        WHERE user_id = ? 
        AND session_date = ? 
        AND logout_time IS NULL
    ");
    
    // Using 'i' for integer (userId), 's' for string (today)
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $existingSession = $result->fetch_assoc();
    $stmt->close();

    if (!$existingSession) {
        // 2. No open session, create new
        $stmt = $conn->prepare("
            INSERT INTO user_sessions (user_id, session_date, login_time, minutes) 
            VALUES (?, ?, NOW(), 0)
        ");
        $stmt->bind_param("is", $userId, $today);
        
        if (!$stmt->execute()) {
            error_log("Failed to log session start for user_id=$userId: " . $stmt->error);
        } else {
            error_log("New session started for user_id=$userId, session_date=$today");
        }
        $stmt->close();
    } else {
        error_log("Existing open session found for user_id=$userId, session_date=$today, session_id={$existingSession['session_id']}");
    }
}

/**
 * Retrieves the total aggregated session minutes for a user on the current day, capped at MAX_DAILY_MINUTES.
 * * @param int $userId The ID of the user.
 * * @return int The total minutes, or 0 if none found.
 */
function get_total_minutes_today($userId) {
    global $conn;
    $today = date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT SUM(minutes) AS total_minutes 
        FROM user_sessions 
        WHERE user_id = ? 
        AND session_date = ?
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Cap at MAX_DAILY_MINUTES
    return min($row['total_minutes'] ?? 0, MAX_DAILY_MINUTES); 
}


/**
 * Updates the minutes of all currently open sessions based on time elapsed since login.
 * This is for real-time display and tracking, allowing the final cap logic in log_session_end to be accurate.
 * * @param int $userId The ID of the user.
 */
function update_current_session($userId) {
    global $conn;
    $today = date('Y-m-d');
    
    // Select all open sessions for today
    $stmt = $conn->prepare("
        SELECT session_id, login_time 
        FROM user_sessions 
        WHERE user_id = ? 
        AND session_date = ? 
        AND logout_time IS NULL
    ");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Calculate minutes based on current time - login time. 
        // NOTE: We calculate the raw elapsed time. The complex daily cap logic is applied in log_session_end.
        $minutes_elapsed = floor((time() - strtotime($row['login_time'])) / 60); 
        
        $updateStmt = $conn->prepare("
            UPDATE user_sessions 
            SET minutes = ? 
            WHERE session_id = ?
        ");
        $updateStmt->bind_param("ii", $minutes_elapsed, $row['session_id']);
        
        if (!$updateStmt->execute()) {
            error_log("Failed to update session_id={$row['session_id']} for user_id=$userId: " . $updateStmt->error);
        } else {
            error_log("Updated session_id={$row['session_id']} for user_id=$userId, minutes=$minutes_elapsed");
        }
        $updateStmt->close();
    }
    $stmt->close();
    
    // FIX: Update attendance after updating session minutes to ensure real-time credit.
    update_attendance($userId);
}

/**
 * Calculates total minutes for the day and updates the attendance log.
 * * @param int $userId The ID of the user.
 */
function update_attendance($userId) {
    global $conn;
    $today = date('Y-m-d');
    
    // Skip attendance on weekends
    if (date('N') >= 6) {
        error_log("Skipping attendance update on weekend for user_id=$userId");
        return;
    }
    
    // 1. Calculate total minutes for the day
    $totalMinutes = get_total_minutes_today($userId); // Uses the dedicated function which handles capping

    // 2. Determine attendance status (using the constant)
    $attendance = ($totalMinutes >= ATTENDANCE_MINUTES_REQUIRED) ? 'Yes' : 'No';
    
    // 3. Insert or update the attendance log
    $stmt = $conn->prepare("
        INSERT INTO attendance_log (user_id, date, attendance) 
        VALUES (?, ?, ?) 
        ON DUPLICATE KEY UPDATE attendance = ?, absence_reason = NULL
    ");
    // Parameters: i (user_id), s (date), s (attendance initial), s (attendance update)
    $stmt->bind_param("isss", $userId, $today, $attendance, $attendance);
    
    if (!$stmt->execute()) {
        error_log("Failed to update attendance for user_id=$userId: " . $stmt->error);
    } else {
        error_log("Updated attendance for user_id=$userId, date=$today, attendance=$attendance, total_minutes=$totalMinutes");
    }
    $stmt->close();
}

/**
 * Closes the current open session, calculating the final minute count 
 * while respecting the 540-minute daily cap.
 * * @param int $userId The ID of the user.
 */
function log_session_end($userId) {
    global $conn;
    $today = date('Y-m-d');
    
    // This query uses the hardcoded '540' literal inside the SQL. 
    // This is retained for stability since the SQL structure is complex.
    $stmt = $conn->prepare("
        UPDATE user_sessions 
        SET logout_time = NOW(), 
            minutes = LEAST(
                TIMESTAMPDIFF(MINUTE, login_time, NOW()), 
                540 - COALESCE(
                    (SELECT SUM(minutes) 
                     FROM user_sessions 
                     WHERE user_id = ? 
                     AND session_date = ? 
                     AND session_id != user_sessions.session_id), 
                    0
                )
            )
        WHERE user_id = ? 
        AND session_date = ? 
        AND logout_time IS NULL
    ");
    
    // Parameters: i (user_id for subquery), s (today for subquery), i (user_id for main query), s (today for main query)
    $stmt->bind_param("isis", $userId, $today, $userId, $today);
    
    if (!$stmt->execute()) {
        error_log("Failed to log session end for user_id=$userId: " . $stmt->error);
    } else {
        error_log("Session ended for user_id=$userId, session_date=$today. Rows affected: " . $stmt->affected_rows);
        
        // FIX: Update attendance immediately after a session is successfully closed.
        if ($stmt->affected_rows > 0) {
            update_attendance($userId);
        }
    }
    $stmt->close();
}
?>
