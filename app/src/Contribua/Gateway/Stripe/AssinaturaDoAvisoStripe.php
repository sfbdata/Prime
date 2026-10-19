<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Stripe;

use App\Contribua\Gateway\AvisoNaoAutenticado;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Confere o cabeçalho `Stripe-Signature` de um webhook (https://docs.stripe.com/webhooks, "Verificar
 * manualmente"): `t=<carimbo>,v1=<hmac>[,v1=...]`, HMAC-SHA256 em hex de `"<t>.<corpo bruto>"` com o
 * segredo do endpoint (`whsec_...`, inteiro, como as bibliotecas oficiais). Só o esquema v1 vale (o resto é
 * ignorado contra downgrade); durante a troca do segredo chegam dois v1 e basta um bater. Carimbo a mais de
 * 5 minutos do relógio é recusado (a tolerância das bibliotecas oficiais), contra reenvio de aviso antigo.
 */
final class AssinaturaDoAvisoStripe
{
    public const TOLERANCIA_EM_SEGUNDOS = 300;

    public function __construct(
        #[Autowire('%env(string:default::CONTRIBUA_STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $segredo,
        private readonly ClockInterface $relogio,
    ) {
    }

    /** @throws AvisoNaoAutenticado */
    public function exigirAutentico(string $cabecalho, string $corpo): void
    {
        if ($this->segredo === '') {
            throw new AvisoNaoAutenticado('CONTRIBUA_STRIPE_WEBHOOK_SECRET não configurado.');
        }
        [$carimbo, $assinaturas] = self::partes($cabecalho);
        if ($carimbo === null || $assinaturas === []) {
            throw new AvisoNaoAutenticado('Aviso do Stripe sem Stripe-Signature válido.');
        }
        if (abs($this->relogio->now()->getTimestamp() - $carimbo) > self::TOLERANCIA_EM_SEGUNDOS) {
            throw new AvisoNaoAutenticado('Aviso do Stripe fora da janela de 5 minutos.');
        }
        $esperada = hash_hmac('sha256', $carimbo . '.' . $corpo, $this->segredo);
        foreach ($assinaturas as $assinatura) {
            if (hash_equals($esperada, $assinatura)) {
                return;
            }
        }

        throw new AvisoNaoAutenticado('Assinatura do aviso do Stripe não confere.');
    }

    /** @return array{0: ?int, 1: list<string>} */
    private static function partes(string $cabecalho): array
    {
        $carimbo = null;
        $assinaturas = [];
        foreach (explode(',', $cabecalho) as $item) {
            [$chave, $valor] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($chave === 't' && ctype_digit($valor)) {
                $carimbo = (int) $valor;
            }
            if ($chave === 'v1' && $valor !== '') {
                $assinaturas[] = $valor;
            }
        }

        return [$carimbo, $assinaturas];
    }
}
