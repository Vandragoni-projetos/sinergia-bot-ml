<?php

declare(strict_types=1);

namespace Sinergia\Cli\Command;

use Psr\Container\ContainerInterface;
use Sinergia\Application\Queue\BotWorker;
use Sinergia\Shared\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Worker do bot (2º serviço no EasyPanel, mesma imagem): a cada ciclo processa as contas com bot ATIVO.
 * Vários workers podem rodar ao mesmo tempo: a trava por conta e a reserva atômica dos itens evitam envio duplicado.
 */
#[AsCommand(name: 'bot:worker', description: 'Planeja e publica a fila das contas com bot ativo (laço contínuo).')]
final class BotWorkerCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Executa um único ciclo e termina')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Segundos entre ciclos', '60');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(10, min(600, (int) $input->getOption('interval')));
        $workerId = substr((gethostname() ?: 'worker') . ':' . getmypid() . ':' . bin2hex(random_bytes(3)), 0, 64);
        $clock = $this->container->get(Clock::class);
        $startedAt = $clock->now();
        $worker = $this->container->get(BotWorker::class);

        do {
            $summary = $worker->runOnce($workerId, $startedAt);
            $output->writeln(sprintf('[%s] %s', $clock->now()->format('Y-m-d H:i:s'), json_encode($summary, JSON_UNESCAPED_UNICODE)));
            if ($input->getOption('once')) {
                break;
            }
            sleep($interval);
        } while (true);

        return Command::SUCCESS;
    }
}
