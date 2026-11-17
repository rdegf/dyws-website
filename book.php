<?php
// Booking form handler for discoveryourwildself.com (/book/ and /nl/boeken/).
// E-mails the booking to Rick and a copy to the participant, and saves it to Rick's Notion (Bookings). Nothing is stored on the server.

date_default_timezone_set('Europe/Amsterdam');
$lang = (($_POST['lang'] ?? '') === 'nl') ? 'nl' : 'en';
$form   = $lang === 'nl' ? '/nl/boeken/' : '/book/';
$thanks = $lang === 'nl' ? '/nl/boeken/bedankt/' : '/book/thanks/';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $form); exit; }
if (trim((string)($_POST['website'] ?? '')) !== '') { header('Location: ' . $thanks); exit; } // bot

function line($k, $max = 200) {
    $v = (string)($_POST[$k] ?? '');
    $v = trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($v)));
    return mb_substr($v, 0, $max);
}
function para($k, $max = 2000) {
    $v = trim(strip_tags((string)($_POST[$k] ?? '')));
    return mb_substr(str_replace("\r", '', $v), 0, $max);
}
function yes($k) { return ($_POST[$k] ?? '') === 'yes'; }

$email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
$f = [
  'first_name' => line('first_name'), 'last_name' => line('last_name'), 'phone' => line('phone'),
  'street' => line('street'), 'postcode' => line('postcode'), 'city' => line('city'), 'country' => line('country'),
  'booking_as' => line('booking_as') === 'company' ? 'company' : 'private',
  'company_name' => line('company_name'), 'company_address' => line('company_address'), 'vat_number' => line('vat_number'),
  'discovery_call' => line('discovery_call'), 'heard' => line('heard'),
  'why' => para('why'), 'intention' => line('intention', 500), 'experience' => para('experience'),
  'concerns' => para('concerns'), 'other' => para('other'),
];
$required = ['first_name','last_name','phone','street','postcode','city','country','discovery_call','why','intention','experience','concerns','other'];
$ok = (bool)$email && yes('accept_terms') && yes('accept_binding') && yes('adult') && yes('privacy');
foreach ($required as $r) { if ($f[$r] === '') { $ok = false; } }
if ($f['booking_as'] === 'company' && ($f['company_name'] === '' || $f['company_address'] === '')) { $ok = false; }
if (!$ok) { header('Location: ' . $form . '?error=1'); exit; }

$now = date('Y-m-d H:i');
$name = $f['first_name'] . ' ' . $f['last_name'];
$nl = $lang === 'nl';

$L = $nl ? [
  'Naam','E-mail','Telefoon','Adres','Boekt als','Particulier','Via werkgever of eigen bedrijf','Bedrijf','Factuuradres bedrijf','Btw-nummer',
  'Trail','Discovery call gehad','Hoe gehoord','Waarom deze trail','Intentie','Ervaring','Zorgen of angsten','Overig',
  'Akkoord reisvoorwaarden en standaardinformatieformulier','Bindende boekingsaanvraag begrepen','18 jaar of ouder','Akkoord privacy','Nieuwsbrief','Ingestuurd op','ja','nee'
] : [
  'Name','E-mail','Phone','Address','Booking as','Private person','Through employer or own business','Company','Company invoice address','VAT number',
  'Trail','Had a discovery call','Heard about it via','Why this trail','Intention','Experience','Fears or concerns','Other',
  'Accepted travel conditions and standard information form','Understood binding booking request','18 or older','Privacy consent','Newsletter','Submitted on','yes','no'
];
$yn = function($b) use ($L) { return $b ? $L[24] : $L[25]; };

$summary = "{$L[0]}: $name\n{$L[1]}: $email\n{$L[2]}: {$f['phone']}\n{$L[3]}: {$f['street']}, {$f['postcode']} {$f['city']}, {$f['country']}\n"
  . "{$L[4]}: " . ($f['booking_as'] === 'company' ? $L[6] : $L[5]) . "\n";
if ($f['booking_as'] === 'company') {
  $summary .= "{$L[7]}: {$f['company_name']}\n{$L[8]}: {$f['company_address']}\n{$L[9]}: {$f['vat_number']}\n";
}
$early = time() < strtotime('2027-01-01 00:00:00');
$price = $f['booking_as'] === 'company'
  ? ($early ? '€4,250 excl. VAT (early bird)' : '€4,750 excl. VAT')
  : ($early ? '€4,695 incl. VAT (early bird)' : '€5,195 incl. VAT');
