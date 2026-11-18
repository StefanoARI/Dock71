<?php
/**
 * DOCK 71 CANTÙ - AUTHENTICATED SMTP MAILER CON PHPMAILER
 * Utilizza la libreria ufficiale PHPMailer (SSL/TLS Porta 465 su mailserver15.vhosting-it.com + fallback STARTTLS 587).
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$phpMailerBase = __DIR__ . '/PHPMailer/src';
if (file_exists($phpMailerBase . '/Exception.php')) {
    require_once $phpMailerBase . '/Exception.php';
    require_once $phpMailerBase . '/PHPMailer.php';
    require_once $phpMailerBase . '/SMTP.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'mailserver15.vhosting-it.com');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 465);
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'info@dock71birreria.it');
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', 'RdP7KakV6EXZ9C584J2B@.');
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', 'info@dock71birreria.it');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'Dock 71 Cantù');
}
if (!defined('COMPANY_NOTIFICATION_EMAIL')) {
    define('COMPANY_NOTIFICATION_EMAIL', 'info@dock71birreria.it');
}

/**
 * Invia un'email HTML autenticata tramite PHPMailer e server SMTP vHosting
 */
if (!function_exists('sendSmtpEmail')) {
    function sendSmtpEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $replyTo = '',
        ?string &$errorOut = null
    ): bool {
        $toEmail = trim($toEmail);
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $errorOut = 'Indirizzo email destinatario non valido';
            return false;
        }

        $replyToEmail = (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL))
            ? $replyTo
            : SMTP_FROM_EMAIL;

        // Tentativo 1: SMTPS (SSL) Porta 465 su mailserver15.vhosting-it.com
        // Tentativo 2 (fallback): STARTTLS Porta 587 se la 465 è bloccata dal firewall
        $attempts = [
            ['secure' => PHPMailer::ENCRYPTION_SMTPS, 'port' => (int)SMTP_PORT],
            ['secure' => PHPMailer::ENCRYPTION_STARTTLS, 'port' => 587],
        ];

        foreach ($attempts as $cfg) {
            $mail = new PHPMailer(true);
            try {
                // Configurazione Server SMTP
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USER;
                $mail->Password   = SMTP_PASS;
                $mail->SMTPSecure = $cfg['secure'];
                $mail->Port       = $cfg['port'];
                $mail->Timeout    = 15;

                // Tolleranza certificati SSL condivisi vHosting / cPanel
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];

                // Mittente, Destinatario e Reply-To
                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                $mail->addAddress($toEmail, $toName ?: $toEmail);
                $mail->addReplyTo($replyToEmail, SMTP_FROM_NAME);

                // Contenuto HTML UTF-8
                $mail->isHTML(true);
                $mail->CharSet  = PHPMailer::CHARSET_UTF8;
                $mail->Encoding = PHPMailer::ENCODING_BASE64;
                $mail->Subject  = $subject;
                $mail->Body     = $htmlBody;
                $mail->AltBody  = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</tr>'], "\n", $htmlBody)));

                if ($mail->send()) {
                    return true;
                }
            } catch (Exception $e) {
                $errorOut = $mail->ErrorInfo ?: $e->getMessage();
                error_log("Errore invio SMTP (porta {$cfg['port']}): {$errorOut}");
            }
        }

        return false;
    }
}

if (!function_exists('sendDock71SmtpEmail')) {
    function sendDock71SmtpEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $replyTo = '', ?string &$errorOut = null): bool {
        return sendSmtpEmail($toEmail, $toName, $subject, $htmlBody, $replyTo, $errorOut);
    }
}

if (!function_exists('buildDock71EmailHtml')) {
    function buildDock71EmailHtml(string $badgeText, string $badgeColor, string $headline, string $customerName, string $bodyHtml, array $details = []): string {
        $rowsHtml = '';
        foreach ($details as $label => $val) {
            if ($val === '' || $val === null) continue;
            $safeLabel = htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8');
            $safeVal   = htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
            $rowsHtml .= "
            <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #E8E2D4;font-size:12px;color:#7A7265;font-weight:bold;text-transform:uppercase;'>{$safeLabel}</td>
                <td style='padding:10px 14px;border-bottom:1px solid #E8E2D4;font-size:13px;color:#1D1C13;font-weight:bold;text-align:right;'>{$safeVal}</td>
            </tr>";
        }

        $detailsTable = !empty($rowsHtml) ? "
        <table width='100%' cellpadding='0' cellspacing='0' style='background:#FAF7F2;border:1px solid #E8E2D4;border-radius:10px;margin:18px 0;overflow:hidden;'>
            {$rowsHtml}
        </table>" : '';

        $safeName     = htmlspecialchars($customerName ?: 'Cliente', ENT_QUOTES, 'UTF-8');
        $safeHeadline = htmlspecialchars($headline, ENT_QUOTES, 'UTF-8');
        $safeBadge    = htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8');

        return "<!DOCTYPE html>
<html lang='it'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:24px;background-color:#FAF7F2;font-family:Arial,Helvetica,sans-serif;color:#1D1C13;'>
    <div style='max-width:600px;margin:0 auto;background-color:#FFF9EB;border:3px solid #7B2039;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(29,28,19,0.12);'>
        <div style='background-color:#7B2039;padding:22px 24px;text-align:center;border-bottom:4px solid #E0A93B;'>
            <div style='display:inline-block;padding:5px 14px;background-color:{$badgeColor};color:#FFFFFF;border-radius:20px;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;'>
                {$safeBadge}
            </div>
            <h1 style='margin:0;color:#FFF9EB;font-size:22px;text-transform:uppercase;letter-spacing:0.5px;'>DOCK 71 • BIRRERIA &amp; PUB INDUSTRIAL</h1>
            <p style='margin:4px 0 0;color:#E0A93B;font-size:12px;font-weight:bold;'>Via Milano 71, 22063 Cantù (CO) • Griglia, Malto e Farina</p>
        </div>
        <div style='padding:26px 24px;'>
            <h2 style='margin-top:0;color:#7B2039;font-size:19px;'>{$safeHeadline}</h2>
            <p style='font-size:14px;line-height:1.6;color:#1D1C13;'>Ciao <strong>{$safeName}</strong>,</p>
            <div style='font-size:14px;line-height:1.6;color:#554245;'>{$bodyHtml}</div>
            {$detailsTable}
            <p style='font-size:12px;color:#7A7265;margin-top:22px;line-height:1.5;'>Per qualsiasi necessità puoi contattarci telefonicamente al <strong>+39 031 712063</strong> oppure rispondere direttamente a questa email (<a href='mailto:info@dock71birreria.it' style='color:#7B2039;font-weight:bold;'>info@dock71birreria.it</a>).</p>
        </div>
        <div style='background-color:#F3EDDF;padding:14px 24px;text-align:center;border-top:1px solid #E8E2D4;font-size:11px;color:#7A7265;'>
            © " . date('Y') . " Dock 71 Cantù • Via Milano 71, 22063 Cantù (CO)
        </div>
    </div>
</body>
</html>";
    }
}
