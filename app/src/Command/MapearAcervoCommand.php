<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gera o mapeamento Drive→Sistema para a Fase 2 do acervo.
 *
 * Uso:
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:mapear \
 *      --json=/tmp/acervo-fase2/pastas-drive.json \
 *      --tenant-id=1 \
 *      --saida=/tmp/acervo-fase2/output'
 */
#[AsCommand(
    name: 'app:acervo:mapear',
    description: 'Gera mapeamento Drive→Sistema para a Fase 2 do acervo (3 CSVs)',
)]
final class MapearAcervoCommand extends Command
{
    private const COLUNAS_MAPEAMENTO = ['drive_id', 'drive_nome', 'nup', 'pasta_id', 'pasta_nome_cliente'];
    private const COLUNAS_SEM_MATCH_DRIVE = ['drive_id', 'drive_nome', 'nup', 'motivo'];
    private const COLUNAS_SEM_MATCH_SISTEMA = ['pasta_id', 'nup', 'nome_cliente', 'motivo'];

    public function __construct(
        private readonly AcervoNomesParser $parser,
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'json',
                null,
                InputOption::VALUE_REQUIRED,
                'Caminho do JSON gerado por rclone lsjson --dirs-only',
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do tenant alvo',
            )
            ->addOption(
                'saida',
                null,
                InputOption::VALUE_REQUIRED,
                'Diretório onde os 3 CSVs serão escritos',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $conn = $this->em->getConnection();

        $jsonPath = $input->getOption('json');
        $tenantId = $input->getOption('tenant-id');
        $dirSaida = $input->getOption('saida');

        // --- 1. Validações antecipadas ---

        if ($jsonPath === null) {
            $io->error('--json é obrigatório.');

            return Command::FAILURE;
        }

        if (!is_file($jsonPath) || !is_readable($jsonPath)) {
            $io->error(sprintf('Arquivo JSON não encontrado ou sem permissão de leitura: %s', $jsonPath));

            return Command::FAILURE;
        }

        if ($tenantId === null) {
            $io->error('--tenant-id é obrigatório.');

            return Command::FAILURE;
        }

        $tenant = $this->tenantRepository->find((int) $tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant com ID %s não encontrado no banco.', $tenantId));

            return Command::FAILURE;
        }

        if ($dirSaida === null) {
            $io->error('--saida é obrigatório.');

            return Command::FAILURE;
        }

        if (!is_dir($dirSaida) || !is_writable($dirSaida)) {
            $io->error(sprintf('Diretório de saída não existe ou sem permissão de escrita: %s', $dirSaida));

            return Command::FAILURE;
        }

        // --- 2. Carregar e validar JSON ---

        $jsonConteudo = (string) file_get_contents($jsonPath);
        /** @var mixed $jsonData */
        $jsonData = json_decode($jsonConteudo, true);

        if (!is_array($jsonData)) {
            $io->error(sprintf('JSON inválido ou não é um array: %s', $jsonPath));

            return Command::FAILURE;
        }

        $totalDrive = count($jsonData);
        $io->writeln(sprintf('Pastas no Drive (JSON): <info>%d</info>', $totalDrive));

        // --- 3. Indexar Drive: nome → ID ---
        // Nota: se dois itens do JSON tiverem o mesmo Name (extremamente raro),
        // o último ID prevalece no índice; o parser os detecta como nup_repetido.

        /** @var array<string, string> $driveNameToId */
        $driveNameToId = [];
        /** @var list<string> $driveNames */
        $driveNames = [];

        foreach ($jsonData as $item) {
            $nome = trim((string) ($item['Name'] ?? ''));
            $driveNameToId[$nome] = (string) ($item['ID'] ?? '');
            $driveNames[] = $nome;
        }

        // --- 4. Parsear nomes para extrair NUPs ---

        $resultado = $this->parser->parsear(implode("\n", $driveNames));

        // --- 5. Carregar pastas do sistema ---

        /** @var list<array<string, mixed>> $rows */
        $rows = $conn->fetchAllAssociative(
            'SELECT id, nup, nome_cliente FROM pasta WHERE tenant_id = :t',
            ['t' => (int) $tenantId],
        );

        // Indexa por NUP (string uppercase — mesmo padrão do entity setter)
        /** @var array<string, array<string, mixed>> $sistemaNupToRow */
        $sistemaNupToRow = [];
        foreach ($rows as $row) {
            $sistemaNupToRow[(string) $row['nup']] = $row;
        }

        $io->writeln(sprintf(
            'Pastas no sistema (tenant %s): <info>%d</info>',
            $tenantId,
            count($sistemaNupToRow),
        ));

        // --- 6. Processar resultados do parser ---

        /** @var list<array<string, string>> $semMatchDrive */
        $semMatchDrive = [];

        // 6a. Pendências: nup_repetido → nup_duplicado_drive; demais → nup_nao_extraido
        foreach ($resultado['pendencias'] as $item) {
            $motivo = $item['motivo'] === 'nup_repetido' ? 'nup_duplicado_drive' : 'nup_nao_extraido';

            $semMatchDrive[] = [
                'drive_id'  => $driveNameToId[$item['linha_original']] ?? '',
                'drive_nome' => $item['linha_original'],
                'nup'       => $item['nup'],
                'motivo'    => $motivo,
            ];
        }

        // 6b. Alta + Revisão: tentar mapear no sistema
        /** @var list<array<string, string>> $candidatos */
        $candidatos = [];

        foreach (array_merge($resultado['alta'], $resultado['revisao']) as $item) {
            $nup       = $item['nup'];
            $linhaNome = $item['linha_original'];
            $driveId   = $driveNameToId[$linhaNome] ?? '';
            $pastaRow  = $sistemaNupToRow[$nup] ?? null;

            if ($pastaRow === null) {
                $semMatchDrive[] = [
                    'drive_id'  => $driveId,
                    'drive_nome' => $linhaNome,
                    'nup'       => $nup,
                    'motivo'    => 'nup_nao_existe_no_sistema',
                ];
            } else {
                $candidatos[] = [
                    'drive_id'           => $driveId,
                    'drive_nome'         => $linhaNome,
                    'nup'                => $nup,
                    'pasta_id'           => (string) $pastaRow['id'],
                    'pasta_nome_cliente' => (string) ($pastaRow['nome_cliente'] ?? ''),
                ];
            }
        }

        // 6c. Detectar NUP ambíguo: 2+ Drive entries mapeando para o mesmo pasta_id
        /** @var array<string, int> $pastaIdContagem */
        $pastaIdContagem = [];
        foreach ($candidatos as $c) {
            $pastaIdContagem[$c['pasta_id']] = ($pastaIdContagem[$c['pasta_id']] ?? 0) + 1;
        }

        /** @var list<array<string, string>> $mapeamento */
        $mapeamento = [];
        foreach ($candidatos as $c) {
            if ($pastaIdContagem[$c['pasta_id']] > 1) {
                $semMatchDrive[] = [
                    'drive_id'  => $c['drive_id'],
                    'drive_nome' => $c['drive_nome'],
                    'nup'       => $c['nup'],
                    'motivo'    => 'nup_ambiguo',
                ];
            } else {
                $mapeamento[] = $c;
            }
        }

        // --- 7. Pastas do sistema sem correspondência no Drive ---

        /** @var array<string, true> $nupsMapeadosSet */
        $nupsMapeadosSet = array_fill_keys(array_column($mapeamento, 'nup'), true);

        /** @var list<array<string, string>> $semMatchSistema */
        $semMatchSistema = [];
        foreach ($sistemaNupToRow as $nup => $row) {
            if (!isset($nupsMapeadosSet[$nup])) {
                $semMatchSistema[] = [
                    'pasta_id'    => (string) $row['id'],
                    'nup'         => $nup,
                    'nome_cliente' => (string) ($row['nome_cliente'] ?? ''),
                    'motivo'      => 'sem_pasta_no_drive',
                ];
            }
        }

        // --- 8. Ordenar por NUP ascendente ---

        $ordenarPorNup = static function (array $a, array $b): int {
            $na = is_numeric($a['nup'] ?? '') ? (int) $a['nup'] : PHP_INT_MAX;
            $nb = is_numeric($b['nup'] ?? '') ? (int) $b['nup'] : PHP_INT_MAX;

            return $na <=> $nb;
        };

        usort($mapeamento, $ordenarPorNup);
        usort($semMatchDrive, $ordenarPorNup);
        usort($semMatchSistema, $ordenarPorNup);

        // --- 9. Reconciliação obrigatória ---

        $totalMapeados = count($mapeamento) + count($semMatchDrive);
        if ($totalMapeados !== $totalDrive) {
            $io->error(sprintf(
                'Reconciliação falhou: %d (mapeamento) + %d (sem_match_drive) = %d ≠ %d (total Drive no JSON). '
                . 'Possível nome duplicado no JSON ou bug no processamento.',
                count($mapeamento),
                count($semMatchDrive),
                $totalMapeados,
                $totalDrive,
            ));

            return Command::FAILURE;
        }

        // --- 10. Gravar CSVs ---

        $io->section('Gravando CSVs');

        $this->gravarCsv(
            $dirSaida . '/mapeamento.csv',
            self::COLUNAS_MAPEAMENTO,
            $mapeamento,
            $io,
        );

        $this->gravarCsv(
            $dirSaida . '/sem_match_drive.csv',
            self::COLUNAS_SEM_MATCH_DRIVE,
            $semMatchDrive,
            $io,
        );

        $this->gravarCsv(
            $dirSaida . '/sem_match_sistema.csv',
            self::COLUNAS_SEM_MATCH_SISTEMA,
            $semMatchSistema,
            $io,
        );

        // --- 11. Resumo final ---

        $io->section('Resumo');

        $motivosContagem = [];
        foreach ($semMatchDrive as $item) {
            $motivosContagem[$item['motivo']] = ($motivosContagem[$item['motivo']] ?? 0) + 1;
        }
        ksort($motivosContagem);

        $io->table(
            ['Destino', 'Linhas'],
            [
                ['mapeamento.csv', count($mapeamento)],
                ['sem_match_drive.csv', count($semMatchDrive)],
                ['sem_match_sistema.csv', count($semMatchSistema)],
                ['TOTAL DRIVE', $totalDrive],
            ],
        );

        if (!empty($motivosContagem)) {
            $io->table(
                ['Motivo (sem_match_drive)', 'Ocorrências'],
                array_map(
                    static fn (string $m, int $q): array => [$m, $q],
                    array_keys($motivosContagem),
                    array_values($motivosContagem),
                ),
            );
        }

        $io->success(sprintf(
            'Reconciliação: %d (mapeamento) + %d (sem_match_drive) = %d ✓',
            count($mapeamento),
            count($semMatchDrive),
            $totalDrive,
        ));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>               $colunas
     * @param list<array<string,string>> $linhas
     */
    private function gravarCsv(string $caminho, array $colunas, array $linhas, SymfonyStyle $io): void
    {
        $handle = fopen($caminho, 'w');
        if ($handle === false) {
            $io->error(sprintf('Não foi possível criar o arquivo: %s', $caminho));

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fwrite($handle, $this->formatarLinhaCsv($colunas));

        foreach ($linhas as $registro) {
            $campos = array_map(
                static fn (string $col): string => (string) ($registro[$col] ?? ''),
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
