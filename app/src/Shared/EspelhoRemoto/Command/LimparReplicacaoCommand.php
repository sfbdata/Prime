<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retenção da outbox do espelho (decisão do dono de 23/10, I4 da P2.3, LGPD): apaga as linhas de
 * envio verificadas, superadas ou descartadas paradas há mais de `--dias` dias (90 por padrão).
 * Pendente, processando, falhou e as exclusões registradas ficam.
 *
 * Apaga só a LINHA LOCAL da fila, nunca objeto no espelho. A saída é só contagem: nome de arquivo e
 * chave remota não saem aqui.
 */
#[AsCommand(
    name: 'armazenamento:limpar-replicacao',
    description: 'Apaga da outbox do espelho as linhas de envio concluídas há mais de N dias (90 por padrão).',
)]
final class LimparReplicacaoCommand extends Command
{
    public function __construct(private readonly OutboxDeReplicacao $outbox)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dias', null, InputOption::VALUE_REQUIRED, 'Idade mínima, em dias, da linha concluída que sai.', '90')
            ->addOption('simular', null, InputOption::VALUE_NONE, 'Só conta o que sairia, sem apagar nada.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $dias = filter_var($input->getOption('dias'), \FILTER_VALIDATE_INT);
        if ($dias === false || $dias < 1) {
            $io->error('--dias precisa ser um número inteiro de pelo menos 1.');

            return Command::INVALID;
        }

        $contagem = $this->outbox->contarConcluidasHaMaisDe($dias);
        $io->table(['estado', 'linhas com mais de ' . $dias . ' dias'], array_map(null, array_keys($contagem), array_values($contagem)));

        if ($input->getOption('simular')) {
            $io->note(sprintf('Simulação: %d linha(s) sairiam; nada foi apagado.', array_sum($contagem)));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d linha(s) apagada(s) da outbox.', $this->outbox->apagarConcluidasHaMaisDe($dias)));

        return Command::SUCCESS;
    }
}
