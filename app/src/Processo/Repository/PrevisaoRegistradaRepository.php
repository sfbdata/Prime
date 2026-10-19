<?php

declare(strict_types=1);

namespace App\Processo\Repository;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\PrevisaoRegistrada;
use App\Processo\Entity\Processo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Previsões da "Situação atual" guardadas para a taxa de acertos (spec push-painel-monitoramento §3).
 *
 * A escrita acontece na MONTAGEM da aba Push (um GET), então não pode estourar por corrida: duas
 * abas abertas ao mesmo tempo gravariam a mesma chave. Por isso INSERT com `ON CONFLICT DO NOTHING`
 * e UPDATE condicional (`conferida = false`) em SQL, sem passar pela unidade de trabalho do ORM,
 * em que uma violação de unicidade fecharia o EntityManager no meio da página.
 *
 * @extends ServiceEntityRepository<PrevisaoRegistrada>
 */
class PrevisaoRegistradaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrevisaoRegistrada::class);
    }

    /**
     * A previsão mais recente do processo, como dado simples (sem hidratar entidade).
     *
     * @return array{id: int, chave: string, previsto: string, conferida: bool}|null
     */
    public function ultimaDoProcesso(Processo $processo, Tenant $tenant): ?array
    {
        $linha = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT id, chave, previsto, conferida FROM previsao_processual
              WHERE tenant_id = :tenant AND processo_id = :processo
              ORDER BY id DESC LIMIT 1',
            ['tenant' => $tenant->getId(), 'processo' => $processo->getId()],
        );

        if ($linha === false) {
            return null;
        }

        return [
            'id'        => (int) $linha['id'],
            'chave'     => (string) $linha['chave'],
            'previsto'  => (string) $linha['previsto'],
            'conferida' => (bool) $linha['conferida'],
        ];
    }

    /** Grava a previsão desta chave; se ela já existe, não faz nada (idempotente). */
    public function registrar(Tenant $tenant, Processo $processo, string $chave, string $previsto, \DateTimeImmutable $em): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO previsao_processual (tenant_id, processo_id, chave, previsto, conferida, acertou, em)
             VALUES (:tenant, :processo, :chave, :previsto, false, NULL, :em)
             ON CONFLICT (processo_id, chave) DO NOTHING',
            [
                'tenant'   => $tenant->getId(),
                'processo' => $processo->getId(),
                'chave'    => mb_substr($chave, 0, 255),
                'previsto' => $previsto,
                'em'       => $em->format('Y-m-d H:i:s'),
            ],
        );
    }

    /** Confere a previsão uma única vez: a segunda chamada não muda nada. */
    public function conferir(int $id, Tenant $tenant, bool $acertou): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE previsao_processual SET conferida = true, acertou = :acertou
              WHERE id = :id AND tenant_id = :tenant AND conferida = false',
            ['id' => $id, 'tenant' => $tenant->getId(), 'acertou' => $acertou ? 'true' : 'false'],
        );
    }

    /** @return array{conferidas: int, acertos: int} */
    public function contarConferidas(Processo $processo, Tenant $tenant): array
    {
        $linha = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COUNT(*) AS conferidas, COUNT(*) FILTER (WHERE acertou) AS acertos
               FROM previsao_processual
              WHERE tenant_id = :tenant AND processo_id = :processo AND conferida = true',
            ['tenant' => $tenant->getId(), 'processo' => $processo->getId()],
        );

        return [
            'conferidas' => (int) ($linha['conferidas'] ?? 0),
            'acertos'    => (int) ($linha['acertos'] ?? 0),
        ];
    }
}
