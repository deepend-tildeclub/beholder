<?php
declare(strict_types=1);

namespace App\Api;

use App\Client\Bot;
use App\ConfigurationInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Throwable;

final class ChannelApiServer
{
    private Bot                    $bot;
    private ConfigurationInterface $cfg;
    private HttpServer             $http;
    private SocketServer           $socket;
    private int                    $parentPid = 0;

    public function __construct(Bot $bot, ConfigurationInterface $cfg)
    {
        $this->bot = $bot;
        $this->cfg = $cfg;
    }

    public function setParentPid(int $pid): void { $this->parentPid = $pid; }

    public function start(): void
    {
        $this->http   = new HttpServer(fn ($r) => $this->wrap($r));
        $this->socket = new SocketServer(
            $this->cfg->getApiHost() . ':' . $this->cfg->getApiPort()
        );
        $this->http->listen($this->socket);

        if ($this->cfg->isDebugMode()) {
            echo "[API] listening on http://{$this->cfg->getApiHost()}:{$this->cfg->getApiPort()}\n";
        }
    }

    private function wrap(ServerRequestInterface $r): Response
    {
        try { return $this->handle($r); }
        catch (Throwable $e) {
            if ($this->cfg->isDebugMode()) echo $e;
            return $this->json(500, ['error' => 'internal']);
        }
    }

    private function norm(string $raw): string
    {
        return ltrim(\rawurldecode($raw), '#');
    }

    private function handle(ServerRequestInterface $req): Response
    {
        if ($req->getHeaderLine('X-API-Key') !== $this->cfg->getApiKey()) {
            return $this->json(401, ['error' => 'unauthorised']);
        }

        $m = \strtoupper($req->getMethod());
        $p = \rtrim($req->getUri()->getPath(), '/');

        if ($m === 'GET' && $p === '/channels') {
            return $this->json(200, ['channels' => $this->bot->getChannels()]);
        }

        if ($m === 'POST' && $p === '/channels') {
            $b = \json_decode((string) $req->getBody(), true);
            if (empty($b['channel'])) {
                return $this->json(400, ['error' => 'channel required']);
            }
            $ch = $this->norm($b['channel']);
            $this->bot->setUpChannel($ch);
            $this->signalParent();
            return $this->json(200, ['added' => "#{$ch}"]);
        }

        if ($m === 'DELETE' && \str_starts_with($p, '/channels/')) {
            $ch = $this->norm(\substr($p, 10));
            $this->bot->tearDownChannel($ch);
            $this->signalParent();
            return $this->json(200, ['removed' => "#{$ch}"]);
        }

        if ($m === 'DELETE' && $p === '/channels') {
            $b = \json_decode((string) $req->getBody(), true);
            if (empty($b['channel'])) {
                return $this->json(400, ['error' => 'channel required']);
            }
            $ch = $this->norm($b['channel']);
            $this->bot->tearDownChannel($ch);
            $this->signalParent();
            return $this->json(200, ['removed' => "#{$ch}"]);
        }

        if ($m === 'POST' && $p === '/behold') {
            $b = \json_decode((string) $req->getBody(), true);
            if (empty($b['channel'])) {
                return $this->json(400, ['error' => 'channel required']);
            }
            $ch = $this->norm($b['channel']);
            $this->bot->setUpBeholdChannel($ch);
            return $this->json(200, ['behold_added' => "#{$ch}"]);
        }

        if ($m === 'DELETE' && \str_starts_with($p, '/behold/')) {
            $ch = $this->norm(\substr($p, 8));
            $this->bot->tearDownBeholdChannel($ch);
            return $this->json(200, ['behold_removed' => "#{$ch}"]);
        }

        if ($m === 'DELETE' && $p === '/behold') {
            $b = \json_decode((string) $req->getBody(), true);
            if (empty($b['channel'])) {
                return $this->json(400, ['error' => 'channel required']);
            }
            $ch = $this->norm($b['channel']);
            $this->bot->tearDownBeholdChannel($ch);
            return $this->json(200, ['behold_removed' => "#{$ch}"]);
        }

        return $this->json(404, ['error' => 'not found']);
    }

    private function json(int $code, array $payload): Response
    {
        return new Response(
            $code,
            ['Content-Type' => 'application/json'],
            \json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function signalParent(): void
    {
        if ($this->parentPid > 0) \posix_kill($this->parentPid, \SIGUSR1);
    }
}
