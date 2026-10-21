<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use App\Agenda\Entity\ExclusaoNoGoogle;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ExclusaoNoGoogle> */
class ExclusaoNoGoogleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExclusaoNoGoogle::class);
    }

    /** @return list<ExclusaoNoGoogle> */
    public function pendentesDaPessoa(User $usuario, Tenant $tenant): array
    {
        return $this->findBy(['usuario' => $usuario, 'tenant' => $tenant], ['id' => 'ASC']);
    }

    /** Em SQL, sem passar pelo flush de quem está apagando o compromisso; repetir não duplica. */
    public function anotar(Tenant $tenant, User $usuario, string $googleId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO agenda_google_exclusao (tenant_id, usuario_id, google_id) VALUES (:t, :u, :g) ON CONFLICT (usuario_id, google_id) DO NOTHING',
            ['t' => $tenant->getId(), 'u' => $usuario->getId(), 'g' => $googleId],
        );
    }

    /** As pendentes da pessoa no escritório, em SQL: vale também de dentro de uma sessão de outro escritório (lote). */
    public function esquecerDaPessoa(User $usuario, Tenant $tenant): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM agenda_google_exclusao WHERE usuario_id = :u AND tenant_id = :t',
            ['u' => $usuario->getId(), 't' => $tenant->getId()],
        );
    }

    public function concluir(ExclusaoNoGoogle $exclusao): void
    {
        $this->getEntityManager()->remove($exclusao);
    }
}
