<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Exception\JustificativaJaAnalisadaException;
use App\Ponto\Repository\JustificativaPontoRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava o que o colaborador editou na própria justificativa — só se ela ainda estiver pendente.
 *
 * Quem: o colaborador, pela rota de edição, sem trocar o atestado (a troca tem UseCase próprio).
 * Para quê: corrigir tipo, abono parcial ou o horário do esquecimento ANTES de o gestor analisar.
 * Pré-condição: a justificativa é do escritório informado e está `pendente` no banco no instante em
 * que se grava.
 * Recusas: já analisada → `JustificativaJaAnalisadaException`, nada gravado; outro escritório ou
 * registro que sumiu → `LogicException` (premissa quebrada).
 * Depois: os campos editados estão gravados e o status continua `pendente` — a edição nunca decide.
 *
 * ## Por que sob trava
 *
 * A rota já recusa o que chega analisado (recusa rápida, pelo estado carregado). Isso não basta: a
 * análise do admin (`TenantController`) comita a qualquer momento e não pega trava nenhuma. Se o admin
 * aprovasse entre o carregamento da justificativa e o flush, o colaborador gravaria tipo ou horário
 * novos numa justificativa já abonada — exatamente o C2-01. Aqui o status é lido do banco com a linha
 * travada, na mesma transação do flush: um UPDATE do admin espera este COMMIT, e um que já comitou é
 * visto. Ver `docs/specs/ponto-edicao-justificativa-analisada.md`, R2.
 */
final class ConfirmarEdicaoDeJustificativaUseCase
{
    /** O único status em que o colaborador ainda pode mexer na justificativa: nada foi analisado. */
    private const STATUS_EDITAVEL = 'pendente';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JustificativaPontoRepository $repositorio,
    ) {
    }

    /**
     * @throws JustificativaJaAnalisadaException quando, sob a trava, a justificativa já não está pendente
     */
    public function executar(JustificativaPonto $justificativa, Tenant $tenant): void
    {
        // Posse do tenant é PRÉ-CONDIÇÃO: a leitura travada filtra pelo escritório informado, e um
        // descasamento a faria não achar o registro. Hoje a porta HTTP é fechada pelo TenantFilter,
        // mas esta classe decide o que vai para a folha e não pode depender só disso.
        $dono = $justificativa->getTenant();

        if ($dono === null || $dono->getId() !== $tenant->getId()) {
            throw new \LogicException('A justificativa não pertence ao escritório informado; edição recusada.');
        }

        // `wrapInTransaction`: trabalho, flush, COMMIT; na falha, ROLLBACK, EntityManager fechado e a
        // exceção sobe — o que a rota mudou na entidade não chega ao banco.
        $this->em->wrapInTransaction(function () use ($justificativa, $tenant): void {
            $status = $this->repositorio->statusNoBancoTravadoPorId((int) $justificativa->getId(), $tenant);

            // Sumir sob a trava é premissa quebrada (a posse já foi conferida), nunca "nada a recusar".
            if ($status === null) {
                throw new \LogicException('Justificativa não encontrada sob a trava; edição recusada.');
            }

            if ($status !== self::STATUS_EDITAVEL) {
                throw new JustificativaJaAnalisadaException();
            }
        });
    }
}
