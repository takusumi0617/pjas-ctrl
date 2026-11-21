<?php
declare(strict_types=1);

namespace Pjas\Domain;

/** The upstream's cookie stays in the ctrl server session. */
final class SecurityGateway
{
    public function __construct(private string $origin)
    {
        if (!preg_match('~\Ahttps://[a-z0-9.-]+(?::[0-9]{1,5})?\z~D', $origin)) {
            throw new \InvalidArgumentException('pjas-security の HTTPS オリジンを設定してください');
        }
    }

    public function request(string $path, ?array $body, array $session, ?array &$login = null): array
    {
        $headers = ['Accept: application/json', 'Connection: close'];
        if (isset($session['token'], $session['csrf'])) {
            $headers[] = 'Cookie: pjas_session='.$session['token'];
            $headers[] = 'X-CSRF-Token: '.$session['csrf'];
        }
        $data = '';
        if ($body !== null) {
            $headers[] = 'Origin: '.$this->origin;
            $headers[] = 'Content-Type: application/json';
            $data = json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        $context = stream_context_create([
            'http'=>[
                'method'=>$body === null ? 'GET' : 'POST', 'header'=>$headers, 'content'=>$data,
                'follow_location'=>0, 'timeout'=>4, 'ignore_errors'=>true,
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true],
        ]);
        $stream = @fopen($this->origin.'/api/v1/'.$path, 'rb', false, $context);
        if (!$stream) throw new \RuntimeException('pjas-security に接続できません');
        try {
            $meta = stream_get_meta_data($stream);
            $status = 0;
            $token = null;
            foreach ($meta['wrapper_data'] as $line) {
                if (preg_match('~^HTTP/\S+ (\d{3})\b~', $line, $match)) $status = (int)$match[1];
                if (preg_match('/^Set-Cookie:\s*pjas_session=([A-Za-z0-9_-]{43});/i', $line, $match)) $token = $match[1];
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('pjas-security が処理を受理しませんでした (HTTP '.$status.')', $status);
            }
            $json = stream_get_contents($stream, 2097153);
            if ($json === false || strlen($json)>2097152 || stream_get_meta_data($stream)['timed_out']) {
                throw new \RuntimeException('pjas-security の応答を取得できません');
            }
            $response = json_decode($json, true);
            if (!is_array($response)) throw new \RuntimeException('pjas-security の応答形式が不正です');
            if ($path === 'auth/login') {
                $csrf = $response['csrf_token'] ?? '';
                if ($token === null || !is_string($csrf) || !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $csrf)) {
                    throw new \RuntimeException('pjas-security のセッションを取得できません');
                }
                $login = ['token'=>$token, 'csrf'=>$csrf];
            }
            return $response;
        } finally {
            fclose($stream);
        }
    }

    public static function siteForEvent(array $event, array $domains): ?string
    {
        if (!in_array($event['category'] ?? '', ['apache','nginx'], true)
            || !preg_match('/ HTTP [45][0-9]{2}（([a-z0-9.-]+)(?::[0-9]+)?）/i', (string)($event['title'] ?? ''), $match)) {
            return null;
        }
        return $domains[strtolower(rtrim($match[1], '.'))] ?? null;
    }
}