if ($nl) { $price = strtr($price, ['4,250' => '4.250', '4,750' => '4.750', '4,695' => '4.695', '5,195' => '5.195', 'excl. VAT' => 'excl. btw', 'incl. VAT' => 'incl. btw']); }
$summary .= ($nl ? 'Prijs' : 'Price') . ": $price\n";
$summary .= "\n{$L[10]}: 18-26 April 2027, Okavango Delta, Botswana\n{$L[11]}: {$f['discovery_call']}\n{$L[12]}: {$f['heard']}\n\n"
  . "{$L[13]}:\n{$f['why']}\n\n{$L[14]}: {$f['intention']}\n\n{$L[15]}:\n{$f['experience']}\n\n{$L[16]}:\n{$f['concerns']}\n\n{$L[17]}:\n{$f['other']}\n\n"
  . $L[18] . ': ' . $yn(yes('accept_terms')) . "\n" . $L[19] . ': ' . $yn(yes('accept_binding')) . "\n"
  . $L[20] . ': ' . $yn(yes('adult')) . "\n" . $L[21] . ': ' . $yn(yes('privacy')) . "\n"
  . $L[22] . ': ' . $yn(yes('newsletter')) . "\n" . $L[23] . ": $now\n";

$from = "From: Discover Your Wild Self <website@discoveryourwildself.com>\r\nContent-Type: text/plain; charset=UTF-8\r\n";
$sub = function($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; };

// Save the booking to Notion (Bookings database, linked to Leads / Participants).
// The key lives in notion-config.php, preferably one folder ABOVE public_html: <?php return ['token' => 'ntn_...'];
// If Notion is unreachable or not set up, the booking still goes through by e-mail; Rick's e-mail then says so.
$notionNote = 'Notion: not set up (notion-config.php not found).';
$ncfg = null;
foreach ([dirname(__DIR__) . '/notion-config.php', __DIR__ . '/notion-config.php'] as $nf) {
  if (is_file($nf)) { $ncfg = include $nf; break; }
}
if (is_array($ncfg) && !empty($ncfg['token'])) {
  $BOOKINGS_DB = $ncfg['bookings_db'] ?? '41bfe77e0997819988e7dfa063bbac5d';
  $LEADS_DB    = $ncfg['leads_db'] ?? '83d7d8086cc047aeb4b3a404c6a63ead';
  $notion = function($method, $path, $body) use ($ncfg) {
    $ch = curl_init('https://api.notion.com/v1/' . $path);
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
      CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $ncfg['token'], 'Notion-Version: 2022-06-28', 'Content-Type: application/json'],
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, json_decode((string)$res, true)];
  };
  $txt = function($v) { $v = (string)$v; return ['rich_text' => $v === '' ? [] : [['text' => ['content' => mb_substr($v, 0, 2000)]]]]; };
  $today = date('Y-m-d');
  $company = $f['booking_as'] === 'company';
  $amount = $company ? ($early ? 4250 : 4750) : ($early ? 4695 : 5195);

  // Find the person in Leads / Participants by e-mail, or add them.
  $leadId = null;
  [$c, $r] = $notion('POST', "databases/$LEADS_DB/query", ['filter' => ['property' => 'Email', 'email' => ['equals' => $email]], 'page_size' => 1]);
  if ($c === 200 && !empty($r['results'][0]['id'])) {
    $leadId = $r['results'][0]['id'];
    $notion('PATCH', "pages/$leadId", ['properties' => ['Last Contact' => ['date' => ['start' => $today]]]]);
  } else {
    [$c2, $r2] = $notion('POST', 'pages', ['parent' => ['database_id' => $LEADS_DB], 'properties' => [
      'Name' => ['title' => [['text' => ['content' => $name]]]],
      'Email' => ['email' => $email],
      'Phone' => ['phone_number' => $f['phone'] ?: null],
      'Last Contact' => ['date' => ['start' => $today]],
      'Consent newsletter' => ['select' => ['name' => yes('newsletter') ? 'Yes' : 'No']],
      'Notes' => $txt('Added automatically from the website booking form on ' . $now . '.'),
    ]]);
    if ($c2 === 200) { $leadId = $r2['id'] ?? null; }
  }

  $props = [
    'Name' => ['title' => [['text' => ['content' => $name]]]],
    'Status' => ['status' => ['name' => 'Request received']],
    'Trail' => ['select' => ['name' => '18–26 April 2027']],
    'Booked on' => ['date' => ['start' => date('c')]],
    'Email' => ['email' => $email],
    'Phone' => ['phone_number' => $f['phone'] ?: null],
    'Address' => $txt("{$f['street']}, {$f['postcode']} {$f['city']}"),
    'Country' => $txt($f['country']),
    'Language' => ['select' => ['name' => $nl ? 'NL' : 'EN']],
    'Booking as' => ['select' => ['name' => $company ? 'Business' : 'Private']],
    'Company name' => $txt($company ? $f['company_name'] : ''),
    'Company address' => $txt($company ? $f['company_address'] : ''),
    'Company VAT number' => $txt($company ? $f['vat_number'] : ''),
    'Price' => $txt($price),
    'Total amount' => ['number' => $amount],
    'Discovery call' => $txt($f['discovery_call']),
    'How they heard' => $txt($f['heard']),
    'Why this trail' => $txt($f['why']),
    'Intention' => $txt($f['intention']),
    'Experience' => $txt($f['experience']),
    'Concerns' => $txt($f['concerns']),
    'Other' => $txt($f['other']),
    'Newsletter' => ['checkbox' => yes('newsletter')],
    'Terms and conditions accepted' => ['checkbox' => yes('accept_terms') && yes('accept_binding')],
    'Next action' => $txt('Confirm or decline in writing within 5 working days'),
    'Next action date' => ['date' => ['start' => date('Y-m-d', strtotime('+7 days'))]],
  ];
  if ($leadId) { $props['Participant'] = ['relation' => [['id' => $leadId]]]; }
  [$c3, $r3] = $notion('POST', 'pages', ['parent' => ['database_id' => $BOOKINGS_DB], 'properties' => $props]);
  $notionNote = $c3 === 200
    ? 'Notion: saved to Bookings' . (!empty($r3['url']) ? ' — ' . $r3['url'] : '') . ($leadId ? '' : ' (could not link to Leads / Participants)')
    : 'Notion: NOT saved (error ' . $c3 . ': ' . ($r3['message'] ?? 'no response') . '). Please add this booking by hand.';
}

