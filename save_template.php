<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = $data['name'];
    $html = $data['html'];

    $db = new SQLite3('database.db');
    $stmt = $db->prepare('INSERT INTO templates (name, html) VALUES (:name, :html)');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $stmt->bindValue(':html', $html, SQLITE3_TEXT);
    $stmt->execute();

    echo json_encode(['status' => 'success']);
}
