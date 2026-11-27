<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\AssinaturaDeEspaco;
use App\Contribua\Gateway\SituacaoDaAssinatura;
use App\Contribua\Repository\AssinaturaDeEspacoRepository;

/**
 * A página de retorno (`/contribua?assinatura=ok`, colada no gateway): o pagador voltou do pagamento. O
 * pedido aguardando mais novo do escritório passa a "pago, aguardando confirmação do gateway"; o espaço
 * só muda quando o gateway confirmar (a URL de retorno qualquer um digita, então ela não libera nada).
 *
 * Devolve o que a tela de sucesso do desenho mostra ("seu espaço passa para N GB. Próxima cobrança em
 * DD/MM/AAAA"), ou null quando não há pedido aguardando. Devolve também o pacote, o GB, o multiplicador e o valor
 * da assinatura, para o resumo da esquerda do checkout ("+N GB", "HOJE", Subtotal e Total mensal) não abrir vazio
 * na volta do gateway (L2-M1, 27/11/2026).
 */
final class RegistrarRetornoDoPagamentoUseCase
{
    public function __construct(
        private readonly AssinaturaDeEspacoRepository $assinaturas,
    ) {
    }

    /** @return array{gb: string, proxima: string, plano: string, gbDoPlano: int, multiplicador: int, centavos: int, preco: string}|null */
    public function executar(Solicitante $quem): ?array
    {
        foreach ($this->assinaturas->doTenant($quem->tenantId()) as $assinatura) {
            if ($assinatura->getSituacao() === SituacaoDaAssinatura::Aguardando) {
                // Só quem pode pagar (o primário) marca o retorno: a URL qualquer um digita.
                if ($quem->ehPrimario) {
                    $assinatura->marcarRetorno();
                    $this->assinaturas->salvar($assinatura, flush: true);
                }

                return self::resumo($assinatura, $assinatura->getCriadaEm()->modify('+1 month')->format('d/m/Y'));
            }
            if ($assinatura->getSituacao() === SituacaoDaAssinatura::Ativa) {
                return self::resumo($assinatura, $assinatura->getPagaAte()?->format('d/m/Y') ?? '');
            }
        }

        return null;
    }

    /**
     * O que a tela de sucesso e o resumo do checkout mostram. `centavos` é o preço de uma vez (o resumo multiplica,
     * como faz ao abrir pelo botão do pacote); `preco` é o valor mensal cheio.
     *
     * @return array{gb: string, proxima: string, plano: string, gbDoPlano: int, multiplicador: int, centavos: int, preco: string}
     */
    private static function resumo(AssinaturaDeEspaco $assinatura, string $proxima): array
    {
        return [
            'gb' => $assinatura->gigabytes() . ' GB',
            'proxima' => $proxima,
            'plano' => $assinatura->getPlano()->getCodigo(),
            'gbDoPlano' => $assinatura->getPlano()->getCotaGb(),
            'multiplicador' => $assinatura->getMultiplicador(),
            'centavos' => intdiv($assinatura->getValorCentavos(), $assinatura->getMultiplicador()),
            'preco' => RegistrarApoioUseCase::reais($assinatura->getValorCentavos()),
        ];
    }
}
