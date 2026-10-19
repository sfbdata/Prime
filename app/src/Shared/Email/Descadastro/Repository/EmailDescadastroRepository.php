<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Repository;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Entity\EmailDescadastro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Persistência do descadastro, por SQL e com o escopo EXPLÍCITO (o escritório, ou null = plataforma).
 *
 * Por que não pelo ORM: o link é aberto por quem não está logado, ou por alguém logado em OUTRO
 * escritório (o TenantFilter estaria ligado no tenant da sessão e esconderia a linha existente,
 * e a segunda gravação estouraria o UNIQUE). A captação roda no CLI, sem filtro. SQL com o escopo
 * na mão vale igual nos três casos, e o `ON CONFLICT DO NOTHING` (contra o índice parcial do
 * escopo) faz do clique repetido um no-op.
 *
 * @extends ServiceEntityRepository<EmailDescadastro>
 */
class EmailDescadastroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailDescadastro::class);
    }

    /** Grava o descadastro no escopo; se já existe, não faz nada. Os valores chegam já normalizados. */
    public function registrarSeAusente(?Tenant $tenant, string $email, string $assunto, string $origem, \DateTimeImmutable $em): void
    {
        $conflito = $tenant === null
            ? '(email, assunto) WHERE tenant_id IS NULL'
            : '(tenant_id, email, assunto) WHERE tenant_id IS NOT NULL';

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO email_descadastro (tenant_id, email, assunto, origem, em)
             VALUES (:tenant, :email, :assunto, :origem, :em)
             ON CONFLICT ' . $conflito . ' DO NOTHING',
            [
                'tenant' => $tenant?->getId(),
                'email' => $email,
                'assunto' => $assunto,
                'origem' => $origem,
                'em' => $em->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Se há linha do endereço, no escopo, para QUALQUER um dos assuntos informados.
     *
     * @param list<string> $assuntos
     */
    public function existeAlgum(?Tenant $tenant, string $email, array $assuntos): bool
    {
        $escopo = $tenant === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant';
        $parametros = ['email' => $email, 'assuntos' => $assuntos];
        $tipos = ['assuntos' => ArrayParameterType::STRING];
        if ($tenant !== null) {
            $parametros['tenant'] = $tenant->getId();
        }

        $achou = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM email_descadastro WHERE ' . $escopo . ' AND email = :email AND assunto IN (:assuntos) LIMIT 1',
            $parametros,
            $tipos,
        );

        return $achou !== false;
    }
}
