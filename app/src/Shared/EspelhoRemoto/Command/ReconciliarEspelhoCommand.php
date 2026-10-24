<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Reconciliacao\AlertaDaReconciliacao;
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
 *
 * `--se-ligado` é o modo do agendador (P2.5 e P2.7): sem `ESPELHO_R2_*` ou sem categoria ligada, sai com
 * sucesso e "espelho desligado". Ligado, confere só as categorias da política e sai com FALHA quando há
 * divergência que exige ação ({@see AlertaDaReconciliacao}), para o e-mail do agendador avisar. A
 * mensagem é só contagem por classe e por categoria.
 */
#[AsCommand(
    name: 'armazenamento:reconciliar-espelho',
    description: 'Compara banco, disco local e espelho remoto (presença, tamanho, SHA-256). Somente leitura.',
)]
final class ReconciliarEspelhoCommand extends Command
{
    public function __construct(
        private readonly ReconciliadorDoEspelho $reconciliador,
        private readonly ProvedorDoEspelhoRemoto $fabrica,
        private readonly PoliticaDeEspelhamento $politica,
        private readonly OutboxDeReplicacao $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sem-sha', null, InputOption::VALUE_NONE, 'Não calcula o SHA-256 do disco (confere só presença e tamanho).')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Para depois de N referências (amostra).')
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON com as divergências (fonte, id, classe).')
            ->addOption('se-ligado', null, InputOption::VALUE_NONE, 'Modo agendado: desligado, sai com sucesso sem fazer nada; ligado, confere só as categorias ligadas e falha quando há divergência que exige ação.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limite = $input->getOption('limite');
        if ($limite !== null && (!ctype_digit((string) $limite) || (int) $limite < 1)) {
            $io->error('--limite precisa ser um inteiro maior que zero.');

            return Command::INVALID;
        }
        $limite   = $limite === null ? null : (int) $limite;
        $seLigado = (bool) $input->getOption('se-ligado');

        if ($seLigado && !($this->politica->estaLigada() && $this->fabrica->estaConfigurado())) {
            $io->writeln('Espelho desligado: nada a reconciliar.');

            return Command::SUCCESS;
        }

        try {
            $espelho = $this->fabrica->criar();
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $relatorio = $this->reconciliador->reconciliar($espelho, !$input->getOption('sem-sha'), $limite, $seLigado ? $this->politica : null);

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

        if (!$seLigado) {
            return Command::SUCCESS;
        }

        $alerta = AlertaDaReconciliacao::avaliar($relatorio, $this->outbox->contarFalhasPorCategoria());
        if (!$alerta->disparou()) {
            $io->writeln('Sem divergência que exija ação nas categorias ligadas.');

            return Command::SUCCESS;
        }

        $io->error(array_merge(['Divergência no espelho que exige ação (só contagens):'], $alerta->linhas()));

        return Command::FAILURE;
    }
}
