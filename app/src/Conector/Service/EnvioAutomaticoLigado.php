<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Cliente\Entity\Cliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Tenant\Tenant;

/**
 * Diz se um cliente recebe o envio automático do Push (`docs/specs/mcp-remoto-oauth.md`, abertura da 6.6, e 14.h):
 * "cliente com envio ligado" é o que tem a linha {@see \App\Cliente\Entity\EnvioAutomaticoDoCliente} com qualquer
 * chave ligada ({@see EnvioAutomaticoDoClienteRepository::doClienteDoTenant()}). Sem linha, ou com as três chaves
 * desligadas (o padrão, {@see ChaveDoEnvioAutomatico::PADRAO}), não recebe.
 *
 * A `atualizar_cliente` pergunta pelo próprio cliente (gravar o e-mail dele, vinculá-lo a uma pasta, torná-lo
 * principal) e recusa com {@see \App\Conector\Exception\ErroDaFerramenta::envioAutomatico()}. O caso "o principal da
 * pasta tem o envio ligado" (vincular processo, reativar ou restaurar a pasta) é do
 * `EnvioAutomaticoDaPasta` (PR 10), que faz a mesma conta sobre o principal.
 *
 * Só leitura; a linha é buscada com o escritório explícito (o conector não depende do filtro da sessão).
 */
final class EnvioAutomaticoLigado
{
    public function __construct(private readonly EnvioAutomaticoDoClienteRepository $envios)
    {
    }

    public function paraOCliente(Cliente $cliente, Tenant $escritorio): bool
    {
        $linha = $this->envios->doClienteDoTenant($cliente, $escritorio);
        if ($linha === null) {
            return false;
        }
        foreach (ChaveDoEnvioAutomatico::cases() as $chave) {
            if ($linha->ligada($chave)) {
                return true;
            }
        }

        return false;
    }
}
