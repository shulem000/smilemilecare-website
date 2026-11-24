<?php
// Contact form on the homepage. Each message is emailed to info@smilemilecare.com
// (which forwards to the owner) and a copy is kept in _private/leads.csv (blocked from the web).
// Spam filter: hidden "company_site" box, too-fast submissions, link-stuffed messages.

$TO    = 'info@smilemilecare.com';
$FROM  = 'website@smilemilecare.com';
$STORE = __DIR__ . '/_private/leads.csv';

$json = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
function finish($ok, $json, $err = '') {
    if ($json) {
        header('Content-Type: application/json');
        if (!$ok) http_response_code(400);
        echo json_encode(['ok' => $ok, 'error' => $err]);
    } else {
        header('Location: ' . ($ok ? './?sent=1#contact' : './#contact'), true, 303);
    }
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') finish(false, $json, 'method');

$p = function ($k) { return isset($_POST[$k]) && !is_array($_POST[$k]) ? (string)$_POST[$k] : ''; };
$one = function ($s, $max) { return str_replace(["\r", "\n", "\t"], ' ', mb_substr(trim($s), 0, $max)); };

$name    = $one($p('name'), 120);
$topic   = $one($p('topic'), 60);
$email   = $one($p('email'), 160);
$phone   = $one($p('phone'), 40);
$org     = $one($p('org'), 160);
$message = mb_substr(trim($p('message')), 0, 4000);
$ip      = $_SERVER['REMOTE_ADDR'] ?? '';

// spam: pretend success so robots learn nothing
if ($p('company_site') !== '') finish(true, $json);
$t = (int)$p('t');
if ($t > 0 && time() - $t < 3) finish(true, $json);
if (preg_match_all('~https?://~i', $message) > 2) finish(true, $json);

if ($name === '') finish(false, $json, 'name');
if ($email === '' && $phone === '') finish(false, $json, 'contact');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) finish(false, $json, 'email');

// keep a copy on the server
@mkdir(__DIR__ . '/_private', 0700);
if (!file_exists(__DIR__ . '/_private/.htaccess')) @file_put_contents(__DIR__ . '/_private/.htaccess', "Require all denied\n");
if ($fh = @fopen($STORE, 'a')) {
    if (flock($fh, LOCK_EX)) {
        fputcsv($fh, [gmdate('c'), $name, $topic, $email, $phone, $org, str_replace(["\r", "\n"], ' ', $message), $ip]);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($STORE, 0600);
}

// email it
date_default_timezone_set('America/New_York');
$subject = 'Website message: ' . $name . ($topic !== '' ? ' (' . $topic . ')' : '');
$body  = "New message from smilemilecare.com\n\n";
$body .= "Name:      $name\n";
$body .= "I am a:    " . ($topic ?: '-') . "\n";
$body .= "Email:     " . ($email ?: '-') . "\n";
$body .= "Phone:     " . ($phone ?: '-') . "\n";
$body .= "Facility:  " . ($org ?: '-') . "\n\n";
$body .= "Message:\n" . ($message !== '' ? $message : '-') . "\n\n";
$body .= "Sent " . date('D, M j, Y g:i A T') . "\n";
$headers  = "From: SmileMile Care Website <$FROM>\r\n";
if ($email !== '') $headers .= "Reply-To: " . str_replace(["\r", "\n"], '', $email) . "\r\n";
$headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
$ok = @mail($TO, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $FROM);
@file_put_contents(__DIR__ . '/_private/mail-log.txt', gmdate('c') . ' ' . ($ok ? 'sent' : 'FAILED') . " $name\n", FILE_APPEND);

finish(true, $json);
