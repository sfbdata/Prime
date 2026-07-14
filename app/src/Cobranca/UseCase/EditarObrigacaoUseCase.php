<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita (corrige) uma Obrigação cadastrada errada (ajuste 5) — descrição, valor original, vencimento,
 * referência e encargos reconhecidos, com `motivo` obrigatório.
 *
 * História: o gestor cadastrou/importou uma obrigação com um dado errado e precisa corrigi-la. É uma
 * CORREÇÃO DE CADASTRO auditada, distinta de movimento operacional: o saldo continua DERIVADO
 * (CalculadoraSaldo) — mudar o valor da obrigação ajusta o saldo por derivação, nunca por coluna manual
 * (invariável 20). Guards protegem o histórico financeiro: caso encerrado (SPEC §17), obrigação ligada a
 * acordo (parcela/substituída — gerida pelo acordo), e valor exigível que cairia abaixo do já pago.
 * O único registro é o evento ObrigacaoEditada (antes/depois no payload) + a auditoria técnica da entidade.
 */
final class EditarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EditarObrigacaoInput $input, Tenant $tenant, User $usuario): Obrigacao
    {
        // Guarda multi-tenant: só uma obrigação do próprio escritório pode ser editada.
        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $input->obrigacaoId, $tenant);

        if ($obrigacao === null) {
            throw new ObrigacaoNaoEncontradaException((int) $input->obrigacaoId);
        }

        $caso = $obrigacao->getCaso();

        // Caso encerrado não aceita novos lançamentos/movimentos (SPEC §17): fim do ciclo do Caso.
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Obrigação travada por acordo VIGENTE (parcela ativa ou substituída por acordo ativo/cumprido) é
        // gerida PELO acordo — nunca por fora (invariáveis 14/15; parcelas → item 7). Acordo rompido/
        // cancelado NÃO trava: a original voltou ao saldo e a parcela virou histórico, ambas editáveis.
        if ($obrigacao->participaDeAcordoVigente()) {
            throw new ObrigacaoDeAcordoException((int) $obrigacao->getId());
        }

        // O novo valor exigível não pode cair abaixo do que já foi pago/alocado nesta obrigação.
        $novoExigivel = (int) $input->valorOriginal + $input->encargosReconhecidos;
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);
        if ($novoExigivel < $totalAlocado) {
            throw new ValorAbaixoDoAlocadoException((int) $obrigacao->getId(), $novoExigivel, $totalAlocado);
        }

        // Snapshot ANTES para o histórico/auditoria (o depois é o input já normalizado).
        $antes = $this->snapshot($obrigacao);

        $descricao = trim((string) $input->descricao);
        $referencia = $this->normalizar($input->referenciaExterna);
        $obrigacao->setDescricao($descricao);
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setEncargosReconhecidos($input->encargosReconhecidos);
        $obrigacao->setReferenciaExterna($referencia);

        $motivo = trim((string) $input->motivo);

        // A obrigação é managed: o flush do evento commita, na mesma transação, a alteração + o evento.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ObrigacaoEditada,
            $usuario,
            sprintf('Obrigação corrigida: %s', $motivo),
            [
                'obrigacaoId' => $obrigacao->getId(),
                'antes' => $antes,
                'depois' => $this->snapshot($obrigacao),
                'motivo' => $motivo,
            ],
            flush: true,
        );

        return $obrigacao;
    }

    /**
     * @return array{descricao: string, valorOriginal: int, vencimentoOriginal: string, encargosReconhecidos: int, referenciaExterna: ?string}
     */
    private function snapshot(Obrigacao $obrigacao): array
    {
        return [
            'descricao' => $obrigacao->getDescricao(),
            'valorOriginal' => $obrigacao->getValorOriginal(),
            'vencimentoOriginal' => $obrigacao->getVencimentoOriginal()->format('Y-m-d'),
            'encargosReconhecidos' => $obrigacao->getEncargosReconhecidos(),
            'referenciaExterna' => $obrigacao->getReferenciaExterna(),
        ];
    }

    private function normalizar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
