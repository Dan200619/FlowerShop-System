<?php

function recordActivity(
    mysqli $connection,
    string $eventType,
    string $description,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    $actorUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])
        ? (int) $_SESSION['user_id']
        : null;
    $actorName = isset($_SESSION['full_name']) && is_string($_SESSION['full_name'])
        ? trim($_SESSION['full_name'])
        : '';
    if ($actorName === '' && isset($_SESSION['username']) && is_string($_SESSION['username'])) {
        $actorName = $_SESSION['username'];
    }
    if ($actorName === '') {
        $actorName = 'Unknown user';
    }
    $actorName = mb_substr($actorName, 0, 150);
    $description = mb_substr($description, 0, 500);

    $statement = mysqli_prepare(
        $connection,
        'INSERT INTO tblactivitylog (ActorUserID, ActorName, EventType, Description, EntityType, EntityID)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    if ($statement === false) {
        error_log('Unable to prepare activity log entry: ' . mysqli_error($connection));
        return;
    }

    if (!mysqli_stmt_bind_param($statement, 'issssi', $actorUserId, $actorName, $eventType, $description, $entityType, $entityId)
        || !mysqli_stmt_execute($statement)
    ) {
        error_log('Unable to record activity log entry: ' . mysqli_stmt_error($statement));
    }
    mysqli_stmt_close($statement);
}
