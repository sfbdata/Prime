<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Presenca;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Presenca> */
class PresencaRepository extends ServiceEntityRepository
{
    /** On-line = batimento há menos disto (desenho L279: 60 s; a tela bate a cada 20 s). */
    public const ONLINE_SEGUNDOS = 60;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Presenca::class);
    }

    /**
     * O batimento: cria ou atualiza a linha do vínculo num só comando (duas abas batendo ao mesmo tempo não
     * duplicam nem se atropelam). `status_desde` só anda quando o status MUDA.
     */
    public function registrar(int $tenantId, int $usuarioId, string $status, \DateTimeImmutable $agora): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO central_presenca (tenant_id, usuario_id, ultima_atividade, status, status_desde)
             VALUES (:tenant, :usuario, :agora, :status, :agora)
             ON CONFLICT (tenant_id, usuario_id) DO UPDATE SET
                ultima_atividade = EXCLUDED.ultima_atividade,
                status_desde = CASE WHEN central_presenca.status = EXCLUDED.status
                    THEN central_presenca.status_desde ELSE EXCLUDED.status_desde END,
                status = EXCLUDED.status',
            [
                'tenant' => $tenantId,
                'usuario' => $usuarioId,
                'agora' => $agora->format('Y-m-d H:i:s'),
                'status' => $status,
            ],
        );
    }

    /**
     * Presença das pessoas DESTE escritório (tenant explícito: a presença do mesmo usuário em outro escritório nunca
     * entra).
     *
     * @param list<int> $usuarioIds
     *
     * @return array<int, array{ultimaAtividade: \DateTimeImmutable, status: string, statusDesde: \DateTimeImmutable}>
     */
    public function doEscritorio(Tenant $tenant, array $usuarioIds): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.usuario) AS usuario', 'p.ultimaAtividade', 'p.status', 'p.statusDesde')
            ->where('p.tenant = :tenant')
            ->andWhere('p.usuario IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $usuarioIds)
            ->getQuery()
            ->getArrayResult();

        $porUsuario = [];
        foreach ($linhas as $linha) {
            $porUsuario[(int) $linha['usuario']] = [
                'ultimaAtividade' => $linha['ultimaAtividade'],
                'status' => (string) $linha['status'],
                'statusDesde' => $linha['statusDesde'],
            ];
        }

        return $porUsuario;
    }
}
