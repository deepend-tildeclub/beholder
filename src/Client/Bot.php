<?php
declare(strict_types=1);

namespace App\Client;

use App\ConfigurationInterface;
use App\Modules\Behold\BeholdModule;
use App\Modules\CommandList\CommandListModule;
use App\Modules\Lottery\LotteryModule;
use App\Modules\Quotes\Persistence\MySQL as QuotesMySQL;
use App\Modules\Quotes\QuotesModule;
use App\Persistence\Core\PersistenceInterface;
use React\EventLoop\Loop;

class Bot extends Client
{
    protected ConfigurationInterface $config;
    protected array                  $channels        = [];
    protected array                  $beholdChannels  = [];
    protected PersistenceInterface   $persistence;
    protected array                  $modules         = [];

    private bool $pendingChannelSync = false;
    private bool $debug;

    public function __construct(ConfigurationInterface $cfg,
                                PersistenceInterface   $persistence)
    {
        parent::__construct(
            $cfg->getDesiredNick(),
            ($cfg->useTls() ? 'tls://' : '') . $cfg->getHost(),
            $cfg->getPort()
        );

        $this->config      = $cfg;
        $this->persistence = $persistence;
        $this->persistence->prepare();
        $this->debug       = $cfg->isDebugMode();

        $this->setName($cfg->getUsername());
        $this->setRealName($cfg->getRealName());
        $this->reconnectInterval = 10;

        $this->initializeChannelLists();
        $this->registerConnectionHandlingListeners();
        $this->registerChannelControlListeners();

        if ($this->debug) {
            $this->on('message', fn ($e) => print "[RAW-IN] {$e->raw}\n");
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGUSR1, fn () => $this->pendingChannelSync = true);
        }
        Loop::addPeriodicTimer(2, fn () => $this->pendingChannelSync && $this->syncChannelList());

        $db = $cfg->getDatabaseCredentials();
        $this->modules = [
            new CommandListModule($this, $cfg),
            new QuotesModule($this, $cfg, new QuotesMySQL($db)),
            new BeholdModule($this, $cfg, new \App\Modules\Behold\Persistence\MySQL($db)),
        ];
        foreach ($this->modules as $m) { $m->prepare(); }
        foreach ($this->modules as $m) { $m->boot();    }
    }

    private function ensureHash(string $ch): string
    {
        return str_starts_with($ch, '#') ? $ch : "#{$ch}";
    }
    private function joinChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        if ($this->debug) echo "[DBG-OUT] JOIN {$ch}\n";
        parent::join($ch);
    }
    private function partChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        if ($this->debug) echo "[DBG-OUT] PART {$ch}\n";
        parent::part($ch);
    }

    private function syncChannelList(): void
    {
        $latest = method_exists($this->persistence, 'refreshChannels')
            ? $this->persistence->refreshChannels()
            : array_map('strtolower', $this->persistence->getChannels());

        foreach (array_diff($latest, $this->channels) as $ch) { $this->join($ch); }
        foreach (array_diff($this->channels, $latest) as $ch) { $this->part($ch); }

        $this->channels = $latest;
        $this->pmBotAdmin('Channel list reloaded (deferred)');
    }

    private function initializeChannelLists(): void
    {
        $this->channels       = array_map([$this,'ensureHash'],$this->persistence->getChannels());
        $this->beholdChannels = array_map([$this,'ensureHash'],$this->persistence->getBeholdChannels());
    }

    protected function registerConnectionHandlingListeners(): void
    {
        $this->on('disconnected', fn () => print "Disconnected.\n");

        $this->on('welcome', function () {
            foreach ($this->channels as $ch) { $this->joinChannel($ch); }
        });
    }

    protected function registerChannelControlListeners(): void
    {
        if (!$this->config->hasBotAdmin()) return;

        $this->on('pm:' . $this->getNick() . ':' . $this->config->getBotAdminNick(),
            function ($e) {
                if (preg_match('/^please (?<a>join|leave|behold\+|behold-) (?<c>\S+) now$/', $e->text, $m)) {
                    $ch = $this->ensureHash($m['c']);
                    match($m['a']) {
                        'join'      => $this->setUpChannel($ch),
                        'leave'     => $this->tearDownChannel($ch),
                        'behold+'   => $this->setUpBeholdChannel($ch),
                        'behold-'   => $this->tearDownBeholdChannel($ch),
                    };
                }
        });
    }

    public function setUpChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        if ($this->isBotMemberOfChannel($ch)) return;
        $this->joinChannel($ch);
        $this->channels = $this->persistence->addChannel($ch);
    }
    public function tearDownChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        $this->partChannel($ch);
        $this->channels = $this->persistence->removeChannel($ch);
    }

    public function setUpBeholdChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        if (in_array($ch, $this->beholdChannels, true)) return;
        $this->beholdChannels = $this->persistence->addBeholdChannel($ch);
    }
    public function tearDownBeholdChannel(string $ch): void
    {
        $ch = $this->ensureHash($ch);
        $this->beholdChannels = $this->persistence->removeBeholdChannel($ch);
    }

    public function isBotMemberOfChannel(string $ch): bool
    {
        return in_array(strtolower($ch), array_map('strtolower',$this->channels), true);
    }
    public function getChannels(): array       { return $this->channels;       }
    public function getBeholdChannels(): array { return $this->beholdChannels; }
}
