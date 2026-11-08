<?php
declare(strict_types=1);

namespace StretchEffect\Mail;

/** Small SMTP client: implicit TLS (port 465) or STARTTLS (587), AUTH LOGIN. */
final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private readonly string $from,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $pass,
        private readonly string $secure = 'ssl', // ssl | tls | none
    ) {
    }

    public function send(string $to, string $subject, string $text, string $html): void
    {
        $m = MimeMessage::build($this->from, $to, $subject, $text, $html);
        $fromAddr = preg_match('/<([^>]+)>/', $this->from, $mm) ? $mm[1] : $this->from;
        try {
            $remote = ($this->secure === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
            $this->sock = @stream_socket_client($remote, $errno, $errstr, 10);
            if (!$this->sock) {
                throw new \RuntimeException("SMTP connect failed: $errstr");
            }
            stream_set_timeout($this->sock, 15);
            $this->expect(220);
            $this->cmd('EHLO stretch-effect', 250);
            if ($this->secure === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS failed');
                }
                $this->cmd('EHLO stretch-effect', 250);
            }
            if ($this->user !== '') {
                $this->cmd('AUTH LOGIN', 334);
                $this->cmd(base64_encode($this->user), 334);
                $this->cmd(base64_encode($this->pass), 235);
            }
            $this->cmd('MAIL FROM:<' . MimeMessage::clean($fromAddr) . '>', 250);
            $this->cmd('RCPT TO:<' . MimeMessage::clean($to) . '>', 250);
            $this->cmd('DATA', 354);
            $data = $m['headers'] . "\r\n\r\n" . $m['body'];
            $data = preg_replace('/^\./m', '..', $data) ?? $data;
            $this->cmd($data . "\r\n.", 250);
            $this->cmd('QUIT', 221);
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    private function cmd(string $line, int $expect): void
    {
        fwrite($this->sock, $line . "\r\n");
        $this->expect($expect);
    }

    private function expect(int $code): void
    {
        $resp = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        if ((int) substr($resp, 0, 3) !== $code) {
            throw new \RuntimeException('SMTP error, expected ' . $code . ' got: ' . trim(substr($resp, 0, 120)));
        }
    }
}
