<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\MetricaDiariaDoSistema;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As fotos diárias das métricas do gráfico Crescimento ({@see MetricaDiariaDoSistema}). Global (sem tenant):
 * só o Master lê, e só números.
 *
 * @extends ServiceEntityRepository<MetricaDiariaDoSistema>
 */
class MetricaDiariaDoSistemaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MetricaDiariaDoSistema::class);
    }

    /**
     * Grava a foto do dia: uma linha por métrica; a mesma tarefa rodando de novo no mesmo dia regrava o valor
     * (ON CONFLICT na chave dia + métrica), sem duplicar.
     *
     * @param array<string, int> $valores métrica => valor
     */
    public function gravarDoDia(\DateTimeImmutable $dia, array $valores, \DateTimeImmutable $agora): void
    {
        $conexao = $this->getEntityManager()->getConnection();
        foreach ($valores as $metrica => $valor) {
            $conexao->executeStatement(
                'INSERT INTO master_metrica_diaria (dia, metrica, valor, gravado_em) VALUES (:dia, :metrica, :valor, :agora)
                 ON CONFLICT (dia, metrica) DO UPDATE SET valor = EXCLUDED.valor, gravado_em = EXCLUDED.gravado_em',
                ['dia' => $dia->format('Y-m-d'), 'metrica' => $metrica, 'valor' => $valor, 'agora' => $agora->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * Todas as fotos, por métrica e dia.
     *
     * @return array<string, array<string, float>> métrica => ['YYYY-MM-DD' => valor]
     */
    public function porMetricaEDia(): array
    {
        $saida = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT metrica, to_char(dia, 'YYYY-MM-DD') AS dia, valor FROM master_metrica_diaria ORDER BY dia",
        ) as $l) {
            $saida[(string) $l['metrica']][(string) $l['dia']] = (float) $l['valor'];
        }

        return $saida;
    }
}
