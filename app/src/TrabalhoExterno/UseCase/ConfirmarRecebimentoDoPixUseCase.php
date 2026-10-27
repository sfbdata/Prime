<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

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
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        // Quem não é da Central leva 403 antes de saber em que estado a demanda está.
        if (!$ctx->ehInterno()) {
            throw new AcaoNaoPermitida('Você não pode confirmar o recebimento desta demanda.');
        }

        return $this->repositorio->comADemandaTravada($demanda, function () use ($ctx, $demanda): DemandaExterna {
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

            $this->registro->registrar($demanda, $ctx->autor(), ApresentadorDoPagamento::valorFormatado($valor), $ctx);

            return $demanda;
        });
    }
}
