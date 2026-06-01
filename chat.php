<?php
// ============================================================
//  chat.php — PHP Inference Engine + Gemini AI Fallback
//  Sri Lanka Travel Chatbot
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { echo json_encode(['error' => 'POST required']); exit; }

require_once 'db.php';
require_once 'config.php';

// ============================================================
//  STEP 1 — Read & validate input
// ============================================================
$input   = json_decode(file_get_contents('php://input'), true);
$raw     = trim($input['message'] ?? '');
$session = trim($input['session_id'] ?? uniqid('sess_'));

if ($raw === '') {
    echo json_encode(['reply' => 'Please type a message!', 'intent' => 'empty']);
    exit;
}

// ============================================================
//  STEP 2 — NLP: Clean, Tokenize, Lemmatize
// ============================================================
function cleanText(string $text): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\w\s]/u', ' ', $text);
    return preg_replace('/\s+/', ' ', trim($text));
}

function tokenize(string $text): array {
    return array_filter(explode(' ', $text), fn($t) => strlen($t) > 1);
}

function removeStopWords(array $tokens): array {
    $stop = ['i','me','my','we','our','you','your','he','she','it','they','them',
        'is','are','was','were','be','been','have','has','had','do','does','did',
        'will','would','could','should','may','might','can','shall',
        'a','an','the','and','or','but','in','on','at','to','for','of','with',
        'about','from','by','this','that','these','those','what','how','when',
        'where','which','who','am','as','so','if','than','then','there','here',
        'please','hello','hi','hey','want','need','know','tell','show','give',
        'looking','like','get','find','help','just','also','any','some',
        'not','no','yes','ok','okay'];
    return array_values(array_diff($tokens, $stop));
}

function lemmatize(array $tokens): array {
    $map = [
        'beaches'=>'beach','hotels'=>'hotel','packages'=>'package','tours'=>'tour',
        'places'=>'place','destinations'=>'destination','activities'=>'activity',
        'visas'=>'visa','costs'=>'cost','prices'=>'price','visiting'=>'visit',
        'traveling'=>'travel','travelling'=>'travel','staying'=>'stay',
        'booking'=>'book','recommendations'=>'recommend','foods'=>'food',
        'restaurants'=>'restaurant','seasons'=>'season','months'=>'month',
        'languages'=>'language','currencies'=>'currency','safaris'=>'safari',
    ];
    return array_map(fn($t) => $map[$t] ?? $t, $tokens);
}

$cleaned = cleanText($raw);
$tokens  = lemmatize(removeStopWords(tokenize($cleaned)));

// ============================================================
//  STEP 3 — Intent Detection
// ============================================================
$intents = [
    'greeting'             => ['hello','hi','hey','morning','afternoon','evening','welcome','start'],
    'farewell'             => ['bye','goodbye','farewell','see','later','exit','quit'],
    'thanks'               => ['thank','thanks','appreciate','grateful'],
    'destination_all'      => ['destination','place','visit','attraction','see','go','explore','tourist'],
    'destination_specific' => ['sigiriya','ella','galle','kandy','mirissa','nuwara','eliya','trincomalee','yala'],
    'package_query'        => ['package','tour','trip','itinerary','day','days','plan','book','holiday'],
    'hotel_query'          => ['hotel','stay','accommodation','resort','guesthouse','lodge','room','sleep','night'],
    'visa_info'            => ['visa','eta','passport','entry','permit','authorization'],
    'best_time'            => ['best','time','season','month','monsoon','weather','climate'],
    'currency'             => ['currency','money','rupee','lkr','exchange','usd','rate','atm','cash'],
    'safety'               => ['safe','safety','crime','danger','dangerous','risk','secure'],
    'transport'            => ['transport','bus','train','tuk','taxi','flight','drive','colombo'],
    'food'                 => ['food','eat','cuisine','restaurant','dish','meal','hoppers','curry'],
    'budget'               => ['cost','budget','price','expensive','cheap','affordable','spend','much'],
    'language'             => ['language','speak','english','sinhala','tamil','communicate'],
    'wildlife'             => ['wildlife','animal','leopard','elephant','bird','safari','jungle','park'],
    'beach'                => ['beach','coast','swim','surf','ocean','sea','sand'],
];

