<?php

declare(strict_types=1);

namespace App\Recompensa\Repository;

use App\Recompensa\Entity\LancamentoDeRecompensa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O extrato de recompensas. Só grava e consulta: o livro não tem remoção. Sem filtro de tenant de propósito: é dado
 * GLOBAL da plataforma, lido só pelo Master (ver {@see LancamentoDeRecompensa}).
 *
 * @extends ServiceEntityRepository<LancamentoDeRecompensa>
 */
class LancamentoDeRecompensaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LancamentoDeRecompensa::class);
    }

    public function salvar(LancamentoDeRecompensa $lancamento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($lancamento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarPorChave(string $chave): ?LancamentoDeRecompensa
    {
        return $this->findOneBy(['chaveDeIdempotencia' => $chave]);
    }

    /** Segura a chave até o fim da transação: dois pedidos iguais ao mesmo tempo passam um de cada vez. */
    public function travarChave(string $chave): void
    {
        $this->getEntityManager()->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$chave]);
    }

    /**
     * Do mais recente para o mais antigo, com o indicador já carregado (uma consulta só).
     *
     * @return list<LancamentoDeRecompensa>
     */
    public function doExtrato(?int $userId, ?int $limite): array
    {
        $consulta = $this->createQueryBuilder('l')
            ->leftJoin('l.user', 'u')->addSelect('u')
            ->orderBy('l.criadoEm', 'DESC')->addOrderBy('l.id', 'DESC');
        if ($userId !== null) {
            $consulta->andWhere('IDENTITY(l.user) = :usuario')->setParameter('usuario', $userId);
        }
        if ($limite !== null) {
            $consulta->setMaxResults($limite);
        }

        return array_values($consulta->getQuery()->getResult());
    }

    /**
     * Os lançamentos `bonus` (com sinal) e `pago` de cada indicador, com a data, em UMA consulta (sem N+1): a validade
     * do crédito ({@see \App\Recompensa\Service\SaldoComValidade}) precisa da data de cada um. Quem foi apagado (user
     * nulo) fica de fora. Com `$userId`, só os dele.
     *
     * @return array<int, list<array{tipo: string, valorCentavos: int, criadoEm: \DateTimeImmutable}>>
     */
    public function porIndicador(?int $userId = null): array
    {
        $consulta = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.user) AS usuario', 'l.tipo', 'l.valorCentavos', 'l.criadoEm')
            ->where('l.tipo IN (:tipos)')->andWhere('l.user IS NOT NULL')
            ->setParameter('tipos', [LancamentoDeRecompensa::TIPO_BONUS, LancamentoDeRecompensa::TIPO_PAGO])
            ->orderBy('l.criadoEm', 'ASC')->addOrderBy('l.id', 'ASC');
        if ($userId !== null) {
            $consulta->andWhere('IDENTITY(l.user) = :usuario')->setParameter('usuario', $userId);
        }

        $porIndicador = [];
        foreach ($consulta->getQuery()->getArrayResult() as $linha) {
            $em = $linha['criadoEm'];
            $porIndicador[(int) $linha['usuario']][] = [
                'tipo' => (string) $linha['tipo'],
                'valorCentavos' => (int) $linha['valorCentavos'],
                'criadoEm' => $em instanceof \DateTimeImmutable ? $em : new \DateTimeImmutable((string) $em),
            ];
        }

        return $porIndicador;
    }
}
