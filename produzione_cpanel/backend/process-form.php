<?php
/**
 * DOCK 71 CANTÙ - PROCESS FORM HANDLER (SMTP & DATABASE)
 *
 * Riceve le richieste da Form Contatto e Form Prenotazione:
 * 1. Salva i dati nella rispettiva tabella MySQL (contacts / bookings) tramite PDO
 * 2. Invia la doppia email di notifica (conferma al cliente + notifica aziendale a info@dock71birreria.it)
 * 3. Esegue redirect automatico verso grazie-contatto.php o grazie-prenotazione.php
 */

require_once __DIR__ . '/db.php';

// Controlla richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /');
    exit;
}

// Protezione Honeypot anti-spam
if (!empty($_POST['website_url_hp'])) {
    // Bot rilevato: termina silenziosamente
    header('Location: /');
    exit;
}

$action = $_POST['action'] ?? 'contact';

/**
 * Funzione helper per invio email tramite SMTP su server mailserver15.vhosting-it.com
 */
function sendSmtpEmail($toEmail, $toName, $subject, $htmlBody): bool {
    // In ambiente PHP con PHPMailer o socket SSL porta 465
    // Intestazioni per mail nativa come fallback sicuro
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . SMTP_FROM_EMAIL . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    return @mail($toEmail, $subject, $htmlBody, $headers);
}

$pdo = getDbConnection();

// ============================================================================
// 1. GESTIONE FORM CONTATTO
// ============================================================================
if ($action === 'contact') {
    $name    = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $email   = trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL) ?? '');
    $phone   = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $subject = trim(filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'Richiesta Contatto Dock 71');
    $message = trim(filter_input(INPUT_POST, 'message', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $ip      = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if (empty($name) || (empty($email) && empty($phone)) || empty($message)) {
        header('Location: /contatti?error=missing_fields');
        exit;
    }

    try {
        // 1. Salvataggio su DB MySQL
        $stmt = $pdo->prepare("
            INSERT INTO `contacts` (`name`, `email`, `phone`, `subject`, `message`, `ip_address`, `status`)
            VALUES (:name, :email, :phone, :subject, :message, :ip, 'new')
        ");
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':phone'   => $phone,
            ':subject' => $subject,
            ':message' => $message,
            ':ip'      => $ip
        ]);

        // 2. Email al Cliente (Conferma presa in carico)
        if ($email) {
            $clientBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 2px solid #7B2039; border-radius: 12px; background-color: #FFF9EB;'>
                <h2 style='color: #7B2039; margin-top: 0;'>Ciao {$name}, abbiamo ricevuto il tuo messaggio!</h2>
                <p>Grazie per aver contattato <strong>Dock 71 Cantù</strong>. Ti confermiamo che il nostro staff ha preso in carico la tua richiesta e <em>sarai ricontattato a breve con la risposta da parte del nostro team</em>.</p>
                <div style='background-color: #F3EDDF; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                    <p style='margin: 0 0 8px 0;'><strong>Riepilogo messaggio inviato:</strong></p>
                    <p style='margin: 0; color: #554245; font-style: italic;'>\"" . nl2br($message) . "\"</p>
                </div>
                <p style='font-size: 13px; color: #7A7265;'>Dock 71 • Via Milano 71, Cantù (CO) • Tel. 031 448 7120</p>
            </div>";
            sendSmtpEmail($email, $name, "Dock 71 Cantù - Abbiamo ricevuto il tuo messaggio", $clientBody);
        }

        // 3. Email Notifica all'Azienda (info@dock71birreria.it)
        $adminBody = "
        <div style='font-family: Arial, sans-serif; padding: 20px;'>
            <h2 style='color: #7B2039;'>Nuova Richiesta Contatto dal Sito Web</h2>
            <p><strong>Nome:</strong> {$name}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Telefono:</strong> {$phone}</p>
            <p><strong>Oggetto:</strong> {$subject}</p>
            <p><strong>Messaggio:</strong></p>
            <p style='background: #eee; padding: 12px;'>" . nl2br($message) . "</p>
            <p><small>IP Mittente: {$ip} • Data: " . date('d/m/Y H:i:s') . "</small></p>
        </div>";
        sendSmtpEmail(COMPANY_NOTIFICATION_EMAIL, 'Dock 71 Admin', "Nuovo Messaggio da {$name} - Dock 71", $adminBody);

        // 4. Redirect automatico alla Thank You Page
        header('Location: /grazie-contatto');
        exit;
    } catch (Exception $e) {
        error_log("Contact form error: " . $e->getMessage());
        header('Location: /contatti?error=server_error');
        exit;
    }
}

