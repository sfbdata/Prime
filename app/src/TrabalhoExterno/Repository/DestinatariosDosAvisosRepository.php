<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * As leituras de que os avisos do Trabalho Externo precisam para achar quem recebe (spec VS 18.10.26
 * §4.7): os Masters, as pessoas ativas de um escritório, o escritório de quem não tem um na vaga, e se já
 * há aviso pendente igual. Toda consulta por pessoa recebe o escritório: nada sai de outro tenant.
 *
 * Sem `final`: os testes do serviço de avisos mockam esta classe.
 */
class DestinatariosDosAvisosRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Os Masters (D1: `ROLE_SUPER_ADMIN`) com conta ativa. `roles` é `json` no PostgreSQL: a busca é pelo
     * texto com as aspas, como em `UserRepository::findComPapelGlobal`.
     *
     * @return list<User>
     */
    public function masters(): array
    {
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM "user" WHERE CAST(roles AS TEXT) LIKE :papel AND is_active = true ORDER BY id',
            ['papel' => '%"ROLE_SUPER_ADMIN"%'],
        );

        return $ids === [] ? [] : $this->em->getRepository(User::class)->findBy(['id' => array_map('intval', $ids)], ['id' => 'ASC']);
    }

    /**
     * Quem tem vínculo ativo no escritório, com a conta ativa, na ordem de id. Escritório fora do ar (suspenso
     * ou excluído) não recebe aviso (docs/specs/te-convidado-escritorio-fora-do-ar.md). Quem chama confere a
     * permissão do módulo de cada um.
     *
     * @return list<User>
     */
    public function ativosDoEscritorio(Tenant $escritorio): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.user = u AND ut.tenant = :tenant AND ut.isActive = true')
            ->join('ut.tenant', 't', 'WITH', 't.isActive = true AND t.excluidoEm IS NULL')
            ->andWhere('u.isActive = true')
            ->setParameter('tenant', $escritorio)
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** O escritório ativo, pelo id, só se a pessoa tem vínculo ativo nele (o alerta guarda o id). */
    public function escritorioDaPessoa(User $usuario, int $escritorioId): ?Tenant
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(Tenant::class, 't')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.tenant = t AND ut.user = :usuario AND ut.isActive = true')
            ->andWhere('t.id = :id')
            ->andWhere('t.isActive = true')
            ->setParameter('usuario', $usuario)
            ->setParameter('id', $escritorioId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O primeiro escritório ativo da pessoa (o Convidado que divulgou sem escritório na vaga). */
    public function primeiroEscritorio(User $usuario): ?Tenant
    {
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(Tenant::class, 't')
            ->join(UserTenant::class, 'ut', 'WITH', 'ut.tenant = t AND ut.user = :usuario AND ut.isActive = true')
            ->andWhere('t.isActive = true')
            ->setParameter('usuario', $usuario)
            ->orderBy('t.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Já há aviso NÃO LIDO deste tipo, para esta pessoa, neste escritório e neste link. Pela conexão, de
     * propósito: o `TenantFilter` da requisição é o escritório de quem age, e o aviso é de outro.
     */
    public function temPendente(User $usuario, Tenant $escritorio, string $tipo, string $url): bool
    {
        return $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM notificacao WHERE usuario_id = :u AND tenant_id = :t AND tipo = :tipo AND url = :url AND lida = false LIMIT 1',
            ['u' => $usuario->getId(), 't' => $escritorio->getId(), 'tipo' => $tipo, 'url' => $url],
        ) !== false;
    }
}
