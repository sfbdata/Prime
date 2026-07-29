<?php

declare(strict_types=1);

namespace App\AtualizacaoMonetaria\Command;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Exception\ImportacaoIndicesException;
use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\AtualizacaoMonetaria\Service\ClienteSgsBcbInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa as séries oficiais de índices do Banco Central para a tabela global `indice_monetario`.
 *
 * Serve à carga histórica (de 01/1994 em diante) e ao incremento mensal — é o mesmo comando, porque
 * é **idempotente**: reimportar o que já está gravado não duplica, não altera valor e não mexe nem
 * no carimbo `importado_em`. Agendado em cron mensal (o INPC de um mês sai por volta do dia 7–10 do
 * mês seguinte).
 *
 * Duas guardas de dinheiro, ambas exigidas pela spec §8:
 *
 * 1. **Série vazia não apaga nem zera nada, e ALARMA.** A API do BCB já devolveu lista vazia com o
 *    dado publicado (a armadilha dos filtros de data, medida em 29/07/2026). Tratar isso como
 *    sucesso deixaria a tabela silenciosamente incompleta — e um mês faltando no meio da série é
 *    correção monetária a menos, sem nada acusando.
 * 2. **Revisão de índice já gravado é REGISTRADA, nunca silenciosa.** O IBGE revisa índice
 *    publicado; um mês que muda depois altera dinheiro em simulação já impressa. Cada sobrescrita
 *    sai na tela e no log, com o valor anterior e o novo.
 *
 * Não é multi-tenant de propósito (spec §7.1): a tabela é global e nenhum dado de escritório entra.
 */
