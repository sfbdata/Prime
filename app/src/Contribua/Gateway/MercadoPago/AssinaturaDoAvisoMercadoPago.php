<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\MercadoPago;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Confere o cabeçalho `x-signature` de um webhook do Mercado Pago
 * (https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks):
 * `ts=<carimbo>,v1=<hmac>`, HMAC-SHA256 em hex do manifesto `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`
 * com a assinatura secreta do painel. O `data.id` vai em minúsculas e as partes ausentes saem do manifesto.
 *
 * O Mercado Pago assina o `data.id` da query da URL; o contrato só entrega cabeçalhos e corpo, então usamos o
 * `data.id` do corpo, que é o mesmo recurso. Sem janela de tempo: o Mercado Pago reenvia o mesmo aviso por
 * horas, e repetir um aviso não faz mal (o Contribua ignora o repetido e sempre reconsulta).
 */
final class AssinaturaDoAvisoMercadoPago
{
    public function __construct(
        #[Autowire('%env(string:default::CONTRIBUA_MERCADO_PAGO_WEBHOOK_SECRET)%')]
        private readonly string $segredo,
    ) {
    }

    /** @throws AvisoNaoAutenticado */
    public function exigirAutentico(string $cabecalho, string $idDaRequisicao, string $idDoRecurso): void
    {
        if ($this->segredo === '') {
            throw new AvisoNaoAutenticado('CONTRIBUA_MERCADO_PAGO_WEBHOOK_SECRET não configurado.');
        }
        [$carimbo, $assinatura] = self::partes($cabecalho);
        if ($carimbo === null || $assinatura === null) {
            throw new AvisoNaoAutenticado('Aviso do Mercado Pago sem x-signature válido.');
        }
        if (!hash_equals(hash_hmac('sha256', self::manifesto($idDoRecurso, $idDaRequisicao, $carimbo), $this->segredo), $assinatura)) {
            throw new AvisoNaoAutenticado('Assinatura do aviso do Mercado Pago não confere.');
        }
    }

    public static function manifesto(string $idDoRecurso, string $idDaRequisicao, string $carimbo): string
    {
        $manifesto = $idDoRecurso === '' ? '' : 'id:' . strtolower($idDoRecurso) . ';';
        $manifesto .= $idDaRequisicao === '' ? '' : 'request-id:' . $idDaRequisicao . ';';

        return $manifesto . 'ts:' . $carimbo . ';';
    }

    /** @return array{0: ?string, 1: ?string} */
    private static function partes(string $cabecalho): array
    {
        $carimbo = null;
        $assinatura = null;
        foreach (explode(',', $cabecalho) as $item) {
            [$chave, $valor] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($chave === 'ts' && ctype_digit($valor)) {
                $carimbo = $valor;
            }
            if ($chave === 'v1' && $valor !== '') {
                $assinatura = $valor;
            }
        }

        return [$carimbo, $assinatura];
    }
}
