<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\FabricaDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconciliação banco × disco × espelho, **só leitura** (spec P2 §3.4, §14). Não existe modo que
 * corrija: o comando só relata.
 *
 * `--saida` grava o relatório em JSON com fonte, id da linha e classe — sem nome de arquivo nem chave
 * remota. `--sem-sha` pula o SHA-256 do disco (rápido; confere só presença e tamanho).
 */
#[AsCommand(
    name: 'armazenamento:reconciliar-espelho',
    description: 'Compara banco, disco local e espelho remoto (presença, tamanho, SHA-256). Somente leitura.',
)]
final class ReconciliarEspelhoCommand extends Command
{
    public function __construct(
        private readonly ReconciliadorDoEspelho $reconciliador,
        private readonly FabricaDoEspelhoRemoto $fabrica,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sem-sha', null, InputOption::VALUE_NONE, 'Não calcula o SHA-256 do disco (confere só presença e tamanho).')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Para depois de N referências (amostra).')
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON com as divergências (fonte, id, classe).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $espelho = $this->fabrica->criar();
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $limite    = $input->getOption('limite') === null ? null : max(1, (int) $input->getOption('limite'));
        $relatorio = $this->reconciliador->reconciliar($espelho, !$input->getOption('sem-sha'), $limite);

        $io->title('Reconciliação do espelho (somente leitura)');
        $io->table(['classe', 'referências'], array_map(null, array_keys($relatorio->contagem()), array_values($relatorio->contagem())));
        $io->writeln(sprintf('Total: %d referência(s); %d fora de "igual".', $relatorio->total(), \count($relatorio->divergencias())));

        $saida = $input->getOption('saida');
        if (\is_string($saida) && $saida !== '') {
            $json = json_encode(['contagem' => $relatorio->contagem(), 'divergencias' => $relatorio->divergencias()], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
            if (file_put_contents($saida, $json) === false) {
                $io->error('Não foi possível gravar o relatório em ' . $saida);

                return Command::FAILURE;
            }
            $io->writeln('Relatório gravado em ' . $saida);
        }

        return Command::SUCCESS;
    }
}
