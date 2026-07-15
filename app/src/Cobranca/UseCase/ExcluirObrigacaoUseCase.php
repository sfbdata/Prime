<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoComPagamentoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Exclui (hard delete) uma Obrigação cadastrada por engano (ajuste 5), com `motivo` obrigatório.
 *
 * História: o gestor lançou uma obrigação errada (duplicidade, engano) e quer removê-la. Só se exclui
 * obrigação SEM rastro financeiro — os guards garantem isso: caso não encerrado (SPEC §17), NÃO travada
 * por acordo vigente (parcelas/substituídas são geridas pelo acordo — item 7) e SEM pagamento alocado.
 * Assim a remoção física é segura (nenhum pagamento/acordo aponta para ela). Registra o evento
 * ObrigacaoExcluida (com o snapshot do que foi apagado) ANTES de remover; o mesmo flush commita os dois.
 */
final class ExcluirObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(int $obrigacaoId, string $motivo, Tenant $tenant, User $usuario): void
    {
        // Guarda multi-tenant: só uma obrigação do próprio escritório pode ser excluída.
        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant($obrigacaoId, $tenant);

        if ($obrigacao === null) {
            throw new ObrigacaoNaoEncontradaException($obrigacaoId);
        }

        $caso = $obrigacao->getCaso();

        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Travada por acordo vigente → gerida pelo acordo (item 7), não excluível aqui.
        if ($obrigacao->participaDeAcordoVigente()) {
            throw new ObrigacaoDeAcordoException((int) $obrigacao->getId());
        }

        // Com pagamento alocado, excluir apagaria a contrapartida de um pagamento real.
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);
        if ($totalAlocado > 0) {
            throw new ObrigacaoComPagamentoException((int) $obrigacao->getId(), $totalAlocado);
        }

        $motivo = trim($motivo);

        // Evento com o SNAPSHOT do que será apagado (a timeline só mostra a descrição; o payload guarda o
        // detalhe). Registrado sem flush; o flush do `remover` commita o evento + a remoção juntos.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ObrigacaoExcluida,
            $usuario,
            sprintf('Obrigação excluída: %s', $motivo),
            [
                'obrigacaoId' => $obrigacao->getId(),
                'descricao' => $obrigacao->getDescricao(),
                'valorOriginal' => $obrigacao->getValorOriginal(),
                'encargosReconhecidos' => $obrigacao->getEncargosReconhecidos(),
                'vencimentoOriginal' => $obrigacao->getVencimentoOriginal()->format('Y-m-d'),
                'referenciaExterna' => $obrigacao->getReferenciaExterna(),
                'motivo' => $motivo,
            ],
            flush: false,
        );

        $this->obrigacaoRepository->remover($obrigacao, flush: true);
    }
}
