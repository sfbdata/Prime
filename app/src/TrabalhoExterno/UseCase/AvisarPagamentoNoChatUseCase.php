<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\ApresentadorDoPagamento;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/**
 * "Enviar no chat" da aba Pagamento (desenho 25/10, `pg.avisarChat`): grava no chat o aviso "💳 Pagamento
 * disponível: R$ x via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação {código}.
 * Veja o QR Code na aba Pagamento." (a tela abre a aba Chat). Só quem confirma o recebimento (interno), enquanto não
 * pago. O valor sai da proposta aceita, no servidor. O Convidado não lê este registro (tem o valor).
 */
final class AvisarPagamentoNoChatUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): DemandaExterna
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeConfirmarRecebimento($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode avisar o pagamento desta demanda.');
        }
        $valor = ApresentadorDoPagamento::valorDaDemanda($demanda)
            ?? throw new TransicaoInvalida('O pagamento fica disponível quando uma proposta for aceita.');

        $demanda->registrar($ctx->autor(), sprintf(
            '💳 Pagamento disponível: %s via PIX para BlueJus Gestão e Tecnologia LTDA (CNPJ 66.803.163/0001-10), identificação %s. Veja o QR Code na aba Pagamento.',
            ApresentadorDoPagamento::valorFormatado($valor),
            $demanda->getCodigo(),
        ), TipoMensagem::Pagamento);
        $this->avisos->andamentoDaDemanda($ctx, $demanda);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
