<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\UseCase\CancelarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\ConsultarCobrancaDaOrigemUseCase;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\AcaoDemanda;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\RegistrarPagamentoDaDemandaService;

/**
 * "Confirmar recebimento" da aba Pagamento (desenho 25/10, `pg.confirmar`): a ação `RegistrarPagamento` do fluxo
 * (pagoEm, marco `pago` com "PIX R$ x", aviso de WhatsApp da fase Pago, aviso de andamento) com o registro do desenho
 * "✅ Pagamento de R$ x confirmado via PIX. A execução pode começar." no lugar do genérico. O valor sai SEMPRE da
 * proposta aceita, no servidor: o cliente não informa nada.
 * A demanda é travada (`FOR UPDATE`) e relida antes de conferir `pagoEm`: duas confirmações simultâneas não passam as duas.
 * Quem: Master, ou Equipe com "Alterar a situação" (a mesma guarda do botão "Registrar pagamento"; nunca na prévia).
 * Alternativos: quem não é da Central → AcaoNaoPermitida (403) sem revelar o estado; para a Central, estado antes de
 * permissão, como em {@see AplicarAcaoNaDemandaUseCase}: fora das situações do
 * pagamento ou já pago (a segunda chamada não duplica nada) → TransicaoInvalida (409); sem proposta aceita → 409;
 * perfil sem poder → AcaoNaoPermitida (403); outro escritório → 404.
 */
final class ConfirmarRecebimentoDoPixUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
        private readonly RegistrarPagamentoDaDemandaService $registro,
        private readonly ConsultarCobrancaDaOrigemUseCase $cobrancas,
        private readonly CancelarCobrancaDaOrigemUseCase $cancelarCobranca,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, ?string $motivo = null): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        // Quem não é da Central leva 403 antes de saber em que estado a demanda está.
        if (!$ctx->ehInterno()) {
            throw new AcaoNaoPermitida('Você não pode confirmar o recebimento desta demanda.');
        }

        return $this->repositorio->comADemandaTravada($demanda, function () use ($ctx, $demanda, $motivo): DemandaExterna {
            if (!in_array($demanda->getSituacao(), AcaoDemanda::RegistrarPagamento->origens(), true)) {
                throw new TransicaoInvalida(sprintf('A demanda está %s: a ação não se aplica mais. Recarregue a tela.', mb_strtolower($demanda->getSituacao()->rotulo())));
            }
            if ($demanda->getPagoEm() !== null) {
                throw new TransicaoInvalida('O pagamento desta demanda já foi registrado.');
            }
            if (!$this->politica->podeConfirmarRecebimento($ctx, $demanda)) {
                throw new AcaoNaoPermitida('Você não pode confirmar o recebimento desta demanda.');
            }
            $valor = ApresentadorDoPagamento::valorDaDemanda($demanda)
                ?? throw new TransicaoInvalida('O pagamento fica disponível quando uma proposta for aceita.');

            $this->resolverCobrancaViva($ctx, $demanda, trim((string) $motivo));
            $this->registro->registrar($demanda, $ctx->autor(), ApresentadorDoPagamento::valorFormatado($valor), $ctx);

            return $demanda;
        });
    }

    /**
     * Cobrança PIX no registro de pagamentos (gateway M1, §4.4): ao confirmar à mão a cobrança aguardando é SEMPRE cancelada
     * (com ou sem QR, vencida ou não), para um PIX tardio cair em `divergente` em vez de pagar duas vezes sem ninguém ver.
     * Com um QR ainda valendo o motivo é obrigatório (409 sem ele, decisão do dono). A conferência de `viva()` lê a cobrança antes da trava (a demanda já está travada, mas o pagamento não); só o cancelamento
     * trava o pagamento e relê o estado. Se o banco confirmar nesse intervalo, o cancelamento não faz nada e a demanda é
     * registrada como paga à mão, com o pagamento do banco já `paga` (o registro automático é no-op pelo `pagoEm`).
     */
    private function resolverCobrancaViva(Contexto $ctx, DemandaExterna $demanda, string $motivo): void
    {
        $tenantId = (int) $demanda->getTenant()->getId();
        $ultima = $this->cobrancas->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, (int) $demanda->getId());
        if ($ultima === null || $ultima->status !== StatusDoPagamento::Aguardando) {
            return;
        }
        if ($motivo === '' && $ultima->viva(new \DateTimeImmutable())) {
            throw new TransicaoInvalida('Há um PIX dinâmico aguardando o banco. Informe o motivo para confirmar o recebimento à mão.');
        }
        $this->cancelarCobranca->executar($tenantId, OrigemDoPagamento::TrabalhoExterno, (int) $demanda->getId(), mb_substr(sprintf('Confirmado à mão por %s%s', $ctx->autor()->nome, $motivo !== '' ? ': ' . $motivo : ''), 0, 500));
    }
}
