<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Repository\TenantRepository;
use App\Shared\Service\ArquivoStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fase 2 Etapa 3 do acervo — lê o filesystem local e importa arquivos para o sistema.
 *
 * Uso:
 *   docker exec jusprime_php_dev bash -c \
 *     'cd app && php bin/console app:acervo:copiar-arquivos \
 *      --diretorio=/opt/jusprime-acervo-download/pastas \
 *      --tenant-id=1'
 */
#[AsCommand(
    name: 'app:acervo:copiar-arquivos',
    description: 'Fase 2 Etapa 3 — Importa arquivos do acervo local para o storage do sistema',
)]
final class CopiarArquivosAcervoCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly TenantRepository        $tenantRepository,
        private readonly PastaSecaoRepository    $secaoRepository,
        private readonly ArquivoStorageInterface $storage,
        #[Autowire('%uploads_dir%')]
        private readonly string                  $uploadsDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'diretorio',
                null,
                InputOption::VALUE_REQUIRED,
                'Raiz com subpastas nomeadas pelo ID da pasta (ex.: /opt/jusprime-acervo-download/pastas)',
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'ID do tenant alvo',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Processar apenas as primeiras N pastas (para testes)',
            )
            ->addOption(
                'pasta-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Processar apenas uma pasta específica (debug)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $diretorio   = $input->getOption('diretorio');
        $tenantId    = $input->getOption('tenant-id');
        $limit       = $input->getOption('limit');
        $pastaIdOpt  = $input->getOption('pasta-id');

        // --- Validações antecipadas ---

        if ($diretorio === null) {
            $io->error('--diretorio é obrigatório.');

            return Command::FAILURE;
        }

        if (!is_dir($diretorio) || !is_readable($diretorio)) {
            $io->error(sprintf('Diretório não existe ou sem permissão de leitura: %s', $diretorio));

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

        // --- Determinar lista de pasta_ids ---

        if ($pastaIdOpt !== null) {
            $pastaIds = [(int) $pastaIdOpt];
        } else {
            $pastaIds = $this->listarPastaIds($diretorio);
            if ($limit !== null) {
                $pastaIds = array_slice($pastaIds, 0, (int) $limit);
            }
        }

        $total = count($pastaIds);
        $io->writeln(sprintf('Pastas a processar: <info>%d</info>', $total));

        // --- Totais globais ---

        $totais = [
            'pastas'      => 0,
            'criados'     => 0,
            'pulados'     => 0,
            'erros'       => 0,
            'secoesNovas' => 0,
        ];

        foreach ($pastaIds as $idx => $pastaId) {
            $this->processarPasta($pastaId, $tenant, $diretorio, $io, $totais, $idx + 1, $total);
        }

        // --- Resumo final ---

        $io->section('Resumo');

        $io->table(
            ['Métrica', 'Total'],
            [
                ['Pastas processadas',              $totais['pastas']],
                ['Documentos criados',              $totais['criados']],
                ['Documentos pulados (já existiam)', $totais['pulados']],
                ['Erros',                           $totais['erros']],
                ['Seções criadas',                  $totais['secoesNovas']],
            ],
        );

        if ($totais['erros'] > 0) {
            $io->warning(sprintf('%d arquivo(s) com erro — revisar logs acima.', $totais['erros']));
        } else {
            $io->success('Concluído sem erros.');
        }

        return Command::SUCCESS;
    }

    /** @param array<string, int> $totais */
    private function processarPasta(
        int          $pastaId,
        Tenant       $tenant,
        string       $diretorio,
        SymfonyStyle $io,
        array        &$totais,
        int          $idx,
        int          $total,
    ): void {
        $dirPasta = rtrim($diretorio, '/') . '/' . $pastaId;

        if (!is_dir($dirPasta)) {
            $io->writeln(sprintf(
                '[%d/%d] pasta_id=%d — diretório não encontrado no FS, pulando.',
                $idx, $total, $pastaId,
            ));

            return;
        }

        $pasta = $this->em->find(Pasta::class, $pastaId);

        if ($pasta === null) {
            $io->writeln(sprintf(
                '[%d/%d] pasta_id=%d — AVISO: não existe no banco, pulando.',
                $idx, $total, $pastaId,
            ));

            return;
        }

        if ($pasta->getTenant()?->getId() !== $tenant->getId()) {
            $io->writeln(sprintf(
                '[%d/%d] pasta_id=%d — AVISO: pertence a outro tenant, pulando.',
                $idx, $total, $pastaId,
            ));

            return;
        }

        $contadores  = ['criados' => 0, 'pulados' => 0, 'erros' => 0];
        $secoesNovas = 0;

        // Índice em memória: nome normalizado (UPPERCASE) → PastaSecao
        /** @var array<string, PastaSecao> $secoesExistentes */
        $secoesExistentes = [];
        foreach ($this->secaoRepository->findByPasta($pasta, $tenant) as $s) {
            $secoesExistentes[$s->getNome()] = $s;
        }

        $entradas = scandir($dirPasta);
        if ($entradas === false) {
            $io->writeln(sprintf(
                '[%d/%d] pasta_id=%d — ERRO: não foi possível ler %s',
                $idx, $total, $pastaId, $dirPasta,
            ));

            return;
        }

        foreach ($entradas as $entrada) {
            if ($entrada === '.' || $entrada === '..') {
                continue;
            }

            $pathEntrada = $dirPasta . '/' . $entrada;

            if (is_dir($pathEntrada)) {
                $nomeNormalizado = mb_strtoupper(trim($entrada));

                if (isset($secoesExistentes[$nomeNormalizado])) {
                    $secao = $secoesExistentes[$nomeNormalizado];
                } else {
                    $secao = (new PastaSecao())
                        ->setNome($entrada)
                        ->setPasta($pasta)
                        ->setTenant($tenant)
                        ->setOrdem($this->secaoRepository->proximaOrdem($pasta, $tenant));
                    $this->em->persist($secao);
                    $secoesExistentes[$secao->getNome()] = $secao;
                    $secoesNovas++;
                }

                // Achata sub-subpastas: todos os arquivos vão para esta seção
                $iterador = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($pathEntrada, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );

                foreach ($iterador as $arquivo) {
                    /** @var \SplFileInfo $arquivo */
                    if (!$arquivo->isFile()) {
                        continue;
                    }
                    $this->processarArquivo($arquivo->getRealPath(), $pasta, $secao, $tenant, $io, $contadores);
                }
            } elseif (is_file($pathEntrada)) {
                $this->processarArquivo($pathEntrada, $pasta, null, $tenant, $io, $contadores);
            }
        }

        $this->em->flush();

        $io->writeln(sprintf(
            '[%d/%d] pasta_id=%d — %d criados, %d seções novas, %d pulados, %d erros',
            $idx, $total, $pastaId,
            $contadores['criados'],
            $secoesNovas,
            $contadores['pulados'],
            $contadores['erros'],
        ));

        $totais['pastas']++;
        $totais['criados']     += $contadores['criados'];
        $totais['pulados']     += $contadores['pulados'];
        $totais['erros']       += $contadores['erros'];
        $totais['secoesNovas'] += $secoesNovas;
    }

    /** @param array<string, int> $contadores */
    private function processarArquivo(
        string       $path,
        Pasta        $pasta,
        ?PastaSecao  $secao,
        Tenant       $tenant,
        SymfonyStyle $io,
        array        &$contadores,
    ): void {
        $nomeOriginal = basename($path);

        if ($this->deveIgnorar($nomeOriginal)) {
            return;
        }

        try {
            $existe = $this->em->getConnection()->fetchOne(
                'SELECT id FROM pasta_documento WHERE pasta_id = :p AND nome_original = :n LIMIT 1',
                ['p' => $pasta->getId(), 'n' => $nomeOriginal],
            );

            if ($existe !== false) {
                $io->writeln(sprintf('  PULADO: %s (já existe)', $nomeOriginal));
                $contadores['pulados']++;

                return;
            }

            $conteudo = file_get_contents($path);
            if ($conteudo === false) {
                throw new \RuntimeException(sprintf('Não foi possível ler: %s', $path));
            }

            $tamanho = filesize($path);
            if ($tamanho === false) {
                throw new \RuntimeException(sprintf('Não foi possível obter tamanho: %s', $path));
            }

            $extensao    = pathinfo($path, PATHINFO_EXTENSION);
            $mime        = mime_content_type($path) ?: 'application/octet-stream';
            $nomeStorage = $this->storage->salvarConteudo($conteudo, $this->uploadsDir, $extensao);

            $doc = (new PastaDocumento())
                ->setTitulo($nomeOriginal)
                ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
                ->setCaminhoArquivo($nomeStorage)
                ->setNomeOriginal($nomeOriginal)
                ->setMimeType($mime)
                ->setTamanhoBytes($tamanho)
                ->setPasta($pasta)
                ->setSecao($secao)
                ->setTenant($tenant);

            $this->em->persist($doc);
            $contadores['criados']++;

        } catch (\Throwable $e) {
            $io->writeln(sprintf('  ERRO: %s: %s', $nomeOriginal, $e->getMessage()));
            $contadores['erros']++;
        }
    }

    /** @return list<int> IDs das pastas encontradas no filesystem, ordenados numericamente */
    private function listarPastaIds(string $diretorio): array
    {
        $entradas = scandir($diretorio);
        if ($entradas === false) {
            return [];
        }

        $ids = [];
        foreach ($entradas as $entrada) {
            if (ctype_digit($entrada) && is_dir($diretorio . '/' . $entrada)) {
                $ids[] = (int) $entrada;
            }
        }

        sort($ids);

        return $ids;
    }

    private function deveIgnorar(string $nomeArquivo): bool
    {
        if (str_starts_with($nomeArquivo, '.')) {
            return true;
        }

        return in_array(strtolower($nomeArquivo), ['thumbs.db', 'desktop.ini'], true);
    }
}
