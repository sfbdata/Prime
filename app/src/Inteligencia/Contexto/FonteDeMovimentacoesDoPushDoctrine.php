<?php

declare(strict_types=1);

namespace App\Inteligencia\Contexto;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Implementação Doctrine da {@see FonteDeMovimentacoesDoPush}. Tenant explícito em TODA consulta:
 * este código roda no worker, onde o TenantFilter é inerte.
 */
final class FonteDeMovimentacoesDoPushDoctrine implements FonteDeMovimentacoesDoPush
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $usuarios,
    ) {
    }

    public function publicacoesDoTenant(Tenant $tenant, array $numeros, int $limite): array
    {
        $numeros = self::normalizarNumeros($numeros);
        if ($numeros === []) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(PublicacaoDjen::class, 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.numeroProcesso IN (:numeros)')
            ->setParameter('tenant', $tenant)
            ->setParameter('numeros', $numeros)
            ->orderBy('p.dataDisponibilizacao', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function movimentacoesDoTenant(Tenant $tenant, array $processoIds, int $limite): array
    {
        if ($processoIds === []) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('m')
            ->from(MovimentacaoProcesso::class, 'm')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.processo IN (:processos)')
            ->setParameter('tenant', $tenant)
            ->setParameter('processos', $processoIds)
            ->orderBy('m.dataMovimentacao', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    public function chavesDoTenant(Tenant $tenant, array $numeros, array $processoIds, int $limitePublicacoes, int $limiteMovimentacoes): array
    {
        $chaves = [];

        $numeros = self::normalizarNumeros($numeros);
        if ($numeros !== []) {
            $ids = $this->em->createQueryBuilder()
                ->select('p.id')
                ->from(PublicacaoDjen::class, 'p')
                ->andWhere('p.tenant = :tenant')
                ->andWhere('p.numeroProcesso IN (:numeros)')
                ->setParameter('tenant', $tenant)
                ->setParameter('numeros', $numeros)
                ->orderBy('p.dataDisponibilizacao', 'DESC')
                ->addOrderBy('p.id', 'DESC')
                ->setMaxResults($limitePublicacoes)
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($ids as $id) {
                $chaves[] = 'pub:' . (int) $id;
            }
        }

        if ($processoIds !== []) {
            $ids = $this->em->createQueryBuilder()
                ->select('m.id')
                ->from(MovimentacaoProcesso::class, 'm')
                ->andWhere('m.tenant = :tenant')
                ->andWhere('m.processo IN (:processos)')
                ->setParameter('tenant', $tenant)
                ->setParameter('processos', $processoIds)
                ->orderBy('m.dataMovimentacao', 'DESC')
                ->addOrderBy('m.id', 'DESC')
                ->setMaxResults($limiteMovimentacoes)
                ->getQuery()
                ->getSingleColumnResult();
            foreach ($ids as $id) {
                $chaves[] = 'mov:' . (int) $id;
            }
        }

        return $chaves;
    }

    public function nomesDaEquipe(Tenant $tenant): array
    {
        $nomes = [];
        foreach ($this->usuarios->findColaboradoresAtivosPorTenant($tenant) as $usuario) {
            if (!$usuario instanceof User) {
                continue;
            }
            $nome = trim((string) $usuario->getFullName());
            if ($nome !== '') {
                $nomes[] = $nome;
            }
        }

        return $nomes;
    }

    /**
     * Mesma normalização do PublicacaoDjenRepository: a publicação grava só dígitos; o processo
     * pode chegar mascarado. Sem isto o casamento falha calado.
     *
     * @param list<string> $numeros
     * @return list<string>
     */
    private static function normalizarNumeros(array $numeros): array
    {
        $digitos = [];
        foreach ($numeros as $numero) {
            $so = preg_replace('/\D/', '', (string) $numero) ?? '';
            if ($so !== '') {
                $digitos[$so] = true;
            }
        }

        return array_keys($digitos);
    }
}