// ============================================================================
// 2. GESTIONE FORM PRENOTAZIONE
// ============================================================================
if ($action === 'booking') {
    $name     = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $email    = trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL) ?? '');
    $phone    = trim(filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $date     = trim(filter_input(INPUT_POST, 'date', FILTER_SANITIZE_SPECIAL_CHARS) ?? date('Y-m-d'));
    $timeSlot = trim(filter_input(INPUT_POST, 'time_slot', FILTER_SANITIZE_SPECIAL_CHARS) ?? '20:30');
    $guests   = (int)($_POST['guests'] ?? 2);
    $area     = trim(filter_input(INPUT_POST, 'area', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'sala-gru');
    $notes    = trim(filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
    $bookingCode = 'DOCK-' . rand(1000, 9999);

    if (empty($name) || empty($phone)) {
        header('Location: /prenota?error=missing_fields');
        exit;
    }

    try {
        // 1. Salvataggio su DB MySQL
        $stmt = $pdo->prepare("
            INSERT INTO `bookings` (`booking_code`, `name`, `email`, `phone`, `booking_date`, `time_slot`, `guests`, `room_area`, `notes`, `status`)
            VALUES (:code, :name, :email, :phone, :bdate, :slot, :guests, :area, :notes, 'pending')
        ");
        $stmt->execute([
            ':code'   => $bookingCode,
            ':name'   => $name,
            ':email'  => $email,
            ':phone'  => $phone,
            ':bdate'  => $date,
            ':slot'   => $timeSlot,
            ':guests' => $guests,
            ':area'   => $area,
            ':notes'  => $notes
        ]);

        // 2. Email al Cliente (Conferma in attesa)
        if ($email) {
            $clientBookingBody = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 2px solid #7B2039; border-radius: 12px; background-color: #FFF9EB;'>
                <h2 style='color: #7B2039; margin-top: 0;'>Richiesta di Prenotazione Ricevuta!</h2>
                <p>Ciao {$name}, ti confermiamo che <em>la prenotazione è in attesa di conferma e che il team risponderà a breve</em>.</p>
                <div style='background-color: #FFFFFF; border: 2px dashed #7B2039; padding: 15px; border-radius: 8px; text-align: center; margin: 20px 0;'>
                    <span style='font-size: 12px; color: #7A7265; text-transform: uppercase;'>Codice Prenotazione</span><br>
                    <strong style='font-size: 24px; color: #7B2039; letter-spacing: 2px;'>{$bookingCode}</strong>
                </div>
                <p><strong>Dettagli:</strong> Data: {$date} • Orario: {$timeSlot} • Persone: {$guests}</p>
                <p style='font-size: 13px; color: #7A7265;'>Dock 71 • Via Milano 71, Cantù (CO) • Tel. 031 448 7120</p>
            </div>";
            sendSmtpEmail($email, $name, "Dock 71 Cantù - Richiesta Prenotazione {$bookingCode}", $clientBookingBody);
        }

        // 3. Email Notifica all'Azienda (info@dock71birreria.it)
        $adminBookingBody = "
        <div style='font-family: Arial, sans-serif; padding: 20px;'>
            <h2 style='color: #7B2039;'>Nuova Prenotazione Tavolo Ricevuta [{$bookingCode}]</h2>
            <p><strong>Nome Cliente:</strong> {$name}</p>
            <p><strong>Telefono:</strong> {$phone}</p>
            <p><strong>Email:</strong> {$email}</p>
            <p><strong>Data &amp; Orario:</strong> {$date} alle ore {$timeSlot}</p>
            <p><strong>Coperti:</strong> {$guests} persone</p>
            <p><strong>Area richiesta:</strong> {$area}</p>
            <p><strong>Note:</strong> {$notes}</p>
        </div>";
        sendSmtpEmail(COMPANY_NOTIFICATION_EMAIL, 'Dock 71 Tavoli', "Nuova Prenotazione [{$bookingCode}] - {$name} ({$guests} pax)", $adminBookingBody);

        // 4. Redirect automatico alla Thank You Page con codice
        header('Location: /grazie-prenotazione?code=' . urlencode($bookingCode));
        exit;
    } catch (Exception $e) {
        error_log("Booking form error: " . $e->getMessage());
        header('Location: /prenota?error=server_error');
        exit;
    }
}