// To Rick
$toRick = @mail('hello@discoveryourwildself.com', $sub("New booking: $name (18-26 April 2027)"),
  "New binding booking request via the website (" . ($nl ? 'Dutch' : 'English') . " form).\nConfirm or decline in writing within 5 working days.\n" . $notionNote . "\n\n" . $summary,
  $from . "Reply-To: $email\r\n");

// Copy to participant
$termsUrl = $nl ? 'https://discoveryourwildself.com/nl/reisvoorwaarden/' : 'https://discoveryourwildself.com/terms-and-conditions/';
$intro = $nl
  ? "Beste {$f['first_name']},\n\nDank je wel voor je boeking van de Discover Your Wild Self-trail van 18-26 april 2027. Hieronder vind je een kopie van wat je hebt ingestuurd.\n\nDit is een bindende boekingsaanvraag. De overeenkomst komt tot stand zodra wij je boeking binnen 5 werkdagen schriftelijk bevestigen. Daarna betaal je binnen 14 dagen de aanbetaling van €750 via STO Garant, en ontvang je het deelnemersformulier. Wacht met het boeken van vluchten tot wij bevestigen dat minimaal 4 deelnemers hebben geboekt.\n\nReisvoorwaarden: $termsUrl\n\n"
  : "Dear {$f['first_name']},\n\nThank you for booking the Discover Your Wild Self trail, 18-26 April 2027. Below is a copy of what you submitted.\n\nThis is a binding booking request. The contract is concluded once we confirm your booking in writing, within 5 working days. You then pay the €750 deposit within 14 days via STO Garant, and receive the participant form. Please wait with booking flights until we confirm that at least 4 participants have booked.\n\nTravel conditions: $termsUrl\n\n";
$outro = $nl ? "\nHartelijke groet,\nRick de Gaay Fortman\nDiscover Your Wild Self\n+31 6 5883 7096\n" : "\nWarm regards,\nRick de Gaay Fortman\nDiscover Your Wild Self\n+31 6 5883 7096\n";
@mail($email, $sub($nl ? 'Je boeking: Discover Your Wild Self-trail 18-26 april 2027' : 'Your booking: Discover Your Wild Self trail 18-26 April 2027'),
  $intro . $summary . $outro, $from . "Reply-To: hello@discoveryourwildself.com\r\n");

header('Location: ' . ($toRick ? $thanks : $form . '?error=1'));
exit;