function detectIntent(array $tokens, array $intents): string {
    $scores = [];
    foreach ($intents as $intent => $keywords) {
        $score = count(array_intersect($tokens, $keywords));
        if ($score > 0) $scores[$intent] = $score;
    }
    if (empty($scores)) return 'unknown';
    arsort($scores);
    return array_key_first($scores);
}

$intent = detectIntent($tokens, $intents);

// ============================================================
//  STEP 4 — Gemini API Call
//  Safely checks API key is configured before calling
// ============================================================
function callGemini(string $userMessage, string $apiKey): array {
    // Safety check — key not configured yet
    if (empty($apiKey) || $apiKey === 'YOUR_GEMINI_API_KEY_HERE') {
        return [
            'text'    => "🔑 The AI assistant is not configured yet. Please add your Gemini API key to config.php.\n\nI can still help you with destinations, hotels, packages, visa info and more from my knowledge base!",
            'success' => false,
        ];
    }

    // Check if curl is available
    if (!function_exists('curl_init')) {
        return [
            'text'    => "⚠️ cURL is not enabled on this server. Please enable it in php.ini to use the AI feature.",
            'success' => false,
        ];
    }

    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . GEMINI_MODEL . ":generateContent?key=" . urlencode($apiKey);

    $systemContext = "You are LankaBot, a friendly Sri Lanka travel assistant chatbot. " .
        "Answer questions about Sri Lanka travel, tourism, culture, food, transport, accommodation, " .
        "visa requirements, weather, activities, history and general facts about Sri Lanka. " .
        "Keep answers concise (under 150 words), friendly and helpful. Use emojis occasionally. " .
        "If asked something completely unrelated to Sri Lanka, politely redirect to Sri Lanka topics.";

    $payload = json_encode([
        'contents' => [[
            'parts' => [['text' => $systemContext . "\n\nUser: " . $userMessage]]
        ]],
        'generationConfig' => [
            'temperature'     => 0.7,
            'maxOutputTokens' => 300,
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => CURL_SSL_VERIFY,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // cURL network error
    if ($curlError) {
        return ['text' => "🔄 Couldn't reach the AI service right now. Please check your internet connection and try again!", 'success' => false];
    }

    // HTTP errors from Gemini
    if ($httpCode === 400) {
        return ['text' => "⚠️ Invalid API request. Please check your Gemini API key in config.php.", 'success' => false];
    }
    if ($httpCode === 403) {
        return ['text' => "🔑 Your Gemini API key seems invalid or expired. Please check config.php and get a new key at aistudio.google.com.", 'success' => false];
    }
    if ($httpCode === 429) {
        return ['text' => "⏳ Too many requests! The AI is a bit busy right now. Please wait a moment and try again.", 'success' => false];
    }
    if ($httpCode !== 200) {
        return ['text' => "😕 The AI service returned an error (HTTP $httpCode). Please try again shortly.", 'success' => false];
    }

    $data = json_decode($response, true);

    // Parse Gemini response
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['text' => "🤔 I got a response but couldn't read it. Please try rephrasing your question!", 'success' => false];
    }

    return ['text' => trim($text), 'success' => true];
}

// ============================================================
//  STEP 5 — Generate Response
// ============================================================
$db     = getDB();
$reply  = '';
$usedAI = false;

function rnd(array $arr): string { return $arr[array_rand($arr)]; }

function searchFAQ(PDO $db, string $keyword): string|false {
    $stmt = $db->prepare("SELECT answer FROM faqs WHERE keywords LIKE ? LIMIT 1");
    $stmt->execute(["%$keyword%"]);
    $row = $stmt->fetch();
    return $row ? $row['answer'] : false;
}

function searchLearnedQA(PDO $db, string $cleaned): string|false {
    $words = array_filter(explode(' ', $cleaned), fn($w) => strlen($w) > 3);
    if (empty($words)) return false;
    $cond   = implode(' OR ', array_fill(0, count($words), 'question LIKE ? OR keywords LIKE ?'));
    $params = [];
    foreach ($words as $w) { $params[] = "%$w%"; $params[] = "%$w%"; }
    $stmt = $db->prepare("SELECT answer FROM learned_qa WHERE $cond ORDER BY use_count DESC LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();
    if ($row) {
        $db->prepare("UPDATE learned_qa SET use_count = use_count + 1 WHERE answer = ? LIMIT 1")->execute([$row['answer']]);
    }
    return $row ? $row['answer'] : false;
}

switch ($intent) {

    case 'greeting':
        $reply = rnd([
            "Ayubowan! 🌺 Welcome to the Sri Lanka Travel Assistant!\n\nI can help you with:\n• 🗺️ Destinations (Sigiriya, Ella, Galle...)\n• 🧳 Tour packages\n• 🏨 Hotel recommendations\n• 🛂 Visa & travel info\n• 💰 Budget planning\n\nPowered by Google Gemini AI ✨ — ask me anything!",
            "Hello! 🌴 Ready to discover Sri Lanka? Ask me about destinations, packages, hotels, or anything travel-related!",
        ]);
        break;

    case 'farewell':
        $reply = rnd([
            "Thank you for using the Sri Lanka Travel Assistant! Have a wonderful trip. Ayubowan! 🌺",
            "Goodbye! Sri Lanka awaits you. Safe travels! ✈️🌴",
        ]);
        break;

    case 'thanks':
        $reply = rnd([
            "You are most welcome! 😊 Is there anything else I can help you with?",
            "Happy to help! Feel free to ask anything else about Sri Lanka! 🌴",
        ]);
        break;

    case 'destination_all':
        $stmt = $db->query("SELECT name, region, best_season FROM destinations ORDER BY name");
        $rows = $stmt->fetchAll();
        $list = '';
        foreach ($rows as $r) {
            $list .= "• {$r['name']} ({$r['region']}) — Best: {$r['best_season']}\n";
        }
        $reply = "🗺️ Top destinations in Sri Lanka:\n\n$list\nAsk me about any destination for full details!";
        break;

    case 'destination_specific':
        $destNames = ['sigiriya','ella','galle','kandy','mirissa','nuwara eliya','trincomalee','yala'];
        $found = null;
        foreach ($destNames as $d) {
            if (str_contains($cleaned, $d)) { $found = $d; break; }
        }
        if ($found) {
            $stmt = $db->prepare("SELECT * FROM destinations WHERE LOWER(name) LIKE ? LIMIT 1");
            $stmt->execute(["%$found%"]);
            $dest = $stmt->fetch();
            if ($dest) {
                $reply  = "📍 *{$dest['name']}* — {$dest['region']}\n\n{$dest['description']}\n\n";
                $reply .= "⭐ Highlights: {$dest['highlights']}\n";
                $reply .= "📅 Best time: {$dest['best_season']}\n";
                $reply .= "🎟️ Entry fee: {$dest['entry_fee']}\n\n";
                $reply .= "Would you like hotels or packages that include {$dest['name']}?";
            }
        } else {
            $reply = "Which destination are you curious about? I know Sigiriya, Ella, Galle, Kandy, Mirissa, Nuwara Eliya, Trincomalee, and Yala!";
        }
        break;

    case 'package_query':
        $stmt = $db->query("SELECT * FROM packages_summary ORDER BY price_usd ASC");
        $rows = $stmt->fetchAll();
        $list = '';
        foreach ($rows as $r) {
            $list .= "🧳 *{$r['name']}* — {$r['duration_days']} days | USD {$r['price_usd']}\n";
            $list .= "   {$r['destinations']}\n\n";
        }
        $reply = "✈️ Our Sri Lanka tour packages:\n\n$list\nAsk about any package for full details!";
        break;

    case 'hotel_query':
        $destKeyword = null;
        foreach (['sigiriya','ella','galle','kandy','mirissa','nuwara eliya','trincomalee','yala'] as $d) {
            if (str_contains($cleaned, $d)) { $destKeyword = $d; break; }
        }
        if ($destKeyword) {
            $stmt = $db->prepare("SELECT * FROM hotel_with_destination WHERE LOWER(destination) LIKE ? ORDER BY stars DESC");
            $stmt->execute(["%$destKeyword%"]);
        } else {
            $stmt = $db->query("SELECT * FROM hotel_with_destination ORDER BY destination, stars DESC");
        }
        $rows = $stmt->fetchAll();
        $list = '';
        foreach ($rows as $r) {
            $stars = str_repeat('★', $r['stars']) . str_repeat('☆', 5 - $r['stars']);
            $list .= "🏨 *{$r['hotel_name']}* — {$r['destination']} $stars — USD {$r['price_per_night']}/night\n";
        }
        $loc   = $destKeyword ? ucfirst($destKeyword) : 'Sri Lanka';
        $reply = "🏨 Hotels in $loc:\n\n$list\nWould you like more details about any hotel?";
        break;

    case 'wildlife':
        $stmt = $db->prepare("SELECT * FROM destinations WHERE LOWER(name) LIKE '%yala%' LIMIT 1");
        $stmt->execute();
        $dest = $stmt->fetch();
        if ($dest) {
            $reply  = "🐆 *Yala National Park*\n\n{$dest['description']}\n\n";
            $reply .= "⭐ {$dest['highlights']}\n📅 Best: {$dest['best_season']}\n🎟️ {$dest['entry_fee']}\n\n";
            $reply .= "We have a Wildlife Safari Adventure package — want details?";
        } else {
            $ai    = callGemini($raw, GEMINI_API_KEY);
            $reply = $ai['text'];
            $usedAI = $ai['success'];
        }
        break;

    case 'beach':
        $stmt = $db->query("SELECT name, region, best_season FROM destinations WHERE region LIKE '%Southern%' OR name = 'Trincomalee'");
        $rows = $stmt->fetchAll();
        $list = '';
        foreach ($rows as $r) { $list .= "🏖️ *{$r['name']}* — Best: {$r['best_season']}\n"; }
        $reply  = "🌊 Best beaches in Sri Lanka:\n\n$list\n";
        $reply .= "South coast (Mirissa, Galle): Nov–Apr ☀️\nEast coast (Trincomalee): Apr–Sep ☀️\n\n";
        $reply .= "Want hotel or package recommendations?";
        break;

    case 'visa_info': case 'best_time': case 'currency':
    case 'safety':    case 'transport': case 'food':
    case 'budget':    case 'language':
        $kwMap = [
            'visa_info'=>'visa','best_time'=>'best time','currency'=>'currency',
            'safety'=>'safe','transport'=>'transport','food'=>'food',
            'budget'=>'cost','language'=>'language',
        ];
        $faq = searchFAQ($db, $kwMap[$intent]);
        if ($faq) {
            $reply = $faq;
        } else {
            $ai     = callGemini($raw, GEMINI_API_KEY);
            $reply  = $ai['text'];
            $usedAI = $ai['success'];
            $intent = $ai['success'] ? 'gemini_ai' : $intent;
        }
        break;

    default:
        // 1. Try FAQ table
        $faq = searchFAQ($db, $cleaned);
        if ($faq) { $reply = $faq; $intent = 'faq_match'; break; }

        // 2. Try learned_qa
        $learned = searchLearnedQA($db, $cleaned);
        if ($learned) { $reply = $learned; $intent = 'learned_match'; break; }

        // 3. Call Gemini as final fallback
        $ai     = callGemini($raw, GEMINI_API_KEY);
        $reply  = $ai['text'];
        $usedAI = $ai['success'];
        $intent = $ai['success'] ? 'gemini_ai' : 'unknown';
        break;
}

// ============================================================
//  STEP 6 — Log & return
// ============================================================
try {
    $db->prepare("INSERT INTO chat_logs (session_id, user_message, bot_response, intent) VALUES (?,?,?,?)")
       ->execute([$session, $raw, $reply, $intent]);
} catch (Exception $e) { /* non-fatal */ }

echo json_encode([
    'reply'      => $reply,
    'intent'     => $intent,
    'used_ai'    => $usedAI,
    'session_id' => $session,
]);