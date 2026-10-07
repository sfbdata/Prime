<?php

declare(strict_types=1);

namespace App\Pasta\Attribute;

/**
 * Declara, na action, que a variável de rota `$argumento` é o id (`int`) da PRÓPRIA pasta — para
 * rotas fora do `PastaController` que recebem o número cru e carregam a pasta por conta própria
 * (marcadores do Expediente, meta criada a partir da pasta).
 *
 * Mesmo motivo do `#[PastaPelaFilha]`: o `PastaSomenteLeituraListener` só enxerga a pasta nos
 * argumentos já resolvidos, e um `int` passava invisível. Com este atributo o listener carrega a
 * pasta pelo id da rota, escopada ao escritório da sessão, e aplica a mesma recusa das demais.
 *
 * Por que não trocar a assinatura para `Pasta $pasta`? A action deixaria de responder o próprio
 * JSON 404 ("Pasta não encontrada.") e passaria a cair na página 404 do resolver — contrato que o
 * JS da tela consome.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PastaPorId
{
    /** @param string $argumento nome da variável da rota que traz o id da pasta */
    public function __construct(
        public readonly string $argumento,
    ) {
    }
}
