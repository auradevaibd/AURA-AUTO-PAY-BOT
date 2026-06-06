<?php
/**
 * AURA-PAY PAYMENT GATEWAY CORE SYSTEM
 * ------------------------------------
 * Updated: Manual Transaction Adder (Target Key Support)
 */

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); 
date_default_timezone_set("Asia/Dhaka");

define('DB_KEYS', 'keys.json');
define('DB_MSGS', 'messages.json');
define('DB_LOGS', 'logs.json');

initSystem();

// --- ইনপুট প্যারামিটার ---
$key        = $_GET['key']        ?? null; 
$message    = $_GET['message']    ?? null; 
$txnid      = $_GET['txnid']      ?? null; 
$keyadd     = $_GET['keyadd']     ?? null; 
$limit      = $_GET['limit']      ?? null;
$admin_pass = $_GET['admin_pass'] ?? null; // নতুন প্যারামিটার
$target_key = $_GET['target_key'] ?? null; // নতুন প্যারামিটার
$new_trx    = $_GET['new_trx']    ?? null; // নতুন প্যারামিটার
$new_amt    = $_GET['new_amt']    ?? null; // নতুন প্যারামিটার

saveActivityLog($_SERVER);

/* ==========================================================
   নতুন ফিচার: ম্যানুয়াল ট্রানজেকশন অ্যাড (ড্যাশবোর্ড এর জন্য)
   ========================================================== */
if ($admin_pass === "MDTOUHID01" && $new_trx && $new_amt) {
    // যদি target_key না থাকে, তবে বর্তমান $key ব্যবহার করবে
    $final_key = $target_key ?? $key;
    
    if (!$final_key) {
        sendResponse("error", "Target Key is missing");
    }

    $trxid = strtoupper(trim($new_trx));
    $allMsgs = json_decode(file_get_contents(DB_MSGS), true);
    
    $allMsgs[$final_key][$trxid] = [
        "TrxID" => $trxid,
        "Tk" => number_format((float)$new_amt, 2, '.', ''),
        "Service" => "manual_admin",
        "Time" => date("Y-m-d H:i:s"),
        "Raw" => "Manually Added via Dashboard"
    ];
    
    file_put_contents(DB_MSGS, json_encode($allMsgs, JSON_PRETTY_PRINT));
    sendResponse("success", ["message" => "Transaction Added Successfully", "target_key" => $final_key, "trxid" => $trxid]);
}

// --- আগের গেটওয়ে লজিক (অপরিবর্তিত) ---

if ($keyadd && $limit !== null) {
    registerMerchant($keyadd, $limit);
}

if ($key && $message) {
    processSmsFromApp($key, $message);
}

if ($key && $txnid) {
    verifyTransaction($key, $txnid);
}

if ($key && !$txnid && !$message && !$admin_pass) {
    getDashboardData($key);
}

sendResponse("error", "Invalid API Request or Missing Parameters", 400);


/* ==========================================================
   কোর ফাংশনসমূহ (অপরিবর্তিত)
   ========================================================== */

function initSystem() {
    foreach ([DB_KEYS, DB_MSGS, DB_LOGS] as $file) {
        if (!file_exists($file)) {
            file_put_contents($file, json_encode([]));
        }
    }
}

