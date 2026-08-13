<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Command\AcervoNomesParser;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Repository\TenantRepository;
use App\Sync\DTO\DriveDoTenant;
use App\Sync\DTO\ResultadoReconciliacaoPasta;
use App\Sync\Enum\ModoSincronizacao;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\GoogleDriveClientInterface;
use App\Sync\Service\ReconciliadorDePasta;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fase 1/2 — motor de reconciliação (aditivo, nunca apaga) de PASTAS e ARQUIVOS.
 * MULTI-TENANT: obtém o client e a pasta-raiz de CADA escritório pela fábrica (a partir da
 * TenantDriveConexao cifrada). Com --tenant-id roda um; sem, itera todos os tenants com Drive
 * conectado. O núcleo por-pasta (folder + arquivos) é o serviço {@see ReconciliadorDePasta},
 * reusado pelo handler da Fase 2. A descoberta Drive→sistema (subpasta nova vira Pasta) fica aqui.
 * Identidade por drive_folder_id / drive_file_id; flush+clear por item; idempotente.
 *
 * DEIXOU DE SER BIDIRECIONAL POR PADRÃO (spec fase2 §12.5 / R2): o sentido agora é
 * {@see ModoSincronizacao} e o padrão é `enviar` (só sistema→Drive). Importar continua possível —
 * o código todo está aqui —, mas exige `--modo=importar` de propósito. O sistema é a fonte.
 */
