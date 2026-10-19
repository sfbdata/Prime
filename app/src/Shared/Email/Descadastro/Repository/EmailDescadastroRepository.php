<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Repository;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Entity\EmailDescadastro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Persistência do descadastro, por SQL e com o tenant EXPLÍCITO.
 *
 * Por que não pelo ORM: o link é aberto por quem não está logado, ou por alguém logado em OUTRO
 * escritório (o TenantFilter estaria ligado no tenant da sessão e esconderia a linha existente,
 * e a segunda gravação estouraria o UNIQUE). A captação roda no CLI, sem filtro. SQL com o tenant
 * na mão vale igual nos três casos, e o `ON CONFLICT DO NOTHING` faz do clique repetido um no-op.
 *
 * @extends ServiceEntityRepository<EmailDescadastro>
 */
class EmailDescadastroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailDescadastro::class);
    }

    /** Grava o descadastro; se já existe, não faz nada. Os valores chegam já normalizados. */
    public function registrarSeAusente(Tenant $tenant, string $email, string $assunto, string $origem, \DateTimeImmutable $em): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO email_descadastro (tenant_id, email, assunto, origem, em)
             VALUES (:tenant, :email, :assunto, :origem, :em)
             ON CONFLICT (tenant_id, email, assunto) DO NOTHING',
            [
                'tenant' => $tenant->getId(),
                'email' => $email,
                'assunto' => $assunto,
                'origem' => $origem,
                'em' => $em->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function existe(Tenant $tenant, string $email, string $assunto): bool
    {
        $achou = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM email_descadastro WHERE tenant_id = :tenant AND email = :email AND assunto = :assunto',
            ['tenant' => $tenant->getId(), 'email' => $email, 'assunto' => $assunto],
        );

        return $achou !== false;
    }
}
