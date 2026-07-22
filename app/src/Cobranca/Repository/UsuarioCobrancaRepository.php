<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Leitura das PESSOAS do setor de cobrança de um escritório — quem deve figurar na aba Atividade da
 * Central mesmo sem ter registrado nada no período (spec §4: "quem não trabalhou aparece zerado, não
 * some da lista"; a ausência é a informação que o gestor foi buscar).
 *
 * Mora no domínio Cobrança, e não em `src/Repository/UserTenantRepository` (legado), porque a pergunta
 * é de cobrança: "quem é a equipe desta tela". Consulta `UserTenant` só como fonte — nada é escrito.
 *
 * O critério de elegibilidade espelha `PermissionChecker::canAccessModule` para o caso do TENANT:
 * vínculo ativo + usuário ativo + (papel de sistema OU permissão `modules.cobrancas.view`). O bypass de
 * `ROLE_SUPER_ADMIN` fica de fora de propósito: super-admin global é acesso de suporte, não gente do
 * setor — listá-lo em toda equipe do sistema seria ruído.
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class UsuarioCobrancaRepository extends ServiceEntityRepository
{
    private const PERMISSAO_MODULO = 'modules.cobrancas.view';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTenant::class);
    }

    /**
     * Equipe elegível do escritório, em ordem alfabética. Nome já resolvido com fallback para o e-mail
     * (`full_name` é NOT NULL mas pode estar vazio em cadastro incompleto — uma linha sem nome na tela
     * do gestor não identifica ninguém).
     *
     * `DISTINCT` porque um papel pode acumular a permissão por mais de uma via no join.
     *
     * @return list<array{id: int, nome: string}>
     */
    public function ativosComAcessoAoModulo(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('ut')
            ->select('DISTINCT u.id AS id', "COALESCE(NULLIF(u.fullName, ''), u.email) AS nome")
            ->join('ut.user', 'u')
            ->join('ut.tenantRole', 'r')
            ->leftJoin('r.tenantRolePermissions', 'trp')
            ->leftJoin('trp.permission', 'p')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('u.isActive = true')
            ->andWhere('r.isSystem = true OR p.code = :permissao')
            ->setParameter('tenant', $tenant)
            ->setParameter('permissao', self::PERMISSAO_MODULO)
            ->orderBy('nome', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'nome' => (string) $l['nome'],
        ], $linhas);
    }

    /**
     * Guarda anti-IDOR da rota de detalhe: resolve o usuário SÓ se ele pertencer ao escritório. Sem
     * isso, um id de outro tenant devolveria uma tela vazia — mas com o NOME de alguém de fora, que é
     * vazamento.
     *
     * Aceita vínculo inativo de propósito: quem saiu da equipe deixou trabalho registrado no período e
     * precisa continuar abrindo. O que não se aceita é vínculo de OUTRO escritório.
     *
     * @return array{id: int, nome: string}|null
     */
    public function doTenantPorId(int $usuarioId, Tenant $tenant): ?array
    {
        /** @var array<int, array{id: int|string, nome: string}> $linhas */
        $linhas = $this->createQueryBuilder('ut')
            ->select('u.id AS id', "COALESCE(NULLIF(u.fullName, ''), u.email) AS nome")
            ->join('ut.user', 'u')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('u.id = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuarioId)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        if ($linhas === []) {
            return null;
        }

        return ['id' => (int) $linhas[0]['id'], 'nome' => (string) $linhas[0]['nome']];
    }
}
