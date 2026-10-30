<?php
// Booking form handler for discoveryourwildself.com (/book/ and /nl/boeken/).
// E-mails the booking to Rick and a copy to the participant. Nothing is stored on the server.

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
  'Naam','E-mail','Telefoon','Adres','Boekt als','Particulier','Via werkgever','Bedrijf','Factuuradres bedrijf','Btw-nummer',
  'Trail','Discovery call gehad','Hoe gehoord','Waarom deze trail','Intentie','Ervaring','Zorgen of angsten','Overig',
  'Akkoord reisvoorwaarden en standaardinformatieformulier','Bindende boekingsaanvraag begrepen','18 jaar of ouder','Akkoord privacy','Nieuwsbrief','Ingestuurd op','ja','nee'
] : [
  'Name','E-mail','Phone','Address','Booking as','Private person','Through employer','Company','Company invoice address','VAT number',
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

// To Rick
$toRick = @mail('hello@discoveryourwildself.com', $sub("New booking: $name (18-26 April 2027)"),
  "New binding booking request via the website (" . ($nl ? 'Dutch' : 'English') . " form).\nConfirm or decline in writing within 5 working days.\n\n" . $summary,
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
