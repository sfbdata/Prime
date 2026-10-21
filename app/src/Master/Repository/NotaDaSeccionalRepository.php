<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\SeccionalOab;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Notas explicativas das seccionais da OAB (uma por UF). Sem filtro de tenant de propósito: tabela de
 * PLATAFORMA (ver {@see NotaDaSeccional}), lida e gravada só pelos UseCases do Master.
 *
 * @extends ServiceEntityRepository<NotaDaSeccional>
 */
class NotaDaSeccionalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotaDaSeccional::class);
    }

    public function daSeccional(SeccionalOab $seccional): ?NotaDaSeccional
    {
        return $this->findOneBy(['seccional' => $seccional]);
    }

    /**
     * Gravação idempotente da nota de UMA seccional: abre uma transação, trava a linha da seccional
     * (SELECT ... FOR UPDATE) e só então lê a nota e entrega à `$acao`, que cria, reescreve ou apaga (sem flush;
     * o flush e o commit são daqui). Duas gravações ao mesmo tempo na mesma UF ficam em fila na trava: a segunda
     * já enxerga a nota criada pela primeira e a reescreve (a última gravação vence), em vez de bater na
     * unicidade por seccional e virar 500. Tudo pelo ORM, então o AuditLogSubscriber continua vendo cada gravação.
     *
     * @param callable(?NotaDaSeccional): void $acao
     */
    public function gravarComTrava(SeccionalOab $seccional, callable $acao): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $seccional, $acao): void {
            $em->lock($seccional, LockMode::PESSIMISTIC_WRITE);
            $acao($this->daSeccional($seccional));
        });
    }

    /**
     * A nota de cada seccional que tem uma, com a data e hora da última gravação (ISO 8601 com o fuso; a janela mostra
     * "Nota salva em dd/mm/aaaa, hh:mm", sem autor). Uma consulta só para as 27, sem carregar entidades.
     *
     * @return array<string, array{texto: string, atualizadaEm: string}> UF => nota
     */
    public function todasPorUf(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.uf, n.texto, n.atualizada_em
               FROM master_nota_seccional n
               JOIN master_seccional_oab s ON s.id = n.seccional_id',
        );

        $porUf = [];
        foreach ($linhas as $l) {
            $porUf[(string) $l['uf']] = [
                'texto'        => (string) $l['texto'],
                'atualizadaEm' => (new \DateTimeImmutable((string) $l['atualizada_em']))->format(\DATE_ATOM),
            ];
        }

        return $porUf;
    }

    /** Sem flush: chamado dentro do {@see gravarComTrava()}, que faz o flush e o commit. */
    public function salvar(NotaDaSeccional $nota): void
    {
        $this->getEntityManager()->persist($nota);
    }

    /** Sem flush: chamado dentro do {@see gravarComTrava()}, que faz o flush e o commit. */
    public function remover(NotaDaSeccional $nota): void
    {
        $this->getEntityManager()->remove($nota);
    }
}
