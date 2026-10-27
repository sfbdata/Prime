<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;

/**
 * A página de retorno (`/contribua?assinatura=ok`, colada no gateway): o pagador voltou do pagamento. O
 * pedido aguardando mais novo do escritório passa a "pago, aguardando confirmação do gateway"; o espaço
 * só muda quando o gateway confirmar (a URL de retorno qualquer um digita, então ela não libera nada).
 *
 * Devolve o que a tela de sucesso do desenho mostra ("seu espaço passa para N GB. Próxima cobrança em
 * DD/MM/AAAA"), ou null quando não há pedido aguardando.
 */
final class RegistrarRetornoDoPagamentoUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
    ) {
    }

    /** @return array{gb: string, proxima: string}|null */
    public function executar(Solicitante $quem): ?array
    {
        foreach ($this->assinaturas->doTenant($quem->tenantId()) as $assinatura) {
            if ($assinatura->getSituacao() === SituacaoDaAssinatura::Aguardando) {
                // Só quem pode pagar (o primário) marca o retorno: a URL qualquer um digita.
                if ($quem->ehPrimario) {
                    $assinatura->marcarRetorno();
                    $this->assinaturas->salvar($assinatura, flush: true);
                }

                return ['gb' => $assinatura->getPlano()->getCotaGb() . ' GB', 'proxima' => $assinatura->getCriadaEm()->modify('+1 month')->format('d/m/Y')];
            }
            if ($assinatura->getSituacao() === SituacaoDaAssinatura::Ativa) {
                return ['gb' => $assinatura->getPlano()->getCotaGb() . ' GB', 'proxima' => $assinatura->getPagaAte()?->format('d/m/Y') ?? ''];
            }
        }

        return null;
    }
}
