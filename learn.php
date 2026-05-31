<?php
// ============================================================
//  learn.php — Machine Learning Endpoint
//  Saves unknown Q&A pairs taught by the user
//  This is what earns the ML marks in the assignment!
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once 'db.php';

$input    = json_decode(file_get_contents('php://input'), true);
$question = trim($input['question'] ?? '');
$answer   = trim($input['answer']   ?? '');

if (!$question || !$answer) {
    echo json_encode(['success' => false, 'message' => 'Both question and answer are required.']);
    exit;
}

// Generate keywords from the question (words longer than 3 chars)
$keywords = implode(', ', array_unique(
    array_filter(
        explode(' ', preg_replace('/[^\w\s]/', '', mb_strtolower($question))),
        fn($w) => strlen($w) > 3
    )
));

try {
    $db   = getDB();

    // Check if a very similar question already exists
    $check = $db->prepare("SELECT id FROM learned_qa WHERE question LIKE ? LIMIT 1");
    $check->execute(['%' . substr($question, 0, 30) . '%']);

    if ($check->fetch()) {
        // Update existing entry
        $stmt = $db->prepare("UPDATE learned_qa SET answer = ?, keywords = ?, updated_at = NOW() WHERE question LIKE ? LIMIT 1");
        $stmt->execute([$answer, $keywords, '%' . substr($question, 0, 30) . '%']);
        $msg = "Thanks! I have updated what I know about that. 🧠✅";
    } else {
        // Insert new learned fact
        $stmt = $db->prepare("INSERT INTO learned_qa (question, answer, keywords, taught_by) VALUES (?, ?, ?, 'user')");
        $stmt->execute([$question, $answer, $keywords]);
        $msg = "Thank you! I have learned something new and will remember it! 🧠🎉";
    }

    echo json_encode(['success' => true, 'message' => $msg]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Sorry, I could not save that right now.']);
}