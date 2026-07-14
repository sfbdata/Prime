<?php

declare(strict_types=1);

namespace App\Sync\Command;

use App\Entity\Auth\User;
use App\Repository\TenantRepository;
use App\Sync\DTO\ResultadoAutenticacaoDrive;
use App\Sync\UseCase\ConectarDriveUseCase;
use App\Sync\UseCase\DefinirRootFolderDriveUseCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Semeia a {@see \App\Sync\Entity\TenantDriveConexao} de um tenant a partir das credenciais OAuth
 * GLOBAIS de ambiente (`GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN` + `GOOGLE_DRIVE_SHARED_DRIVE_ID`) — a ponte
 * usada hoje pelo cron do tenant 1. Passo de migração: depois que o reconcile passa a ler a conexão
 * do banco (Marco 2), este comando promove o env para uma conexão persistida e cifrada. Idempotente.
 */
#[AsCommand(
    name: 'app:sync:conectar-tenant',
    description: 'Cria/atualiza a conexão de Drive de um tenant a partir das credenciais OAuth de ambiente.',
)]
final class ConectarTenantCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantRepository $tenantRepository,
        private readonly ConectarDriveUseCase $conectar,
        private readonly DefinirRootFolderDriveUseCase $definirRootFolder,
        private readonly string $googleDriveOauthRefreshToken,
        private readonly string $googleDriveSharedDriveId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do tenant a conectar')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário responsável pela conexão');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $refreshToken = trim($this->googleDriveOauthRefreshToken);
        $rootFolderId = trim($this->googleDriveSharedDriveId);
        if ($refreshToken === '' || $rootFolderId === '') {
            $io->error('Configure GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN e GOOGLE_DRIVE_SHARED_DRIVE_ID no ambiente.');

            return Command::FAILURE;
        }

        $tenantId = $input->getOption('tenant-id');
        $usuarioId = $input->getOption('usuario-id');
        if ($tenantId === null || $usuarioId === null) {
            $io->error('Informe --tenant-id e --usuario-id.');

            return Command::FAILURE;
        }

        $tenant = $this->tenantRepository->find((int) $tenantId);
        $usuario = $this->em->find(User::class, (int) $usuarioId);
        if ($tenant === null || $usuario === null) {
            $io->error('Tenant ou usuário inexistente.');

            return Command::FAILURE;
        }

        $this->conectar->executar(
            $tenant,
            $usuario,
            new ResultadoAutenticacaoDrive($refreshToken, null, 'env:google_drive_oauth'),
        );
        $conexao = $this->definirRootFolder->executar($tenant, $rootFolderId);

        $io->success(sprintf(
            'Conexão do tenant %d %s (pasta-raiz %s).',
            $tenant->getId(),
            $conexao->estaPronta() ? 'ativa' : 'criada (inativa)',
            $conexao->getRootFolderId() ?? '—',
        ));

        return Command::SUCCESS;
    }
}