#[AsCommand(
    name: 'app:sync:reconciliar',
    description: 'Reconcilia pastas e arquivos do sistema para o Drive (--modo=enviar por padrão; aditivo, multi-tenant)',
)]
final class ReconciliarCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly GoogleDriveClientFactoryInterface $clientFactory,
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly AcervoNomesParser $parser,
        private readonly ReconciliadorDePasta $reconciliador,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do tenant alvo (sem isto, itera todos os tenants com Drive conectado)')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário criadoPor das pastas nascidas no Drive')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simula (sem mutação no Drive; DB revertido)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Processa só as N primeiras de cada via (amostra)')
            ->addOption('pasta-id', null, InputOption::VALUE_REQUIRED, 'Processa só uma pasta do sistema→Drive (debug); pula a via Drive→sistema')
            ->addOption('skip-arquivos', null, InputOption::VALUE_NONE, 'Reconcilia só PASTAS (pula a sincronização de arquivos)')
            // R2: o PADRÃO é `enviar`. Antes, rodar sem opção nenhuma fazia os dois sentidos — era
            // assim que o cron importava do Drive de hora em hora. Deixar o padrão em `ambos` e
            // confiar num passo de ops para trocar o cron seria apostar a garantia "nada importa
            // sozinho" numa linha de crontab que alguém precisa lembrar de editar. Com o padrão
            // aqui, mesmo o cron antigo (sem --modo) para de importar assim que o deploy sobe.
            ->addOption(
                'modo',
                null,
                InputOption::VALUE_REQUIRED,
                'Sentido da sincronização: ' . implode('|', ModoSincronizacao::valores())
                    . '. Padrão: enviar (só sistema→Drive). "importar" e "ambos" nunca são automáticos.',
                ModoSincronizacao::Enviar->value,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io  = new SymfonyStyle($input, $output);
        $tid = $input->getOption('tenant-id');
        $uid = $input->getOption('usuario-id');

        $usuario = $uid !== null ? $this->em->find(User::class, (int) $uid) : null;
        if (!$usuario instanceof User) {
            $io->error('Usuário inválido (--usuario-id é obrigatório: criadoPor das pastas nascidas no Drive).');

            return Command::FAILURE;
        }

        $modo = ModoSincronizacao::tryFrom((string) $input->getOption('modo'));
        if ($modo === null) {
            $io->error(sprintf(
                'Modo inválido "%s". Use: %s.',
                (string) $input->getOption('modo'),
                implode(', ', ModoSincronizacao::valores()),
            ));

            return Command::FAILURE;
        }

        // Alvos = (tenant, client+raiz). Com --tenant-id, um; senão todos os tenants conectados.
        $alvos = $this->resolverAlvos($tid !== null ? (int) $tid : null, $io);
        if ($alvos === null) {
            return Command::FAILURE;
        }
        if ($alvos === []) {
            $io->warning('Nenhum tenant com Drive conectado. Conecte um escritório em "Conectar meu Drive".');

            return Command::SUCCESS;
        }

        $totais = $this->totaisZerados();
        $fatal  = false;

        foreach ($alvos as [$tenant, $drive]) {
            /** @var Tenant $tenant */
            /** @var DriveDoTenant $drive */
            $io->writeln(sprintf(
                'Reconciliando <info>tenant %d</info> contra a raiz <info>%s</info> [modo: <info>%s</info>]%s',
                (int) $tenant->getId(),
                $drive->rootFolderId,
                $modo->value,
                (bool) $input->getOption('dry-run') ? ' <comment>(dry-run)</comment>' : '',
            ));

            if ($this->processarTenant($tenant, $drive, $usuario, $input, $modo, $totais, $io)) {
                $fatal = true;
                break; // EM fechou — aborta a rodada inteira (idempotente na reexecução)
            }
        }

        $this->imprimirResumo($totais, (bool) $input->getOption('dry-run'), (bool) $input->getOption('skip-arquivos'), $io);

        if ($fatal) {
            $io->error('Rodada interrompida: o EntityManager fechou após um erro grave. Reexecute (é idempotente).');

            return Command::FAILURE;
        }
        if ($totais['erros'] > 0) {
            $io->warning(sprintf('%d item(ns) com erro — logados acima; a reexecução é idempotente.', $totais['erros']));
        }

        return Command::SUCCESS;
    }

    /**
     * Resolve os alvos. Devolve null em erro (tenant inválido / sem conexão quando --tenant-id dado).
     *
     * @return list<array{0: Tenant, 1: DriveDoTenant}>|null
     */
    private function resolverAlvos(?int $tenantId, SymfonyStyle $io): ?array
    {
        if ($tenantId !== null) {
            $tenant = $this->tenantRepository->find($tenantId);
            if ($tenant === null) {
                $io->error('Tenant inválido.');

                return null;
            }
            try {
                return [[$tenant, $this->clientFactory->paraTenant($tenant)]];
            } catch (TenantSemDriveException) {
                $io->error(sprintf('Tenant %d não tem Drive conectado (conecte em "Conectar meu Drive").', $tenantId));

                return null;
            }
        }

        $alvos = [];
        foreach ($this->conexoes->findTodasAtivas() as $conexao) {
            $tenant = $conexao->getTenant();
            if ($tenant === null) {
                continue;
            }
            try {
                $alvos[] = [$tenant, $this->clientFactory->paraTenant($tenant)];
            } catch (TenantSemDriveException) {
                // conexão marcada ativa mas incompleta — ignora (defensivo)
            }
        }

        return $alvos;
    }

    /**
     * Processa um tenant: lock próprio, dry-run em transação revertida, as 3 vias. Devolve true se
     * o EM fechou (fatal — a rodada deve abortar).
     *
     * @param array<string, int> $totais
     */
    private function processarTenant(Tenant $tenant, DriveDoTenant $drive, User $usuario, InputInterface $input, ModoSincronizacao $modo, array &$totais, SymfonyStyle $io): bool
    {
        $tenantId = (int) $tenant->getId();
        $dryRun   = (bool) $input->getOption('dry-run');
        $limit    = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $pastaIdOpt = $input->getOption('pasta-id') !== null ? (int) $input->getOption('pasta-id') : null;
        $skipArquivos = (bool) $input->getOption('skip-arquivos');
        $conn = $this->em->getConnection();

        // Lock por tenant: impede rodadas concorrentes (cron + manual) para o mesmo escritório.
        $lock = fopen(sys_get_temp_dir() . '/jusprime-sync-reconciliar-' . $tenantId . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $io->warning(sprintf('Outra reconciliação já roda para o tenant %d. Pulando.', $tenantId));

            return false;
        }

        if ($dryRun) {
            $conn->beginTransaction();
        }

        $fatal = false;
        try {
            if ($modo->envia()) {
                $fatal = $this->sistemaParaDrive($tenantId, $pastaIdOpt, $limit, $drive, $dryRun, $totais, $io);
            }
            // R2: a descoberta Drive→sistema (subpasta nova vira Pasta) só roda sob pedido
            // explícito. É a via que criava pastas no sistema a partir do Drive de hora em hora.
            if (!$fatal && $modo->importa() && $pastaIdOpt === null) {
                $fatal = $this->driveParaSistema($tenantId, (int) $usuario->getId(), $limit, $drive, $dryRun, $totais, $io);
            }
            if (!$fatal && !$skipArquivos) {
                $fatal = $this->reconciliarArquivos($tenantId, $pastaIdOpt, $limit, $drive->client, $dryRun, $modo, $totais, $io);
            }
        } finally {
            if ($dryRun && $conn->isTransactionActive()) {
                $conn->rollBack();
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $fatal;
    }

    /**
     * Via sistema→Drive (pastas): cada Pasta sem drive_folder_id ganha uma pasta no Drive.
     * Delega item a item ao serviço. @return bool true se o EM fechou (fatal).
     *
     * @param array<string, int> $totais
     */
    private function sistemaParaDrive(int $tenantId, ?int $pastaIdOpt, ?int $limit, DriveDoTenant $drive, bool $dryRun, array &$totais, SymfonyStyle $io): bool
    {
        $conn = $this->em->getConnection();
        $sql  = 'SELECT id FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NULL';
        $params = ['t' => $tenantId];
        if ($pastaIdOpt !== null) {
            $sql .= ' AND id = :pid';
            $params['pid'] = $pastaIdOpt;
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        /** @var list<int> $ids */
        $ids = array_map('intval', $conn->fetchFirstColumn($sql, $params));

        $r = new ResultadoReconciliacaoPasta();
        foreach ($ids as $id) {
            $this->reconciliador->garantirFolderDaPasta($id, $drive->rootFolderId, $drive->client, $dryRun, $r);
            if ($r->fatal) {
                break;
            }
        }
        $this->absorver($r, $totais, $io);

        return $r->fatal;
    }

    /**
     * Via Drive→sistema (descoberta): subpasta sem par vira Pasta (NUP extraído); sem NUP numérico
     * pula (D9); divergência de nome só reporta (D10). Fica no comando (é varredura da raiz, não
     * por-pasta). @return bool true se o EM fechou (fatal).
     *
     * @param array<string, int> $totais
     */
    private function driveParaSistema(int $tenantId, int $usuarioId, ?int $limit, DriveDoTenant $drive, bool $dryRun, array &$totais, SymfonyStyle $io): bool
    {
        $conn = $this->em->getConnection();
        // Map escalar drive_folder_id → nome esperado (não hidrata entidades).
        $rows = $conn->fetchAllAssociative(
            'SELECT drive_folder_id, nup, nome_cliente, nome_acao FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NOT NULL',
            ['t' => $tenantId],
        );
        $linkadas = [];
        foreach ($rows as $rw) {
            $linkadas[(string) $rw['drive_folder_id']] = ReconciliadorDePasta::nomeEsperado(
                (string) $rw['nup'],
                $rw['nome_cliente'] !== null ? (string) $rw['nome_cliente'] : null,
                $rw['nome_acao'] !== null ? (string) $rw['nome_acao'] : null,
            );
        }

        $subs = $drive->client->listarSubpastas($drive->rootFolderId);
        if ($limit !== null) {
            $subs = array_slice($subs, 0, $limit);
        }

        foreach ($subs as $sub) {
            if (isset($linkadas[$sub['id']])) {
                if ($linkadas[$sub['id']] !== $sub['nome']) {
                    $totais['divergencias']++;
                    $io->writeln(sprintf(
                        '  <comment>[divergência]</comment> drive_folder_id=%s: sistema="%s" drive="%s"',
                        $sub['id'], $linkadas[$sub['id']], $sub['nome'],
                    ));
                }

                continue;
            }

            $rParse = $this->parser->parsear($sub['nome']);
            $item = $rParse['alta'][0] ?? $rParse['revisao'][0] ?? null;
            // D9: sem NUP extraível → pula e reporta (não cria pasta com NUP lixo).
            if ($item === null || !$this->ehNupValido($item['nup'])) {
                $totais['semNup']++;
                $io->writeln(sprintf('  <comment>[sem NUP]</comment> "%s" — pulada (D9)', $sub['nome']));

                continue;
            }

            try {
                // getReference: proxies leves, válidos mesmo após em->clear() da iteração anterior.
                $tenant  = $this->em->getReference(Tenant::class, $tenantId);
                $usuario = $this->em->getReference(User::class, $usuarioId);
                $dto = new CriarPastaDTO(
                    nup: $item['nup'],
                    nomeCliente: $item['cliente'] !== '' ? $item['cliente'] : null,
                    nomeAcao: $item['acao'] !== '' ? $item['acao'] : null,
                );
                $pasta = $this->criarPasta->executar($dto, $usuario, $tenant);
                $pasta->setDriveFolderId($sub['id']);
                $pasta->setDriveSyncedAt(new \DateTimeImmutable());
                $this->em->flush(); // executar() persistiu sem driveFolderId; aqui grava o vínculo
                $this->em->clear();
                $totais['criadasNoSistema']++;
            } catch (\Throwable $e) {
                $totais['erros']++;
                $io->writeln(sprintf('  <error>[erro]</error> drive_folder_id=%s (Drive→sistema): %s', $sub['id'], $e->getMessage()));
                if (!$this->em->isOpen()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Via de ARQUIVOS: para cada Pasta vinculada, delega ao serviço por-pasta.
     * @return bool true se o EM fechou (fatal).
     *
     * @param array<string, int> $totais
     */
    private function reconciliarArquivos(int $tenantId, ?int $pastaIdOpt, ?int $limit, GoogleDriveClientInterface $client, bool $dryRun, ModoSincronizacao $modo, array &$totais, SymfonyStyle $io): bool
    {
        $conn   = $this->em->getConnection();
        $sql    = 'SELECT id FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NOT NULL';
        $params = ['t' => $tenantId];
        if ($pastaIdOpt !== null) {
            $sql .= ' AND id = :pid';
            $params['pid'] = $pastaIdOpt;
        }
        $sql .= ' ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . $limit;
        }
        /** @var list<int> $ids */
        $ids = array_map('intval', $conn->fetchFirstColumn($sql, $params));

        $r = new ResultadoReconciliacaoPasta();
        foreach ($ids as $id) {
            $this->reconciliador->reconciliarArquivosDaPasta($id, $client, $dryRun, $r, $modo);
            if ($r->fatal) {
                break;
            }
        }
        $this->absorver($r, $totais, $io);

        return $r->fatal;
    }

    /**
     * NUP válido para criar Pasta: dígitos com sufixo opcional de uma letra (ex.: 10, 10A, 10B).
     */
    private function ehNupValido(string $nup): bool
    {
        return preg_match('/^\d+[A-Za-z]?$/', trim($nup)) === 1;
    }

    /**
     * Soma os contadores do serviço por-pasta ao agregado do comando e imprime as mensagens dele.
     *
     * @param array<string, int> $totais
     */
    private function absorver(ResultadoReconciliacaoPasta $r, array &$totais, SymfonyStyle $io): void
    {
        $totais['criadasNoDrive']      += $r->criadasNoDrive;
        $totais['renomeadasNoDrive']   += $r->renomeadasNoDrive;
        $totais['arquivosEnviados']    += $r->arquivosEnviados;
        $totais['arquivosBaixados']    += $r->arquivosBaixados;
        $totais['secoesArquivos']      += $r->secoesArquivos;
        $totais['googleNative']        += $r->googleNative;
        $totais['ignoradosTamanho']    += $r->ignoradosTamanho;
        $totais['ignoradosNome']       += $r->ignoradosNome;
        $totais['ignoradosDuplicados'] += $r->ignoradosDuplicados;
        $totais['erros']               += $r->erros;

        foreach ($r->mensagens as $msg) {
            $io->writeln('  ' . $msg);
        }
    }

    /** @return array<string, int> */
    private function totaisZerados(): array
    {
        return [
            'criadasNoDrive' => 0, 'renomeadasNoDrive' => 0, 'criadasNoSistema' => 0, 'semNup' => 0, 'divergencias' => 0,
            'erros' => 0, 'arquivosEnviados' => 0, 'arquivosBaixados' => 0, 'secoesArquivos' => 0,
            'googleNative' => 0, 'ignoradosTamanho' => 0, 'ignoradosNome' => 0, 'ignoradosDuplicados' => 0,
        ];
    }

    /** @param array<string, int> $totais */
    private function imprimirResumo(array $totais, bool $dryRun, bool $skipArquivos, SymfonyStyle $io): void
    {
        $io->section('Resumo da reconciliação de pastas');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Pastas criadas no Drive (simulado)' : 'Pastas criadas no Drive', $totais['criadasNoDrive']],
            [$dryRun ? 'Pastas criadas no sistema (simulado)' : 'Pastas criadas no sistema', $totais['criadasNoSistema']],
            [$dryRun ? 'Pastas renomeadas no Drive (simulado)' : 'Pastas renomeadas no Drive', $totais['renomeadasNoDrive']],
            ['Pastas do Drive sem NUP (puladas)', $totais['semNup']],
            ['Divergências de nome (só reporta)', $totais['divergencias']],
        ]);

        if ($skipArquivos) {
            $io->note('Sincronização de arquivos PULADA (--skip-arquivos): só as pastas foram reconciliadas.');
        }

        $io->section('Resumo da reconciliação de arquivos');
        $io->table(['Métrica', 'Total'], [
            [$dryRun ? 'Arquivos enviados ao Drive (simulado)' : 'Arquivos enviados ao Drive', $totais['arquivosEnviados']],
            [$dryRun ? 'Arquivos baixados do Drive (simulado)' : 'Arquivos baixados do Drive', $totais['arquivosBaixados']],
            ['Seções criadas (Drive→sistema)', $totais['secoesArquivos']],
            ['Arquivos Google-native pulados', $totais['googleNative']],
            ['Arquivos ignorados por tamanho (coluna INT4)', $totais['ignoradosTamanho']],
            ['Arquivos ignorados por nome > 255 chars', $totais['ignoradosNome']],
            ['Arquivos já vinculados a outra pasta (pulados)', $totais['ignoradosDuplicados']],
            ['Erros por item (logados)', $totais['erros']],
        ]);
    }
}
