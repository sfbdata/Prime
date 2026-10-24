<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Reconciliacao\AlertaDaReconciliacao;
use App\Shared\EspelhoRemoto\Reconciliacao\ReconciliadorDoEspelho;
use App\Shared\EspelhoRemoto\Reconciliacao\RelatorioDeReconciliacao;
use Psr\Clock\ClockInterface;
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
 *
 * `--fatia`: `todas` (padrão, à mão) confere o SHA de tudo; `auto` (o agendador) confere o SHA só da
 * fatia do dia da semana em Brasília (domingo = 0), e presença e tamanho de tudo; `0` a `6` escolhe a
 * fatia. A fatia de cada referência é estável ({@see ReconciliadorDoEspelho::fatiaDe()}).
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
        private readonly ClockInterface $relogio,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sem-sha', null, InputOption::VALUE_NONE, 'Não calcula o SHA-256 do disco (confere só presença e tamanho).')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Para depois de N referências (amostra).')
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Arquivo JSON com as divergências (fonte, id, classe).')
            ->addOption('fatia', null, InputOption::VALUE_REQUIRED, 'SHA de "todas" as referências, da fatia do dia ("auto") ou de uma fatia de 0 a 6.', 'todas')
            ->addOption('se-ligado', null, InputOption::VALUE_NONE, 'Modo agendado: desligado, sai com sucesso sem fazer nada; ligado, confere só as categorias ligadas e falha quando há divergência que exige ação.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $opcoes = $this->opcoes($input, $io);
        if ($opcoes === null) {
            return Command::INVALID;
        }
        [$limite, $fatia] = $opcoes;
        $seLigado         = (bool) $input->getOption('se-ligado');

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

        $comSha    = !$input->getOption('sem-sha');
        $relatorio = $this->reconciliador->reconciliar($espelho, $comSha, $limite, $seLigado ? $this->politica : null, $fatia);
        $this->mostrar($io, $relatorio, $comSha, $fatia);

        $saida = $input->getOption('saida');
        if (\is_string($saida) && $saida !== '' && !$this->gravarRelatorio($io, $relatorio, $saida)) {
            return Command::FAILURE;
        }

        return $seLigado ? $this->alertar($io, $relatorio) : Command::SUCCESS;
    }

    /** @return array{0: ?int, 1: ?int}|null limite e fatia; null = opção inválida (o erro já saiu) */
    private function opcoes(InputInterface $input, SymfonyStyle $io): ?array
    {
        $limite = $input->getOption('limite');
        if ($limite !== null && (!ctype_digit((string) $limite) || (int) $limite < 1)) {
            $io->error('--limite precisa ser um inteiro maior que zero.');

            return null;
        }

        $fatia = $this->fatia((string) $input->getOption('fatia'));
        if ($fatia === false) {
            $io->error(sprintf('--fatia precisa ser "todas", "auto" ou um inteiro de 0 a %d.', ReconciliadorDoEspelho::FATIAS_DO_SHA - 1));

            return null;
        }

        return [$limite === null ? null : (int) $limite, $fatia];
    }

    private function mostrar(SymfonyStyle $io, RelatorioDeReconciliacao $relatorio, bool $comSha, ?int $fatia): void
    {
        $io->title('Reconciliação do espelho (somente leitura)');
        $io->writeln(match (true) {
            !$comSha        => 'SHA-256: não conferido (--sem-sha).',
            $fatia === null => 'SHA-256: conferido em todas as referências.',
            default         => sprintf('SHA-256: conferido na fatia %d de %d.', $fatia, ReconciliadorDoEspelho::FATIAS_DO_SHA),
        });
        $io->table(['classe', 'referências'], array_map(null, array_keys($relatorio->contagem()), array_values($relatorio->contagem())));
        $io->writeln(sprintf('Total: %d referência(s); %d fora de "igual".', $relatorio->total(), \count($relatorio->divergencias())));
    }

    /** null = SHA de todas; int = a fatia; false = opção inválida. */
    private function fatia(string $opcao): int|false|null
    {
        if ($opcao === 'todas') {
            return null;
        }
        if ($opcao === 'auto') {
            return (int) $this->relogio->now()->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('w') % ReconciliadorDoEspelho::FATIAS_DO_SHA;
        }

        return ctype_digit($opcao) && (int) $opcao < ReconciliadorDoEspelho::FATIAS_DO_SHA ? (int) $opcao : false;
    }

    private function gravarRelatorio(SymfonyStyle $io, RelatorioDeReconciliacao $relatorio, string $saida): bool
    {
        $json = json_encode(['contagem' => $relatorio->contagem(), 'divergencias' => $relatorio->divergencias()], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        if (file_put_contents($saida, $json) === false) {
            $io->error('Não foi possível gravar o relatório em ' . $saida);

            return false;
        }
        $io->writeln('Relatório gravado em ' . $saida);

        return true;
    }

    /** O critério da P2.7: falha (e o agendador avisa) quando há divergência que exige ação. */
    private function alertar(SymfonyStyle $io, RelatorioDeReconciliacao $relatorio): int
    {
        $alerta = AlertaDaReconciliacao::avaliar($relatorio, $this->outbox->contarFalhasPorCategoria());
        if (!$alerta->disparou()) {
            $io->writeln('Sem divergência que exija ação nas categorias ligadas.');

            return Command::SUCCESS;
        }

        $io->error(array_merge(['Divergência no espelho que exige ação (só contagens):'], $alerta->linhas()));

        return Command::FAILURE;
    }
}