function sendResponse($status, $data, $httpCode = 200) {
    http_response_code($httpCode);
    $response = ["status" => $status];
    if (is_array($data)) {
        $response = array_merge($response, $data);
    } else {
        $response["message"] = $data;
    }
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function saveActivityLog($server) {
    $logs = json_decode(file_get_contents(DB_LOGS), true);
    $logs[] = [
        "time" => date("Y-m-d H:i:s"),
        "ip" => $server['REMOTE_ADDR'],
        "request" => $server['QUERY_STRING'] ?? "",
        "user_agent" => $server['HTTP_USER_AGENT'] ?? ""
    ];
    if (count($logs) > 1000) array_shift($logs);
    file_put_contents(DB_LOGS, json_encode($logs, JSON_PRETTY_PRINT));
}

function registerMerchant($key, $limit) {
    $keys = json_decode(file_get_contents(DB_KEYS), true);
    $keys[$key] = [
        "limit" => (int)$limit,
        "used" => 0,
        "history" => [],
        "registered_at" => date("Y-m-d H:i:s")
    ];
    file_put_contents(DB_KEYS, json_encode($keys, JSON_PRETTY_PRINT));
    sendResponse("success", ["message" => "Key registered successfully", "key" => $key, "limit" => $limit]);
}

function processSmsFromApp($key, $message) {
    $keysData = json_decode(file_get_contents(DB_KEYS), true);
    if (!isset($keysData[$key])) {
        sendResponse("error", "Key not registered in server", 401);
    }

    $trxid = "";
    if (preg_match('/(?:TrxID|TxnID|Txn ID|TxnId:?)\s*([A-Z0-9]+)/i', $message, $m)) {
        $trxid = strtoupper($m[1]);
    }
    if (empty($trxid)) sendResponse("error", "Transaction ID not found in SMS");

    preg_match('/(?:Tk|BDT)\s*([\d,]+\.\d{2})/i', $message, $amountMatch);
    $amount = isset($amountMatch[1]) ? str_replace(",", "", $amountMatch[1]) : "0.00";

    preg_match('/Sender:\s*(\w+)/i', $message, $senderMatch);
    $serviceName = strtolower($senderMatch[1] ?? "unknown");

    $allMsgs = json_decode(file_get_contents(DB_MSGS), true);
    $allMsgs[$key][$trxid] = [
        "TrxID" => $trxid,
        "Tk" => $amount,
        "Service" => $serviceName,
        "Time" => date("Y-m-d H:i:s"),
        "Raw" => $message
    ];
    
    file_put_contents(DB_MSGS, json_encode($allMsgs, JSON_PRETTY_PRINT));
    sendResponse("success", ["trxid" => $trxid, "amount" => $amount, "service" => $serviceName]);
}

function verifyTransaction($key, $txid) {
    $txid = strtoupper($txid);
    $keysData = json_decode(file_get_contents(DB_KEYS), true);
    $msgsData = json_decode(file_get_contents(DB_MSGS), true);

    if (!isset($keysData[$key])) sendResponse("error", "Invalid Secret Key");
    if ($keysData[$key]['limit'] <= 0) sendResponse("error", "Verification limit finished.");

    if (isset($msgsData[$key][$txid])) {
        $foundData = $msgsData[$key][$txid];
        unset($msgsData[$key][$txid]);
        file_put_contents(DB_MSGS, json_encode($msgsData, JSON_PRETTY_PRINT));

        $keysData[$key]['limit'] -= 1;
        $keysData[$key]['used'] += 1;
        $keysData[$key]['history'][] = [
            "TrxID" => $foundData['TrxID'],
            "Tk" => $foundData['Tk'],
            "Time" => date("Y-m-d H:i:s")
        ];
        file_put_contents(DB_KEYS, json_encode($keysData, JSON_PRETTY_PRINT));

        sendResponse("success", ["TrxID" => $foundData['TrxID'], "Tk" => $foundData['Tk']]);
    } else {
        sendResponse("error", "Transaction not found or already verified");
    }
}

function getDashboardData($key) {
    $keysData = json_decode(file_get_contents(DB_KEYS), true);
    $msgsData = json_decode(file_get_contents(DB_MSGS), true);

    if (!isset($keysData[$key])) sendResponse("error", "Key not found");

    $pendingTrx = isset($msgsData[$key]) ? array_values($msgsData[$key]) : [];
    
    sendResponse("success", [
        "remaining_limit" => $keysData[$key]['limit'],
        "total_used" => $keysData[$key]['used'],
        "pending_count" => count($pendingTrx),
        "pending_list" => $pendingTrx,
        "history" => array_slice(array_reverse($keysData[$key]['history'] ?? []), 0, 10)
    ]);
}
?>
