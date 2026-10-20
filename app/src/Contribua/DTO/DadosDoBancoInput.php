<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/**
 * O banco escolhido e os campos extras da Integração bancária (D4/D5 da C4b). Segredo em branco mantém o
 * que já estava guardado (a tela nunca recebe o valor de volta, só "definido" ou "não definido"). A conta
 * corrente (Inter, opcional) é conferida aqui: vazia ou só números.
 */
final readonly class DadosDoBancoInput
{
    public function __construct(
        public string $banco,
        #[\SensitiveParameter]
        public string $clientSecret = '',
        public string $chavePix = '',
        #[\SensitiveParameter]
        public string $appKey = '',
        public string $urlBase = '',
        public string $urlToken = '',
        public string $contaCorrente = '',
    ) {
        if ('' !== trim($contaCorrente) && 1 !== preg_match('/^\d{1,20}$/', trim($contaCorrente))) {
            throw new \DomainException('Informe a conta corrente só com números, incluindo o dígito.');
        }
    }
}
