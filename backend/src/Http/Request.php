<?php
declare(strict_types=1);

namespace Postvak\Http;

final class Request
{
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public array $params = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Path after /api, e.g. "/accounts/3/folders".
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos = strpos($uri, '/api');
        $path = $pos === false ? $uri : substr($uri, $pos + 4);
        $path = '/' . trim($path, '/');
        if (str_starts_with($path, '/index.php')) {
            $path = '/' . ltrim(substr($path, 10), '/');
        }

        return new self($method, $path);
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    /** JSON body as array. */
    public function body(): array
    {
        if ($this->json === null) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = $raw === '' ? [] : json_decode($raw, true);
            $this->json = is_array($decoded) ? $decoded : [];
        }
        return $this->json;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body()[$key] ?? $_POST[$key] ?? $default;
    }

    /** Required string input; throws 400 when missing or empty. */
    public function string(string $key): string
    {
        $value = $this->input($key);
        if (!is_string($value) || trim($value) === '') {
            throw HttpException::badRequest("Field '$key' is required");
        }
        return trim($value);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function param(string $key): string
    {
        return $this->params[$key] ?? '';
    }

    public function intParam(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }
}
