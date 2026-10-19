<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Escritórios como CONTAS do sistema, para as ações do Master (suspender e reativar, spec
 * perfis-1.2-contas). Busca por id em TODOS os escritórios de propósito: o Master administra a
 * plataforma, não um escritório. Quem chama: só os UseCases do domínio Master, atrás de
 * ROLE_SUPER_ADMIN no controller e da checagem de Master no UseCase.
 *
 * Não estende ServiceEntityRepository: a entidade Tenant já tem o repositório legado dela
 * (App\Repository\TenantRepository); este é o recorte do Master, mockável nos testes de UseCase.
 */
class ContasDoSistemaRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function buscar(int $contaId): ?Tenant
    {
        return $contaId > 0 ? $this->em->find(Tenant::class, $contaId) : null;
    }

    public function salvar(Tenant $conta, bool $flush = false): void
    {
        $this->em->persist($conta);
        if ($flush) {
            $this->em->flush();
        }
    }
}
