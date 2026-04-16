<?php
/**
 * AgentCore — Telegram API Client
 *
 * Lightweight wrapper around the Telegram Bot API.
 */

class TelegramApi
{
    private string $token;
    private int $timeout;

    public function __construct(string $token, int $timeout = 30)
    {
        $this->token = $token;
        $this->timeout = $timeout;
    }

    public function getMe(): ?array
    {
        return $this->request('getMe');
    }

    public function getUpdates(int $offset = 0, int $timeout = 30): array
    {
        $result = $this->request('getUpdates', [
            'offset'  => $offset,
            'timeout' => $timeout,
        ]);
        return $result ?? [];
    }

    public function sendMessage(string $chatId, string $text, string $parseMode = ''): ?array
    {
        // Telegram max message length is 4096
        if (mb_strlen($text) > 4096) {
            return $this->sendLongMessage($chatId, $text, $parseMode);
        }

        $params = [
            'chat_id' => $chatId,
            'text'    => $text,
        ];
        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        return $this->request('sendMessage', $params);
    }

    public function sendTyping(string $chatId): void
    {
        $this->request('sendChatAction', [
            'chat_id' => $chatId,
            'action'  => 'typing',
        ]);
    }

    private function sendLongMessage(string $chatId, string $text, string $parseMode): ?array
    {
        $chunks = $this->splitMessage($text, 4000);
        $lastResult = null;

        foreach ($chunks as $i => $chunk) {
            if (count($chunks) > 1) {
                $chunk = "(" . ($i + 1) . "/" . count($chunks) . ")\n" . $chunk;
            }
            $lastResult = $this->sendMessage($chatId, $chunk, $parseMode);
            if ($i < count($chunks) - 1) {
                usleep(500000); // 0.5s between chunks
            }
        }

        return $lastResult;
    }

    private function splitMessage(string $text, int $maxLen): array
    {
        $chunks = [];
        while (mb_strlen($text) > $maxLen) {
            // Try to split at a newline
            $cutPos = mb_strrpos(mb_substr($text, 0, $maxLen), "\n");
            if ($cutPos === false || $cutPos < $maxLen / 2) {
                $cutPos = $maxLen;
            }
            $chunks[] = mb_substr($text, 0, $cutPos);
            $text = mb_substr($text, $cutPos);
        }
        if (mb_strlen($text) > 0) {
            $chunks[] = $text;
        }
        return $chunks;
    }

    private function request(string $method, array $params = []): ?array
    {
        $url = "https://api.telegram.org/bot{$this->token}/{$method}";

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout + 10,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return null;
        }

        $data = json_decode($response, true);
        if (!$data || !($data['ok'] ?? false)) {
            return null;
        }

        // Some Telegram methods (sendChatAction, deleteMessage, etc.) return bool true
        // in the `result` field. This function is typed ?array, so coerce non-arrays to null —
        // callers that need the payload (getUpdates, sendMessage) always receive arrays anyway.
        $result = $data['result'] ?? null;
        return is_array($result) ? $result : null;
    }
}
