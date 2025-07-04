<?php
declare(strict_types=1);

namespace App\Command;

use App\Api\ChannelApiServer;
use App\Client\Bot;
use React\EventLoop\Loop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RunBotCommand extends Command
{
    use LockableTrait;

    protected static $defaultName = 'bot:run';

    private Bot               $bot;
    private ChannelApiServer  $api;

    public function __construct(Bot $bot, ChannelApiServer $api)
    {
        parent::__construct();
        $this->bot = $bot;
        $this->api = $api;
    }

    protected function configure(): void
    {
        $this->setDescription('Run Beholder bot and REST side-car');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        if (!$this->lock()) {
            $out->writeln('<error>The bot is already running.</error>');
            return Command::SUCCESS;
        }

        if (!\function_exists('pcntl_fork')) {
            $out->writeln('<error>ext-pcntl is not available.</error>');
            return Command::FAILURE;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            $out->writeln('<error>Could not fork.</error>');
            return Command::FAILURE;
        }

        if ($pid === 0) {
            $this->api->setParentPid(\posix_getppid());
            $this->api->start();
            Loop::get()->run();
            \exit(0);
        }

        $this->bot->connect();
        return Command::SUCCESS;
    }
}
