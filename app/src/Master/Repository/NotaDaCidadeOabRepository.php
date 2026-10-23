<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\SeccionalOab;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Notas explicativas das cidades (uma por seccional e cidade). Sem filtro de tenant de propósito: tabela de
 * PLATAFORMA (ver {@see NotaDaCidadeOab}), lida e gravada só pelos UseCases do Master.
 *
 * @extends ServiceEntityRepository<NotaDaCidadeOab>
 */
class NotaDaCidadeOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotaDaCidadeOab::class);
    }

    public function daCidade(SeccionalOab $seccional, string $cidade): ?NotaDaCidadeOab
    {
        return $this->findOneBy(['seccional' => $seccional, 'cidade' => $cidade]);
    }

    /**
     * Mesma trava da {@see NotaDaSeccionalRepository::gravarComTrava()}: a linha da seccional fica travada
     * (SELECT ... FOR UPDATE) antes de ler a nota da cidade, então duas gravações ao mesmo tempo ficam em fila e a
     * última vence, em vez de bater na unicidade (seccional, cidade) e virar 500.
     *
     * @param callable(?NotaDaCidadeOab): void $acao
     */
    public function gravarComTrava(SeccionalOab $seccional, string $cidade, callable $acao): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($em, $seccional, $cidade, $acao): void {
            $em->lock($seccional, LockMode::PESSIMISTIC_WRITE);
            $acao($this->daCidade($seccional, $cidade));
        });
    }

    /**
     * As notas de cidade, por UF e cidade, com a data e hora da última gravação (ISO 8601 com o fuso).
     *
     * @return array<string, array<string, array{texto: string, atualizadaEm: string}>> UF => cidade => nota
     */
    public function todasPorUf(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT s.uf, n.cidade, n.texto, n.atualizada_em
               FROM master_nota_cidade_oab n
               JOIN master_seccional_oab s ON s.id = n.seccional_id
           ORDER BY s.uf, n.cidade',
        );

        $porUf = [];
        foreach ($linhas as $l) {
            $porUf[(string) $l['uf']][(string) $l['cidade']] = [
                'texto'        => (string) $l['texto'],
                'atualizadaEm' => (new \DateTimeImmutable((string) $l['atualizada_em']))->format(\DATE_ATOM),
            ];
        }

        return $porUf;
    }

    /** Sem flush: chamado dentro do {@see gravarComTrava()}, que faz o flush e o commit. */
    public function salvar(NotaDaCidadeOab $nota): void
    {
        $this->getEntityManager()->persist($nota);
    }

    /** Sem flush: chamado dentro do {@see gravarComTrava()}, que faz o flush e o commit. */
    public function remover(NotaDaCidadeOab $nota): void
    {
        $this->getEntityManager()->remove($nota);
    }
}
