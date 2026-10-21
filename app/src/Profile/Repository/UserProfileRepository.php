<?php
declare(strict_types=1);
namespace App\Profile\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserProfile::class);
    }

    public function salvar(UserProfile $perfil, bool $flush = false): void
    {
        $this->getEntityManager()->persist($perfil);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(UserProfile $perfil, bool $flush = false): void
    {
        $this->getEntityManager()->remove($perfil);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarPorUsuario(User $user): ?UserProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Colaboradores ativos do escritório com data de nascimento, para os aniversários da Agenda.
     *
     * @return list<array{id: int, nome: string, nascimento: \DateTimeInterface}>
     */
    public function aniversariantesDoEscritorio(Tenant $tenant): array
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT u.id AS id, u.fullName AS nome, up.dataNascimento AS nascimento
                 FROM App\Profile\Entity\UserProfile up
                 JOIN up.user u
                 JOIN App\Entity\Auth\UserTenant ut WITH ut.user = u
                 WHERE ut.tenant = :tenant
                   AND ut.isActive = true
                   AND u.isActive = true
                   AND up.dataNascimento IS NOT NULL
                 ORDER BY u.fullName ASC'
            )
            ->setParameter('tenant', $tenant)
            ->getArrayResult();
    }

    public function buscarPorFotoUrlETenant(string $fotoUrl, Tenant $tenant): ?UserProfile
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT up FROM App\Profile\Entity\UserProfile up
                 JOIN up.user u
                 JOIN App\Entity\Auth\UserTenant ut WITH ut.user = u
                 WHERE up.fotoUrl  = :fotoUrl
                   AND ut.tenant   = :tenant
                   AND ut.isActive = true'
            )
            ->setParameter('fotoUrl', $fotoUrl)
            ->setParameter('tenant', $tenant)
            ->getOneOrNullResult();
    }

    /**
     * Leitor em lote para o motor de filtros do Comercial (frente 5d, F2; spec docs/specs/meu-perfil-genero-atuacao.md):
     * gênero e atuação declarados (string crua do enum ou null), nascimento e a UF da OAB principal (cópia no User).
     * Uma consulta só, indexada pelo userId; quem não tem perfil vem com tudo null, e id que não existe fica de fora.
     *
     * @param list<int> $userIds
     *
     * @return array<int, array{genero: ?string, atuacao: ?string, dataNascimento: ?\DateTimeImmutable, uf: ?string}>
     */
    public function atributosDe(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT u.id, p.genero, p.atuacao, p.data_nascimento, u.oab_uf
               FROM "user" u LEFT JOIN user_profiles p ON p.user_id = u.id
              WHERE u.id IN (?)',
            [array_values(array_unique($userIds))],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int) $l['id']] = [
                'genero'         => $l['genero'] ?? null,
                'atuacao'        => $l['atuacao'] ?? null,
                'dataNascimento' => $l['data_nascimento'] !== null ? new \DateTimeImmutable((string) $l['data_nascimento']) : null,
                'uf'             => $l['oab_uf'] !== null && trim((string) $l['oab_uf']) !== '' ? strtoupper(trim((string) $l['oab_uf'])) : null,
            ];
        }

        return $porId;
    }
}
