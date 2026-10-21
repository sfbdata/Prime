<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\DTO\EstadoDaAutomacao;
use App\Comercial\Entity\AutomacaoDeEmail;
use App\Comercial\Enum\Automacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A leitura do estado das automações (spec comercial-e-emails.md §3.4), por SQL. A alteração do Master é pela
 * entidade, auditada ({@see \App\Comercial\UseCase\AlterarAutomacaoUseCase}).
 *
 * @extends ServiceEntityRepository<AutomacaoDeEmail>
 */
class AutomacaoDeEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AutomacaoDeEmail::class);
    }

    public function estado(Automacao $automacao): EstadoDaAutomacao
    {
        $linha = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT ligada, atraso, ligada_em FROM comercial_automacao WHERE chave = :c',
            ['c' => $automacao->value],
        );

        return $linha === false
            ? new EstadoDaAutomacao($automacao, false, $automacao->atrasoPadrao(), null)
            : new EstadoDaAutomacao(
                $automacao,
                (bool) $linha['ligada'],
                $linha['atraso'] !== null ? (string) $linha['atraso'] : $automacao->atrasoPadrao(),
                $linha['ligada_em'] !== null ? new \DateTimeImmutable((string) $linha['ligada_em']) : null,
            );
    }

    /** @return list<EstadoDaAutomacao> na ordem do desenho */
    public function todas(): array
    {
        return array_map(fn (Automacao $a): EstadoDaAutomacao => $this->estado($a), Automacao::cases());
    }
}
