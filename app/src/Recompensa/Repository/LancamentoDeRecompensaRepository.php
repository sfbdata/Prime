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

    /** @return array<int, int> soma dos lançamentos `bonus` por indicador (com sinal); quem foi apagado fica de fora */
    public function somaDoBonusPorIndicador(): array
    {
        return $this->somaPorIndicador(LancamentoDeRecompensa::TIPO_BONUS);
    }

    /** @return array<int, int> soma dos pagamentos (`pago`, sempre positivos) por indicador */
    public function somaDoPagoPorIndicador(): array
    {
        return $this->somaPorIndicador(LancamentoDeRecompensa::TIPO_PAGO);
    }

    /** @return array<int, int> */
    private function somaPorIndicador(string $tipo): array
    {
        $linhas = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.user) AS usuario', 'SUM(l.valorCentavos) AS soma')
            ->where('l.tipo = :tipo')->andWhere('l.user IS NOT NULL')
            ->setParameter('tipo', $tipo)
            ->groupBy('l.user')
            ->getQuery()->getArrayResult();

        $soma = [];
        foreach ($linhas as $linha) {
            $soma[(int) $linha['usuario']] = (int) $linha['soma'];
        }

        return $soma;
    }
}
