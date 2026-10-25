<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\Replica\ReplicadorDeArquivos;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * O worker da réplica: processa até N linhas da outbox e sai (spec P2 §14). Feito para cron/laço
 * na P2.5 — fora da requisição HTTP. Dois ao mesmo tempo são seguros: a reivindicação é atômica e
 * uma chave nunca fica com duas linhas em processamento.
 *
 * A saída é só contagem. Nome de arquivo e chave remota não saem aqui.
 */
#[AsCommand(
    name: 'armazenamento:replicar-espelho',
    description: 'Leva ao espelho remoto (R2) os envios pendentes da outbox. Nunca apaga nada no espelho.',
)]
final class ReplicarEspelhoCommand extends Command
{
    public function __construct(
        private readonly ReplicadorDeArquivos $replicador,
        private readonly FabricaDoEspelhoRemoto $fabrica,
        private readonly OutboxDeReplicacao $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Quantas linhas processar nesta rodada.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $limite = (int) $input->getOption('limite');
        if ($limite < 1) {
            $io->error('--limite precisa ser pelo menos 1.');

            return Command::INVALID;
        }

        try {
            $espelho = $this->fabrica->criar();
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $resumo = $this->replicador->processar($espelho, $limite);

        $io->table(['rodada', 'quantidade'], array_map(null, array_keys($resumo->comoLista()), array_values($resumo->comoLista())));
        $io->table(['outbox (estado)', 'linhas'], array_map(null, array_keys($this->outbox->contarPorEstado()), array_values($this->outbox->contarPorEstado())));

        return Command::SUCCESS;
    }
}
