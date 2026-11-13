<?php

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendPasswordResetEmail(string $recipient, string $name, string $resetLink): void
{
    $privateConfigPath = getenv('USERPROFILE')
        . DIRECTORY_SEPARATOR . 'aussh_private'
        . DIRECTORY_SEPARATOR . 'mail_config.php';

    if (!is_file($privateConfigPath)) {
        throw new RuntimeException('Private email configuration is missing.');
    }

    // Buffer possible UTF-8 BOM output from a Windows-created PHP file.
    ob_start();
    $mailConfig = require $privateConfigPath;
    ob_end_clean();

    if (
        !is_array($mailConfig) ||
        empty($mailConfig['username']) ||
        empty($mailConfig['password']) ||
        empty($mailConfig['from_email'])
    ) {
        throw new RuntimeException('Email configuration is incomplete.');
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host = $mailConfig['host'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Timeout = 20;

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name'] ?? 'AU Student Success Hub'
    );

    $mail->addAddress($recipient, $name);

    $mail->isHTML(true);
    $mail->Subject = 'Reset your AU Student Success Hub password';

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8');

    $mail->Body = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:28px;color:#333">
            <div style="background:#641c32;color:white;padding:24px;border-radius:12px">
                <h2 style="margin:0">AU Student Success Hub</h2>
                <p style="margin-bottom:0">Account security and password recovery</p>
            </div>

            <h2>Hello ' . $safeName . ',</h2>

            <p>We received a request to reset the password for your AU Student Success Hub account.</p>

            <p>Use the button below to create a new password.</p>

            <p style="margin:30px 0">
                <a href="' . $safeLink . '"
                   style="background:#641c32;color:white;padding:14px 24px;
                          text-decoration:none;border-radius:8px;font-weight:bold">
                    Reset My Password
                </a>
            </p>

            <p>This link expires in <strong>30 minutes</strong> and can only be used once.</p>

            <p>If you did not request this change, you can safely ignore this email.</p>

            <hr>
            <small>AU Student Success Hub (AUSSH)</small>
        </div>
    ';

    $mail->AltBody =
        "Hello {$name},\n\n"
        . "Use this link to reset your password:\n{$resetLink}\n\n"
        . "The link expires in 30 minutes and can only be used once.";

    $mail->send();
}

function sendWorkflowEmail(string $recipient, string $name, string $subject, string $message): void
{
    $profile = getenv('USERPROFILE');
    if (!is_string($profile) || $profile === '') {
        throw new RuntimeException('Private email configuration is unavailable.');
    }

    $privateConfigPath = $profile
        . DIRECTORY_SEPARATOR . 'aussh_private'
        . DIRECTORY_SEPARATOR . 'mail_config.php';
    if (!is_file($privateConfigPath)) {
        throw new RuntimeException('Private email configuration is unavailable.');
    }

    ob_start();
    try {
        $mailConfig = require $privateConfigPath;
    } finally {
        ob_end_clean();
    }

    if (
        !is_array($mailConfig) ||
        empty($mailConfig['username']) ||
        empty($mailConfig['password']) ||
        empty($mailConfig['from_email'])
    ) {
        throw new RuntimeException('Private email configuration is incomplete.');
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'] ?? 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = 465;
    $mail->CharSet = PHPMailer::CHARSET_UTF8;
    $mail->Timeout = 12;
    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name'] ?? 'AU Student Success Hub'
    );
    $safeHeaderName = preg_replace('/[\r\n\x00-\x1F\x7F]/u', ' ', $name) ?? '';
    $safeHeaderSubject = preg_replace('/[\r\n\x00-\x1F\x7F]/u', ' ', $subject) ?? '';
    $mail->addAddress($recipient, $safeHeaderName);
    $mail->isHTML(true);
    $mail->Subject = $safeHeaderSubject;

    $safeName = htmlspecialchars($safeHeaderName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeSubject = htmlspecialchars($safeHeaderSubject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $mail->Body = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:28px;color:#333">
            <div style="background:#641c32;color:#fff;padding:22px;border-radius:12px">
                <h2 style="margin:0">Africa University</h2>
                <p style="margin:8px 0 0">Student Success Hub</p>
            </div>
            <h2>Hello ' . $safeName . ',</h2>
            <h3>' . $safeSubject . '</h3>
            <p>' . $safeMessage . '</p>
            <hr>
            <small>Africa University Student Success Hub (AUSSH)</small>
        </div>';
    $mail->AltBody = "Hello {$safeHeaderName},\n\n{$message}\n\nAfrica University Student Success Hub (AUSSH)";
    $mail->send();
}