<?php

declare(strict_types=1);

namespace App\Cobranca\Service;

use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * As três ações que deixam a pasta no padrão da cobrança, iguais nos dois caminhos da judicialização
 * (criar/vincular) e reusadas pela correção de pasta duplicada
 * (`CorrigirPastaJudicialDuplicadaUseCase`): nome `CREDOR - DEVEDOR`, ação `AÇÃO MONITÓRIA` e o
 * responsável como cliente principal.
 *
 * Extraído de `JudicializarCasoUseCase::normalizarPastaJudicial()` (mesmo comportamento, mesmo
 * texto das notas) para ser chamado também na correção de dados, sem duplicar a regra.
 *
 * ⚠️ Sobrescreve o que houver — inclusive uma ação diferente e um nome digitado à mão. É o
 * pedido do dono: a pasta judicializada se identifica pelo caso, não pelo que alguém digitou.
 */
final class NormalizadorDePastaJudicial
{
    public function __construct(
        private readonly ComporNomeDaPastaJudicial $comporNome,
        private readonly ResolvedorClienteDoResponsavel $resolvedorCliente,
    ) {
    }

    public function normalizar(Pasta $pasta, CasoCobranca $caso, Tenant $tenant, User $usuario): void
    {
        $nome = $this->comporNome->paraCaso($caso);

        // Só sobrescreve quando há o que compor. Sem pessoa cobrada, manter o nome que estava é
        // melhor que apagá-lo — apagar em silêncio foi o defeito que custou 3 pastas até 01/09.
        if ($nome !== null) {
            $pasta->setNomeCliente($nome);
        }

        $pasta->setNomeAcao(JudicializarCasoInput::ACAO_PADRAO);

        // Sem CPF na ficha do responsável não há identidade a cadastrar, e a pasta segue sem cliente
        // — o dado que falta é da ficha, não desta operação (spec §3.1). Nunca inventa.
        $cliente = $this->resolvedorCliente->resolver($caso->getPessoaCobradaAtual(), $tenant, $usuario);

        if ($cliente === null) {
            return;
        }

        $pasta->addCliente($cliente);
        // `addCliente` só marca o principal no PRIMEIRO vínculo. A pasta VINCULADA pode já ter
        // outros clientes, e o responsável do caso tem de ser o principal de qualquer maneira.
        $pasta->definirClientePrincipal($cliente);
    }
}
