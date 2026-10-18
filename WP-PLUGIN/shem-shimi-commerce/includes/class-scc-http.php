<?php
if (!defined('ABSPATH')) exit;
/** Request wrapper handed to every route handler. */
final class Scc_Request {
    public string $method; public string $path; public array $params = []; public array $query = [];
    public array $headers = []; public string $raw = ''; private $json = null;
    public function __construct() {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $this->path = '/' . trim(rawurldecode($uri), '/');
        $this->query = $_GET;
        foreach ($_SERVER as $k => $v) if (strpos($k, 'HTTP_') === 0) $this->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        if (isset($_SERVER['CONTENT_TYPE'])) $this->headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        $this->raw = (string) file_get_contents('php://input');
    }
    public function header(string $n): string { return (string) ($this->headers[strtolower($n)] ?? ''); }
    /** Decoded JSON body, [] when empty/invalid. */
    public function json(): array {
        if ($this->json === null) { $d = json_decode($this->raw, true); $this->json = is_array($d) ? $d : []; }
        return $this->json;
    }
}
/** Response. Errors follow the original worker shape: {"error": "<hebrew message>"}. */
final class Scc_Response {
    public function __construct(public int $status = 200, public mixed $body = null, public array $headers = [], public bool $raw = false) {}
    public static function json(mixed $data, int $status = 200, array $headers = []): self { return new self($status, $data, $headers); }
    public static function error(string $msg, int $status = 400, array $extra = []): self { return new self($status, ['error' => $msg] + $extra); }
    /** Binary/text body (PDF, CSV...). */
    public static function raw(string $bytes, string $type, int $status = 200, array $headers = []): self { return new self($status, $bytes, ['content-type' => $type] + $headers, true); }
    public function send(): never {
        status_header($this->status);
        nocache_headers();
        foreach ($this->headers as $k => $v) header($k . ': ' . $v);
        if ($this->raw) { echo $this->body; }
        else { header('content-type: application/json; charset=utf-8'); echo wp_json_encode($this->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        exit;
    }
}
