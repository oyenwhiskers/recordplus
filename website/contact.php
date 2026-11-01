<?php
declare(strict_types=1);

session_start();

$redirectBase = 'contact.html';
$toEmail = 'support@recordplus.app';

const RATE_LIMIT_MAX = 5;
const RATE_LIMIT_WINDOW = 3600;
const MIN_SUBMIT_SECONDS = 3;
const MAX_SUBMIT_SECONDS = 7200;

function send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

function is_ajax_request(): bool
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function respond(bool $success, string $message = ''): void
{
    global $redirectBase;

    send_security_headers();

    if ($message === '') {
        $message = $success
            ? 'Thank you. Your message has been sent. We will reply to your email as soon as we can.'
            : 'We could not send your message right now. Please try again or email support@recordplus.app directly.';
    }

    if (is_ajax_request()) {
        http_response_code($success ? 200 : 400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success' => $success,
            'message' => $message,
        ]);
        exit;
    }

    header('Location: ' . $redirectBase . '?status=' . ($success ? 'success' : 'error') . '&msg=' . rawurlencode($message));
    exit;
}

function clean_input(string $value): string
{
    return trim($value);
}

function has_header_injection(string $value): bool
{
    return (bool) preg_match('/[\r\n]|%0A|%0D/i', $value);
}

function is_valid_referer(): bool
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    if ($referer === '') {
        return true;
    }

    return (bool) preg_match(
        '#^https?://(www\.)?(recordplus\.app|localhost|127\.0\.0\.1)(:\d+)?/.*$#i',
        $referer
    );
}

function is_valid_csrf_token(string $token): bool
{
    $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');

    return $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function is_valid_form_timing(int $formTimestamp): bool
{
    if ($formTimestamp <= 0) {
        return false;
    }

    $elapsed = time() - $formTimestamp;

    return $elapsed >= MIN_SUBMIT_SECONDS && $elapsed <= MAX_SUBMIT_SECONDS;
}

function check_session_rate_limit(): bool
{
    $now = time();
    $windowStart = (int) ($_SESSION['rate_window_start'] ?? 0);
    $submitCount = (int) ($_SESSION['submit_count'] ?? 0);

    if ($windowStart === 0 || ($now - $windowStart) > RATE_LIMIT_WINDOW) {
        $_SESSION['rate_window_start'] = $now;
        $_SESSION['submit_count'] = 0;
        $windowStart = $now;
        $submitCount = 0;
    }

    if ($submitCount >= RATE_LIMIT_MAX) {
        return false;
    }

    $_SESSION['submit_count'] = $submitCount + 1;

    return true;
}

function looks_like_spam(string $text): bool
{
    $lower = strtolower($text);

    if (preg_match_all('/https?:\/\//i', $text) >= 3) {
        return true;
    }

    $spamPatterns = [
        'viagra',
        'casino',
        'crypto giveaway',
        'seo service',
        'link building service',
        'guest post',
        'telegram:',
        'whatsapp:',
    ];

    foreach ($spamPatterns as $pattern) {
        if (str_contains($lower, $pattern)) {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

if (!is_valid_referer()) {
    respond(false, 'Invalid request source.');
}

if (!check_session_rate_limit()) {
    respond(false, 'Too many submissions. Please try again later or email support@recordplus.app directly.');
}

$csrfToken = clean_input((string) ($_POST['csrf_token'] ?? ''));
if (!is_valid_csrf_token($csrfToken)) {
    respond(false, 'Security validation failed. Please refresh the page and try again.');
}

$formTimestamp = (int) ($_POST['form_ts'] ?? 0);
if (!is_valid_form_timing($formTimestamp)) {
    respond(false, 'Please wait a moment and try again.');
}

$honeypotWebsite = clean_input((string) ($_POST['website_url'] ?? ''));
$honeypotCompanySite = clean_input((string) ($_POST['company_site'] ?? ''));
if ($honeypotWebsite !== '' || $honeypotCompanySite !== '') {
    respond(false);
}

$name = clean_input((string) ($_POST['name'] ?? ''));
$email = clean_input((string) ($_POST['email'] ?? ''));
$subjectLine = clean_input((string) ($_POST['subject'] ?? ''));
$message = clean_input((string) ($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $subjectLine === '' || $message === '') {
    respond(false, 'Please fill in all required fields.');
}

if (
    strlen($name) > 100 ||
    strlen($email) > 254 ||
    strlen($subjectLine) > 200 ||
    strlen($message) > 5000
) {
    respond(false, 'One or more fields are too long.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

if (
    has_header_injection($name) ||
    has_header_injection($email) ||
    has_header_injection($subjectLine)
) {
    respond(false);
}

$spamCheckText = $name . ' ' . $subjectLine . ' ' . $message;
if (looks_like_spam($spamCheckText)) {
    respond(false);
}

$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeSubject = htmlspecialchars($subjectLine, ENT_QUOTES, 'UTF-8');
$safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

$subject = 'RecordPlus contact: ' . $subjectLine;
$messageBody = implode("\n", [
    "Name: {$safeName}",
    "Email: {$safeEmail}",
    "Subject: {$safeSubject}",
    '',
    'Message:',
    $safeMessage,
]);

$safeFromName = preg_replace('/[^a-zA-Z0-9 \'\-]/', '', $name) ?: 'RecordPlus Visitor';
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $safeFromName . ' <' . $toEmail . '>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . phpversion(),
];

$mailSent = mail($toEmail, $subject, $messageBody, implode("\r\n", $headers));

if ($mailSent) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    respond(true);
}

respond(false);
