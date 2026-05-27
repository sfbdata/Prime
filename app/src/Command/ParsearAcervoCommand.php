<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Parseia o acervo de nomes de pastas do Google Drive e gera três CSVs:
 * alta_confianca.csv, revisao_manual.csv e pendencias.csv.
 *
 * Uso:
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:parsear /caminho/acervo_nomes.txt'
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:parsear /caminho/acervo_nomes.txt --dry-run'
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:parsear /caminho/acervo_nomes.txt --amostra=20 --dry-run'
 */
#[AsCommand(
    name: 'app:acervo:parsear',
    description: 'Parseia nomes de pastas do acervo Google Drive e gera CSVs de importação',
)]
final class ParsearAcervoCommand extends Command
{
    private const COLUNAS_ALTA = ['nup', 'cliente', 'parte_contraria', 'acao', 'linha_original'];
    private const COLUNAS_REVISAO = ['nup', 'cliente', 'parte_contraria', 'acao', 'motivo_revisao', 'linha_original'];
    private const COLUNAS_PENDENCIAS = ['nup', 'motivo', 'linha_original'];

    public function __construct(
        private readonly AcervoNomesParser $parser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'arquivo',
                InputArgument::REQUIRED,
                'Caminho absoluto do arquivo .txt com os nomes de pasta',
            )
            ->addOption(
                'saida',
                null,
                InputOption::VALUE_REQUIRED,
                'Diretório de saída para os CSVs (padrão: mesmo diretório do arquivo)',
            )
            ->addOption(
                'amostra',
                null,
                InputOption::VALUE_REQUIRED,
                'Processar apenas as N primeiras linhas (para testes)',
                0,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Exibe estatísticas sem gravar nenhum arquivo',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $arquivo = $input->getArgument('arquivo');
        $dryRun  = (bool) $input->getOption('dry-run');
        $amostra = (int) $input->getOption('amostra');

        if (!is_file($arquivo) || !is_readable($arquivo)) {
            $io->error(sprintf('Arquivo não encontrado ou sem permissão de leitura: %s', $arquivo));

            return Command::FAILURE;
        }

        $dirSaida = $input->getOption('saida') ?? dirname($arquivo);
        if (!$dryRun && !is_dir($dirSaida)) {
            $io->error(sprintf('Diretório de saída não existe: %s', $dirSaida));

            return Command::FAILURE;
        }

        if ($dryRun) {
            $io->note('Modo dry-run — nenhum arquivo será gravado.');
        }

        if ($amostra > 0) {
            $io->note(sprintf('Modo amostra — processando apenas as %d primeiras linhas.', $amostra));
        }

        // --- Parse ---
        $conteudo   = file_get_contents($arquivo);
        $resultado  = $this->parser->parsear($conteudo, $amostra);

        $alta      = $resultado['alta'];
        $revisao   = $resultado['revisao'];
        $pendencias = $resultado['pendencias'];

        $totalLido = $this->contarLinhasValidas($conteudo, $amostra);

        // --- Reconciliação (antes de gravar qualquer arquivo) ---
        $soma = count($alta) + count($revisao) + count($pendencias);
        if ($soma !== $totalLido) {
            $io->error(sprintf(
                'Reconciliação falhou: %d (alta) + %d (revisão) + %d (pendências) = %d ≠ %d (lidas)',
                count($alta),
                count($revisao),
                count($pendencias),
                $soma,
                $totalLido,
            ));

            return Command::FAILURE;
        }

        // --- Exibição ---
        $io->section('Resultado do parse');

        $motivosContagem = [];
        foreach ($pendencias as $p) {
            $motivosContagem[$p['motivo']] = ($motivosContagem[$p['motivo']] ?? 0) + 1;
        }
        ksort($motivosContagem);

        $linhasPendencias = [];
        foreach ($motivosContagem as $motivo => $qtd) {
            $linhasPendencias[] = [$motivo, $qtd];
        }

        $io->table(
            ['Motivo pendência', 'Linhas'],
            $linhasPendencias,
        );

        $motivosRevisao = [];
        foreach ($revisao as $r) {
            $motivosRevisao[$r['motivo_revisao']] = ($motivosRevisao[$r['motivo_revisao']] ?? 0) + 1;
        }
        if (!empty($motivosRevisao)) {
            $io->table(
                ['Motivo revisão', 'Linhas'],
                array_map(
                    static fn (string $m, int $q): array => [$m, $q],
                    array_keys($motivosRevisao),
                    array_values($motivosRevisao),
                ),
            );
        }

        $io->table(
            ['Destino', 'Linhas'],
            [
                ['alta_confianca.csv', count($alta)],
                ['revisao_manual.csv', count($revisao)],
                ['pendencias.csv', count($pendencias)],
                ['TOTAL', $totalLido],
            ],
        );

        $io->success(sprintf(
            'Reconciliação: %d (alta) + %d (revisão) + %d (pendências) = %d ✓',
            count($alta),
            count($revisao),
            count($pendencias),
            $totalLido,
        ));

        if ($dryRun) {
            return Command::SUCCESS;
        }

        // --- Gravação dos CSVs ---
        $this->gravarCsv(
            $dirSaida . '/alta_confianca.csv',
            self::COLUNAS_ALTA,
            $alta,
            $io,
        );

        $this->gravarCsv(
            $dirSaida . '/revisao_manual.csv',
            self::COLUNAS_REVISAO,
            $revisao,
            $io,
        );

        $this->gravarCsv(
            $dirSaida . '/pendencias.csv',
            self::COLUNAS_PENDENCIAS,
            $pendencias,
            $io,
        );

        return Command::SUCCESS;
    }

    private function contarLinhasValidas(string $conteudo, int $amostra): int
    {
        $linhas = array_filter(
            explode("\n", $conteudo),
            static fn (string $l): bool => trim($l) !== '',
        );

        $total = count($linhas);

        return ($amostra > 0 && $amostra < $total) ? $amostra : $total;
    }

    /**
     * @param list<string>             $colunas
     * @param list<array<string,string>> $linhas
     */
    private function gravarCsv(string $caminho, array $colunas, array $linhas, SymfonyStyle $io): void
    {
        $handle = fopen($caminho, 'w');
        if ($handle === false) {
            $io->error(sprintf('Não foi possível criar o arquivo: %s', $caminho));

            return;
        }

        // UTF-8 BOM para Excel pt-BR
        fwrite($handle, "\xEF\xBB\xBF");

        // Cabeçalho
        fwrite($handle, $this->formatarLinhaCsv($colunas));

        foreach ($linhas as $registro) {
            $campos = array_map(
                static fn (string $col): string => $registro[$col] ?? '',
                $colunas,
            );
            fwrite($handle, $this->formatarLinhaCsv($campos));
        }

        fclose($handle);

        $io->writeln(sprintf('  <info>✓</info> %s (%d linhas)', $caminho, count($linhas)));
    }

    /** @param list<string> $campos */
    private function formatarLinhaCsv(array $campos): string
    {
        $escapados = array_map(
            static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"',
            $campos,
        );

        return implode(';', $escapados) . "\r\n";
    }
}
