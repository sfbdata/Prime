<?php

declare(strict_types=1);

namespace App\Inteligencia\Repository;

use App\Inteligencia\Entity\EstadoDaQuestaoJuridica;
use App\Inteligencia\Entity\MudancaDeStatusDaQuestaoJuridica;
use App\Inteligencia\Enum\StatusDaQuestaoJuridica;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O status das questões jurídicas, por chave. PLATAFORMA, sem tenant (as questões são do BlueJus e só o Master as lê).
 *
 * @extends ServiceEntityRepository<EstadoDaQuestaoJuridica>
 */
class EstadoDaQuestaoJuridicaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EstadoDaQuestaoJuridica::class);
    }

    /** @return array<string, EstadoDaQuestaoJuridica> as linhas gravadas, pela chave */
    public function todasPorChave(): array
    {
        $porChave = [];
        foreach ($this->findAll() as $estado) {
            $porChave[$estado->getChave()] = $estado;
        }

        return $porChave;
    }

    /** @return list<string> as chaves já resolvidas (para o aviso do topo: uma consulta curta) */
    public function chavesResolvidas(): array
    {
        return array_map('strval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT chave FROM inteligencia_questao_juridica WHERE status = ?',
            [StatusDaQuestaoJuridica::Resolvida->value],
        ));
    }

    /** Para quem vai alterar: a linha da chave, criada (e marcada para persistir) se faltar. */
    public function obterParaAlterar(string $chave, \DateTimeImmutable $agora): EstadoDaQuestaoJuridica
    {
        $estado = $this->findOneBy(['chave' => $chave]);
        if ($estado === null) {
            $estado = new EstadoDaQuestaoJuridica($chave, $agora);
            $this->getEntityManager()->persist($estado);
        }

        return $estado;
    }

    /** Grava a mudança de status e a linha do histórico dela, num flush só. */
    public function salvarMudanca(EstadoDaQuestaoJuridica $estado, MudancaDeStatusDaQuestaoJuridica $mudanca): void
    {
        $this->getEntityManager()->persist($estado);
        $this->getEntityManager()->persist($mudanca);
        $this->getEntityManager()->flush();
    }
}
