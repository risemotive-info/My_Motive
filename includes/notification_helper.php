<?php

function notify($conn, $title, $message)
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    $userId = $_SESSION['user_id'];

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications
        (user_id, title, message)
        VALUES (?, ?, ?)"
    );

    mysqli_stmt_bind_param(
        $stmt,
        "iss",
        $userId,
        $title,
        $message
    );

    mysqli_stmt_execute($stmt);
}
// add to notification_helper.php
function notifyUser($conn, $userId, $title, $message)
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $title, $message);
    mysqli_stmt_execute($stmt);
}