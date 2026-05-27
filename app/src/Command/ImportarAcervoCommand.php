<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa pastas do acervo via CriarPastaUseCase, usando alta_confianca.csv
 * gerado pela Fase 0 (app:acervo:parsear).
 *
 * Uso:
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:importar \
 *      --csv=/tmp/acervo/alta_confianca.csv \
 *      --tenant-id=1 --usuario-id=1 --dry-run'
 */
#[AsCommand(
    name: 'app:acervo:importar',
    description: 'Importa pastas do acervo CSV (Fase 0) via CriarPastaUseCase',
)]
final class ImportarAcervoCommand extends Command
{
    private const UTF8_BOM = "\xEF\xBB\xBF";
    private const COLUNAS_OBRIGATORIAS = ['nup', 'cliente', 'acao'];

    public function __construct(
        private readonly CriarPastaUseCase $useCase,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'csv',
                null,
                InputOption::VALUE_REQUIRED,
                'Caminho do alta_confianca.csv gerado pela Fase 0',
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do tenant destino',
            )
            ->addOption(
                'usuario-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do usuário a ser atribuído como criadoPor',
            )
            ->addOption(
                'amostra',
                null,
                InputOption::VALUE_REQUIRED,
                'Processar apenas as N primeiras linhas de dados (para testes)',
                0,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simula o import sem persistir nenhuma linha',
            )
            ->addOption(
                'pular-erros',
                null,
                InputOption::VALUE_NONE,
                'Em erros inesperados: loga e continua. Sem esta flag, para no 1º erro.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $csvPath    = $input->getOption('csv');
        $tenantId   = $input->getOption('tenant-id');
        $usuarioId  = $input->getOption('usuario-id');
        $amostra    = (int) $input->getOption('amostra');
        $dryRun     = (bool) $input->getOption('dry-run');
        $pularErros = (bool) $input->getOption('pular-erros');

        // --- 1. Validação antecipada de todos os parâmetros ---

        if ($csvPath === null) {
            $io->error('--csv é obrigatório.');

            return Command::FAILURE;
        }

        if (!is_file($csvPath) || !is_readable($csvPath)) {
            $io->error(sprintf('Arquivo CSV não encontrado ou sem permissão de leitura: %s', $csvPath));

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

        if ($usuarioId === null) {
            $io->error('--usuario-id é obrigatório.');

            return Command::FAILURE;
        }

        $usuario = $this->em->find(User::class, (int) $usuarioId);
        if (!$usuario instanceof User) {
            $io->error(sprintf('Usuário com ID %s não encontrado no banco.', $usuarioId));

            return Command::FAILURE;
        }

        // --- Avisos de modo ---

        if ($dryRun) {
            $io->note('Modo dry-run — nenhuma linha será persistida.');
        }

        if ($amostra > 0) {
            $io->note(sprintf('Modo amostra — processando apenas as %d primeiras linhas de dados.', $amostra));
        }

        // --- 2. Abrir e validar cabeçalho do CSV ---

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            $io->error(sprintf('Não foi possível abrir o CSV: %s', $csvPath));

            return Command::FAILURE;
        }

        // O BOM (EF BB BF) fica antes da primeira aspa do enclosure, quebrando o
        // fgetcsv. Consumir os 3 bytes antes de qualquer leitura CSV resolve isso.
        $primeirosBytes = fread($handle, 3);
        if ($primeirosBytes !== self::UTF8_BOM) {
            rewind($handle);
        }

        /** @var list<string>|false $cabecalho */
        $cabecalho = fgetcsv($handle, 0, ';', '"');
        if ($cabecalho === false) {
            fclose($handle);
            $io->error('CSV vazio — nenhuma linha de cabeçalho encontrada.');

            return Command::FAILURE;
        }

        // Cinto de segurança: remove aspas ou espaços residuais dos nomes de coluna
        $cabecalho = array_map(static fn (string $col): string => trim($col, "\" \t"), $cabecalho);

        $colIdx = array_flip($cabecalho);
        foreach (self::COLUNAS_OBRIGATORIAS as $col) {
            if (!isset($colIdx[$col])) {
                fclose($handle);
                $io->error(sprintf(
                    'Coluna "%s" não encontrada no CSV. Colunas detectadas: %s',
                    $col,
                    implode(', ', $cabecalho),
                ));

                return Command::FAILURE;
            }
        }

        // --- 3. Processar linhas ---

        $importadas = 0;
        $puladas    = 0;
        $erros      = 0;
        $linhaCSV   = 1; // cabeçalho foi a linha 1

        /** @var list<string>|false $row */
        while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
            $linhaCSV++;
            $linhadeDados = $linhaCSV - 1; // 1-indexed dentro dos dados

            if ($amostra > 0 && $linhadeDados > $amostra) {
                break;
            }

            $nup     = trim($row[$colIdx['nup']] ?? '');
            $cliente = trim($row[$colIdx['cliente']] ?? '');
            $acao    = trim($row[$colIdx['acao']] ?? '');

            $dto = new CriarPastaDTO(
                nup: $nup,
                nomeCliente: $cliente !== '' ? $cliente : null,
                nomeAcao: $acao !== '' ? $acao : null,
            );

            if ($dryRun) {
                $io->writeln(sprintf(
                    '  [dry-run] #%d NUP=<info>%s</info> | Cliente=%s | Ação=%s',
                    $linhadeDados,
                    $nup,
                    $cliente ?: '<comment>(vazio)</comment>',
                    $acao ?: '<comment>(vazio)</comment>',
                ));
                $importadas++;
                continue;
            }

            try {
                $this->useCase->executar($dto, $usuario, $tenant);
                $importadas++;
            } catch (\InvalidArgumentException $e) {
                // NUP duplicado ou NUP vazio — sempre pula, independente de --pular-erros
                $puladas++;
                $io->writeln(sprintf(
                    '  <comment>[pulada]</comment> Linha %d NUP=%s: %s',
                    $linhaCSV,
                    $nup,
                    $e->getMessage(),
                ));
            } catch (\Exception $e) {
                $erros++;
                $io->writeln(sprintf(
                    '  <error>[erro]</error> Linha %d NUP=%s: %s',
                    $linhaCSV,
                    $nup,
                    $e->getMessage(),
                ));

                if (!$pularErros) {
                    fclose($handle);
                    $io->error(sprintf(
                        'Import interrompido na linha %d. Use --pular-erros para continuar em erros inesperados.',
                        $linhaCSV,
                    ));
                    $this->exibirResumo($io, $importadas, $puladas, $erros, $dryRun);

                    return Command::FAILURE;
                }
            }
        }

        fclose($handle);

        // --- 4. Resumo final ---

        $this->exibirResumo($io, $importadas, $puladas, $erros, $dryRun);

        return $erros > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function exibirResumo(
        SymfonyStyle $io,
        int $importadas,
        int $puladas,
        int $erros,
        bool $dryRun,
    ): void {
        $io->section('Resumo do import');

        $io->table(
            ['Métrica', 'Linhas'],
            [
                ['Processadas', $importadas + $puladas + $erros],
                ['Importadas', $importadas],
                ['Puladas (NUP já existe)', $puladas],
                ['Erros inesperados', $erros],
            ],
        );

        if ($dryRun) {
            $io->note('Dry-run concluído — nenhuma linha foi persistida. Remova --dry-run para importar de verdade.');

            return;
        }

        if ($erros === 0) {
            $io->success(sprintf('%d pasta(s) importada(s) com sucesso.', $importadas));
        } else {
            $io->warning(sprintf('%d pasta(s) importada(s), %d erro(s) encontrado(s).', $importadas, $erros));
        }
    }
}
