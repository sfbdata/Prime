<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\AcessoAPasta;
use Doctrine\ORM\EntityManagerInterface;

/**
 * As outras pastas dos clientes da pasta aberta, para a IA do chat cruzar (PR 4c, D12,
 * `docs/specs/ia-chat-central.md`). Roda NA SOLICITAÇÃO, depois de a pasta aberta ter sido liberada.
 *
 * Quem entra: pastas do MESMO escritório que tenham ao menos um dos clientes da pasta aberta, não excluídas, que a pessoa
 * vê pelo cadeado ({@see AcessoAPasta::restringirAoVisivel}, nunca `PastaRepository::findByCliente` cru, que não filtra
 * nada disso), da mais recente para a mais antiga (`dataAbertura DESC`). O teto é {@see LIMITE_PASTAS} contando a aberta.
 * Cada candidata passa por {@see PastaDoContextoDaIa::liberar} (financeiro e abas decididos POR pasta, nunca herdados);
 * a que tem segredo de justiça é omitida em silêncio e só CONTADA; a que a pessoa não vê nem entra na contagem (não revela que existe). Devolve ids, flags e a contagem:
 * nunca NUP, nome nem motivo.
 */
// Não é final: o teste de unidade do UseCase o dubla (a consulta mora no banco, provada no teste funcional).
class PastasDosClientesDaIa
{
    /** A pasta aberta mais as adicionais. */
    public const LIMITE_PASTAS = 5;

    /** Quantas candidatas se examinam: as omitidas por segredo não gastam vaga, mas o exame tem fim. */
    public const CANDIDATAS_MAXIMAS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly PastaDoContextoDaIa $pastaDoContexto,
    ) {
    }

    /**
     * @return array{pastas: list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>, naoConsultadas: int} só as ADICIONAIS
     */
    public function adicionais(User $eu, Tenant $tenant, int $pastaAbertaId): array
    {
        $pastas = [];
        $naoConsultadas = 0;
        foreach ($this->candidatas($eu, $tenant, $pastaAbertaId) as $candidata) {
            if (\count($pastas) >= self::LIMITE_PASTAS - 1) {
                break;
            }
            try {
                $pastas[] = $this->pastaDoContexto->liberar($eu, $tenant, (int) $candidata->getId());
            } catch (ContextoBloqueadoException) {
                ++$naoConsultadas;
            } catch (PastaDaIaNaoEncontradaException) {
                // A pessoa não vê esta pasta: some SEM entrar na contagem (contar revelaria que ela existe).
            }
        }

        return ['pastas' => $pastas, 'naoConsultadas' => $naoConsultadas];
    }

    /** @return list<Pasta> */
    private function candidatas(User $eu, Tenant $tenant, int $pastaAbertaId): array
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Pasta::class, 'p')
            ->where('p.tenant = :tenant')
            ->andWhere('p.excluidaEm IS NULL')
            ->andWhere('p.id <> :aberta')
            ->andWhere('p.id IN (SELECT p2.id FROM ' . Pasta::class . ' p2 JOIN p2.clientes c2 WHERE p2.tenant = :tenant AND c2.tenant = :tenant AND c2.id IN '
                . '(SELECT c3.id FROM ' . Pasta::class . ' p3 JOIN p3.clientes c3 WHERE p3.id = :aberta AND p3.tenant = :tenant))')
            ->setParameter('tenant', $tenant)
            ->setParameter('aberta', $pastaAbertaId)
            ->orderBy('p.dataAbertura', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(self::CANDIDATAS_MAXIMAS);
        $this->acessoAPasta->restringirAoVisivel($qb, 'p', $eu);

        /** @var list<Pasta> $candidatas */
        $candidatas = array_values($qb->getQuery()->getResult());

        return $candidatas;
    }
}
