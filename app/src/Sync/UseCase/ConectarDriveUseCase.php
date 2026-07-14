<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Sync\DTO\ResultadoAutenticacaoDrive;
use App\Sync\Entity\TenantDriveConexao;
use App\Sync\Repository\TenantDriveConexaoRepository;
use App\Sync\Service\CifradorDeSegredo;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Guarda (upsert por tenant) as credenciais OAuth do Drive de um escritório após o callback:
 * cifra o refresh_token e registra e-mail/escopo. A conexão só vira usável quando a pasta-raiz
 * também for definida ({@see DefinirRootFolderDriveUseCase}) — aqui ela pode ficar inativa.
 * Multi-tenant: opera exclusivamente sobre a conexão DO tenant informado.
 */
final class ConectarDriveUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantDriveConexaoRepository $conexoes,
        private readonly CifradorDeSegredo $cifrador,
    ) {
    }

    public function executar(Tenant $tenant, User $por, ResultadoAutenticacaoDrive $auth): TenantDriveConexao
    {
        if (trim($auth->refreshToken) === '') {
            throw new \InvalidArgumentException('refresh_token ausente — não é possível conectar o Drive.');
        }

        $conexao = $this->conexoes->findDoTenant($tenant) ?? new TenantDriveConexao($tenant);

        $conexao->registrarCredenciais(
            $this->cifrador->cifrar($auth->refreshToken),
            $auth->email,
            $auth->escopo,
            $por,
        );

        $this->em->persist($conexao);
        $this->em->flush();

        return $conexao;
    }
}
