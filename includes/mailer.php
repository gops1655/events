<?php
declare(strict_types=1);

/**
 * Minimal dependency-free SMTP client. No Composer/PHPMailer available on
 * plain shared hosting, so this speaks just enough SMTP (EHLO, STARTTLS,
 * AUTH LOGIN, MAIL/RCPT/DATA) to deliver a single HTML email.
 */
final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $encryption; // 'ssl' | 'tls' | 'none'
    private string $user;
    private string $pass;
    private int $timeout;

    /** @var resource|null */
    private $sock = null;

    public function __construct(string $host, int $port, string $encryption, string $user, string $pass, int $timeout = 12)
    {
        $this->host = $host;
        $this->port = $port;
        $this->encryption = $encryption;
        $this->user = $user;
        $this->pass = $pass;
        $this->timeout = $timeout;
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $htmlBody): array
    {
        if (trim($this->host) === '' || trim($toEmail) === '') {
            return ['ok' => false, 'error' => 'SMTP host or recipient address missing.'];
        }
        try {
            $this->connect();
            $this->hello();
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed.');
                }
                $this->hello();
            }
            if ($this->user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->user), 334);
                $this->command(base64_encode($this->pass), 235);
            }
            $this->command('MAIL FROM:<' . $this->cleanAddr($fromEmail) . '>', 250);
            $this->command('RCPT TO:<' . $this->cleanAddr($toEmail) . '>', 250);
            $this->command('DATA', 354);
            $this->write($this->buildMessage($fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody));
            $this->command('.', 250);
            $this->command('QUIT', 221);
            $this->close();
            return ['ok' => true, 'error' => null];
        } catch (Throwable $e) {
            $this->close();
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function connect(): void
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->sock = @stream_socket_client($prefix . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            throw new RuntimeException('Could not connect to ' . $this->host . ':' . $this->port . ' (' . $errstr . ')');
        }
        stream_set_timeout($this->sock, $this->timeout);
        $this->readResponse(220);
    }

    private function hello(): void
    {
        $client = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
        $this->command('EHLO ' . $client, 250);
    }

    private function command(string $line, int $expect): string
    {
        $this->write($line . "\r\n");
        return $this->readResponse($expect);
    }

    private function write(string $data): void
    {
        if (!$this->sock || @fwrite($this->sock, $data) === false) {
            throw new RuntimeException('Failed writing to SMTP socket.');
        }
    }

    private function readResponse(int $expect): string
    {
        $buffer = '';
        while (!feof($this->sock)) {
            $line = fgets($this->sock, 1024);
            if ($line === false) {
                break;
            }
            $buffer .= $line;
            // Multiline responses use "code-text"; the final line uses "code text".
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        $code = (int) substr($buffer, 0, 3);
        if ($code !== $expect) {
            throw new RuntimeException('SMTP server replied: ' . trim($buffer));
        }
        return $buffer;
    }

    private function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function cleanAddr(string $addr): string
    {
        return trim(str_replace(["\r", "\n", '<', '>'], '', $addr));
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function buildMessage(string $fromEmail, string $fromName, string $toEmail, string $toName, string $subject, string $htmlBody): string
    {
        // Dot-stuff any line starting with '.' so SMTP doesn't treat it as end-of-data.
        $body = preg_replace('/^\./m', '..', $htmlBody) ?? $htmlBody;
        $headers = [
            'From: ' . $this->encodeHeader($fromName) . ' <' . $this->cleanAddr($fromEmail) . '>',
            'To: ' . $this->encodeHeader($toName) . ' <' . $this->cleanAddr($toEmail) . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['SERVER_NAME'] ?? 'localhost') . '>',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n";
    }
}
