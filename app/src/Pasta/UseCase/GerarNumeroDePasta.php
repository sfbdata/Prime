<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Service\NumeracaoDePastaInterface;

/**
 * Devolve o próximo número livre de pasta do escritório (spec fase2 §12.5/D12.1).
 *
 * Existe porque a escolha manual do número colidia: até 2026-08 o número vinha digitado
 * pelo usuário e o `CriarPastaUseCase` não checava duplicidade (a trava UNIQUE de nup foi
 * removida de propósito na Version20260701144054, para o sync aceitar 10A/10B). Duas pessoas
 * criando a mesma pasta ao mesmo tempo geravam duas pastas — e o sync refletia as duas no
 * Drive. Em produção isso aconteceu 3 vezes (nups 1214, 1221 e 1227).
 *
 * Regra: MAX(prefixo numérico) + 1, POR TENANT. Buracos NÃO são preenchidos — reaproveitar o
 * número de uma pasta apagada confunde o Drive e o histórico. No tenant 1, com 1047 prefixos
 * distintos e maior = 1231, o próximo é 1232 (e não um dos ~184 buracos).
 *
 * O sufixo de letra do legado (10A/10B) é tolerado na leitura — conta como prefixo 10 — mas
 * nunca é gerado.
 *
 * A trava e a leitura do maior número moram no `NumeracaoDePasta`, compartilhado com o
 * `ExcluirPastaUseCase`: os dois precisam concordar sobre o que é o número de uma pasta.
 * O chamador continua obrigado a abrir transação — é o que dá validade à trava (é o que o
 * `CriarPastaUseCase` faz com `wrapInTransaction`).
 */
final class GerarNumeroDePasta
{
    public function __construct(
        private readonly NumeracaoDePastaInterface $numeracao,
    ) {
    }

    public function executar(Tenant $tenant): string
    {
        $this->numeracao->travar($tenant);

        return (string) ($this->numeracao->maiorNumero($tenant) + 1);
    }
}
