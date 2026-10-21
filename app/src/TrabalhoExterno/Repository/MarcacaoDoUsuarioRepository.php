<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Repository;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Marcações de UM usuário. Toda consulta recebe o usuário: não há leitura de marcação alheia. Os
 * ids de item já vêm escopados por quem chama (o `LocalizadorDeDemanda`, para demandas).
 *
 * @extends ServiceEntityRepository<MarcacaoDoUsuario>
 */
class MarcacaoDoUsuarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarcacaoDoUsuario::class);
    }

    public function salvar(MarcacaoDoUsuario $marcacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($marcacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @param list<int> $itens
     *
     * @return array<int, MarcacaoDoUsuario> id do item → marcação (ausente = nada marcado)
     */
    public function doUsuario(User $usuario, TipoItemMarcado $tipo, array $itens): array
    {
        if ($itens === []) {
            return [];
        }

        $marcacoes = $this->createQueryBuilder('m')
            ->andWhere('m.usuario = :usuario')
            ->andWhere('m.tipo = :tipo')
            ->andWhere('m.itemId IN (:itens)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tipo', $tipo)
            ->setParameter('itens', $itens)
            ->getQuery()
            ->getResult();

        $mapa = [];
        foreach ($marcacoes as $m) {
            $mapa[$m->getItemId()] = $m;
        }

        return $mapa;
    }

    /** Grava as marcações de um lote de uma vez (um flush). @param list<MarcacaoDoUsuario> $marcacoes */
    public function salvarTodas(array $marcacoes): void
    {
        foreach ($marcacoes as $m) {
            $this->getEntityManager()->persist($m);
        }
        $this->getEntityManager()->flush();
    }

    /** Quem remove o item chama isto: `item_id` não tem FK (tabela polimórfica, spec §2). */
    public function removerDoItem(TipoItemMarcado $tipo, int $itemId): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.tipo = :tipo')
            ->andWhere('m.itemId = :item')
            ->setParameter('tipo', $tipo)
            ->setParameter('item', $itemId)
            ->getQuery()
            ->execute();
    }

    /** Como {@see removerDoItem()}, para vários itens do mesmo tipo (as candidaturas da vaga ou do currículo removido). @param list<int> $itens */
    public function removerDosItens(TipoItemMarcado $tipo, array $itens): void
    {
        if ($itens === []) {
            return;
        }
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.tipo = :tipo')
            ->andWhere('m.itemId IN (:itens)')
            ->setParameter('tipo', $tipo)
            ->setParameter('itens', $itens)
            ->getQuery()
            ->execute();
    }
}
