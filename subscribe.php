<?php
// Newsletter sign-up for discoveryourwildself.com
// Adds the visitor to Sender.net (and triggers the "joins group" automation = welcome email).
// If Sender can't be reached, the sign-up is emailed to Rick so nobody gets lost.

$lang = (($_POST['lang'] ?? '') === 'nl') ? 'nl' : 'en';
$base = $lang === 'nl' ? '/nl/' : '/';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /'); exit; }

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$trap  = trim((string)($_POST['website'] ?? ''));
$name  = trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags((string)($_POST['first_name'] ?? ''))));
$name  = mb_substr($name, 0, 60);
if ($trap !== '') { header('Location: ' . $base . '?subscribed=1#newsletter'); exit; }   // bot: pretend success
if (!$email || $name === '') { header('Location: ' . $base . '?subscribed=0#newsletter'); exit; }

// Settings live in sender-config.php — preferably one folder ABOVE public_html, so it is never web-accessible.
$cfg = null;
foreach ([dirname(__DIR__) . '/sender-config.php', __DIR__ . '/sender-config.php'] as $f) {
    if (is_file($f)) { $cfg = include $f; break; }
}

$added = false; $error = 'config file not found';
if (is_array($cfg) && !empty($cfg['api_token'])) {
    $group = ($lang === 'nl' && !empty($cfg['group_nl'])) ? $cfg['group_nl'] : ($cfg['group_en'] ?? '');
    $payload = ['email' => $email, 'firstname' => $name, 'trigger_automation' => true];
    if ($group !== '') { $payload['groups'] = [$group]; }
    $ch = curl_init('https://api.sender.net/v2/subscribers');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['api_token'],
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    $json  = json_decode((string)$res, true);
    $added = $code >= 200 && $code < 300 && !empty($json['success']);
    $error = $added ? '' : ('HTTP ' . $code . ' ' . ($cerr ?: substr((string)$res, 0, 500)));
}

$notify = is_array($cfg) ? ($cfg['notify_email'] ?? 'hello@discoveryourwildself.com') : 'hello@discoveryourwildself.com';
$mailed = false;
if (!$added || !empty($cfg['notify_on_success'])) {
    $subject = $added ? 'New newsletter subscriber' : 'Newsletter sign-up NOT added to Sender — please add manually';
    $body = ($added ? "Added to Sender.net:\n\n" : "Could not add this person to Sender.net ($error).\nPlease add them manually:\n\n")
          . "First name: $name\nEmail: $email\nLanguage: " . ($lang === 'nl' ? 'Dutch' : 'English') . "\nDate: " . date('Y-m-d H:i') . "\n";
    $headers = "From: Discover Your Wild Self <website@discoveryourwildself.com>\r\n"
             . "Reply-To: $email\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n";
    $mailed = @mail($notify, $subject, $body, $headers);
}

header('Location: ' . $base . '?subscribed=' . (($added || $mailed) ? '1' : '0') . '#newsletter');
exit;