#[AsCommand(
    name: 'app:importar-indices-monetarios',
    description: 'Importa INPC, IPCA e taxa legal do Banco Central para a tabela global de índices.',
)]
final class ImportarIndicesMonetariosCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly ClienteSgsBcbInterface $cliente,
        private readonly IndiceMonetarioRepository $repositorio,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('serie', null, InputOption::VALUE_REQUIRED, 'Restringe a uma série (INPC, IPCA ou TAXA_LEGAL).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula: consulta o BCB e relata, mas não grava.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $series = $this->resolverSeries($input, $io);
        if ($series === null) {
            return Command::FAILURE;
        }

        if ($this->adquirirLock() === false) {
            $io->warning('Outra importação de índices já está em andamento. Abortando.');

            return Command::SUCCESS;
        }

        try {
            if ($dryRun === true) {
                $io->note('Modo simulação (--dry-run): nada será gravado.');
            }

            $agora = new \DateTimeImmutable();
            $linhas = [];
            $revisoes = [];
            $falhas = 0;
            $vazias = 0;
            $totalNovos = 0;
            $totalRevisados = 0;
            $totalInalterados = 0;

            foreach ($series as $serie) {
                try {
                    $variacoes = $this->cliente->baixarSerie($serie);
                } catch (ImportacaoIndicesException $e) {
                    ++$falhas;
                    $io->error(sprintf('Série %s: %s', $serie->value, $e->getMessage()));
                    $this->logger->error('Falha ao importar série de índices.', [
                        'serie' => $serie->value,
                        'erro' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($variacoes === []) {
                    // NÃO apaga nem zera: o que está gravado continua valendo. Só alarma.
                    ++$vazias;
                    $io->error(sprintf(
                        'Série %s voltou VAZIA do BCB — nada foi apagado, mas a série pode estar desatualizada.',
                        $serie->value,
                    ));
                    $this->logger->error('Série de índices voltou vazia do BCB.', ['serie' => $serie->value]);

                    continue;
                }

                $resumo = $this->importarSerie($serie, $variacoes, $agora, $dryRun, $revisoes);

                $totalNovos += $resumo['novos'];
                $totalRevisados += $resumo['revisados'];
                $totalInalterados += $resumo['inalterados'];
                $linhas[] = [
                    $serie->value,
                    $resumo['novos'],
                    $resumo['revisados'],
                    $resumo['inalterados'],
                    $resumo['primeira'],
                    $resumo['ultima'],
                ];
            }

            if ($dryRun === false) {
                $this->em->flush();
            }

            if ($linhas !== []) {
                $io->table(['Série', 'Novos', 'Revisados', 'Inalterados', 'Primeira', 'Última'], $linhas);
            }

            if ($revisoes !== []) {
                $io->text('<comment>Índices REVISADOS pela fonte (valor sobrescrito):</comment>');
                $io->table(['Série', 'Competência', 'Anterior', 'Novo'], $revisoes);
            }

            $io->writeln(sprintf(
                '%d novo(s), %d revisão(ões), %d inalterado(s).',
                $totalNovos,
                $totalRevisados,
                $totalInalterados,
            ));

            if ($falhas > 0 || $vazias > 0) {
                $io->warning(sprintf('%d série(s) com falha e %d vazia(s) — verifique.', $falhas, $vazias));

                return Command::FAILURE;
            }

            $io->success('Índices monetários importados.');

            return Command::SUCCESS;
        } finally {
            $this->liberarLock();
        }
    }

    /**
     * @param array<string, string>                     $variacoes competência 'Y-m-01' => variação
     * @param list<array{string, string, string, string}> $revisoes  acumulador, por referência
     *
     * @return array{novos: int, revisados: int, inalterados: int, primeira: string, ultima: string}
     */
    private function importarSerie(
        SerieIndice $serie,
        array $variacoes,
        \DateTimeImmutable $agora,
        bool $dryRun,
        array &$revisoes,
    ): array {
        // Uma consulta por série (não uma por mês): são ~570 competências no INPC.
        $existentes = $this->repositorio->mapaPorCompetencia($serie);

        $novos = 0;
        $revisados = 0;
        $inalterados = 0;

        foreach ($variacoes as $chave => $variacao) {
            $existente = $existentes[$chave] ?? null;

            if ($existente === null) {
                ++$novos;

                if ($dryRun === false) {
                    $this->repositorio->salvar(new IndiceMonetario(
                        $serie,
                        new \DateTimeImmutable($chave),
                        $variacao,
                        $serie->fonte(),
                        $agora,
                    ));
                }

                continue;
            }

            if ($existente->variacaoDivergeDe($variacao) === false) {
                ++$inalterados;

                continue;
            }

            ++$revisados;
            $anterior = $existente->getVariacaoPct();
            $novo = IndiceMonetario::canonizarVariacao($variacao);
            $competencia = substr($chave, 0, 7);

            $revisoes[] = [$serie->value, $competencia, $anterior, $novo];
            $this->logger->warning('Índice monetário revisado pela fonte oficial.', [
                'serie' => $serie->value,
                'competencia' => $competencia,
                'anterior' => $anterior,
                'novo' => $novo,
            ]);

            if ($dryRun === false) {
                $existente->atualizarVariacao($variacao, $agora);
            }
        }

        $competencias = array_keys($variacoes);

        return [
            'novos' => $novos,
            'revisados' => $revisados,
            'inalterados' => $inalterados,
            'primeira' => substr((string) reset($competencias), 0, 7),
            'ultima' => substr((string) end($competencias), 0, 7),
        ];
    }

    /**
     * @return list<SerieIndice>|null null quando o --serie informado não existe
     */
    private function resolverSeries(InputInterface $input, SymfonyStyle $io): ?array
    {
        $opcao = $input->getOption('serie');

        if ($opcao === null) {
            return SerieIndice::cases();
        }

        $serie = SerieIndice::tryFrom(strtoupper(trim((string) $opcao)));

        if ($serie === null) {
            $io->error(sprintf(
                'Série "%s" não existe. Use uma de: %s.',
                (string) $opcao,
                implode(', ', array_column(SerieIndice::cases(), 'value')),
            ));

            return null;
        }

        return [$serie];
    }

    /**
     * Lock por lockfile (flock), no mesmo padrão do cron do DJEN: duas execuções sobrepostas
     * disputariam o UNIQUE (serie, competencia) e uma morreria no meio da carga.
     */
    private function adquirirLock(): bool
    {
        $handle = fopen(sys_get_temp_dir() . '/jusprime-importar-indices-monetarios.lock', 'c');

        if ($handle === false) {
            return false;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB) === false) {
            fclose($handle);

            return false;
        }

        $this->lockHandle = $handle;

        return true;
    }

    private function liberarLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, \LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
