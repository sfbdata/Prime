<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * O que a Cobrança já sabe da pasta judicializada (spec central-documentos-controladoria, fatia B, decisão do Samuel
 * em 31/10): a unidade cobrada e o titular (a pessoa cobrada do caso). Serve de sugestão no Cadastro da pasta; o que a
 * advogada digitar vale mais. Contrato não vem da Cobrança: não existe campo de contrato lá.
 */
final class DadosDaCobrancaNaPasta
{
    public function __construct(private readonly CasoCobrancaRepository $casos)
    {
    }

    /** @return array{unidade: ?string, titular: ?string} */
    public function da(Pasta $pasta, Tenant $tenant): array
    {
        $dados = $this->casos->dadosParaControladoriaDaPasta($pasta, $tenant);

        return ['unidade' => $dados['unidade'] ?? null, 'titular' => $dados['titular'] ?? null];
    }
}
