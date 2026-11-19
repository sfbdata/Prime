<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Service\Tenant\TenantContext;

/**
 * A pergunta única "mascaro CPF, CNPJ, telefone e e-mail neste texto que vai à I.A.?" (`docs/specs/mcp-remoto-oauth.md`,
 * 6.2, regra 6). Quem pergunta: as três leituras que mandam texto do escritório a um provedor
 * (`ProtecaoDoTextoDoChat`, `MontadorDeContextoDaPasta` e `MontadorDeContextoDoPush`).
 *
 *  - Dentro de uma requisição do conector de I.A.: SEMPRE mascara. O provedor é o da conta de consumo da pessoa, sem
 *    ZDR, e a preferência do escritório foi dada para o provedor que o BlueJus contrata (14.a);
 *  - fora dela (Chat I.A., BlueJus I.A., worker): a preferência do escritório, como antes; sem configuração gravada,
 *    mascara.
 *
 * "Dentro do conector" é o escritório fixado sem sessão no {@see TenantContext}: só o `PortaoDoConector` o fixa, junto
 * com o modo do conector (`PortaoDoConectorArquiteturaTest`, que também reserva o modo ao `PermissionChecker` e à
 * `RegraDosPrimarios`; por isso a pergunta não vai ao modo). Sem estado próprio: o `TenantContext` zera no `reset()`
 * entre requisições e mensagens do worker, e a requisição seguinte do mesmo processo volta a seguir a preferência. Por
 * isso esta classe não precisa de `ResetInterface`.
 */
final class PoliticaDeMascaramento
{
    public function __construct(
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
        private readonly ?TenantContext $tenantContext = null,
    ) {
    }

    public function mascarar(Tenant $tenant): bool
    {
        if ($this->tenantContext?->estaFixadoSemSessao() === true) {
            return true;
        }

        return $this->configuracoes->findDoTenant($tenant)?->isMascararDadosPessoais() ?? true;
    }
}
