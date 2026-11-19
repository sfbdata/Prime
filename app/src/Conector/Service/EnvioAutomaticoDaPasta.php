<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * Diz se o cliente principal da pasta recebe o envio automático do Push (`docs/specs/mcp-remoto-oauth.md`, abertura da
 * 6.6, e 14.h): nenhum caminho do conector estende o envio. O envio sai, por pasta viva e ativa, para o cliente principal
 * ({@see Pasta::getClientePrincipal()}) que tem a linha {@see \App\Cliente\Entity\EnvioAutomaticoDoCliente} com qualquer
 * chave ligada. Quem pergunta recusa com {@see \App\Conector\Exception\ErroDaFerramenta::envioAutomatico()}, ao propor e
 * de novo ao confirmar.
 *
 * Só leitura; a linha é buscada com o escritório explícito (o conector não depende do filtro da sessão).
 */
final class EnvioAutomaticoDaPasta
{
    public function __construct(private readonly EnvioAutomaticoDoClienteRepository $envios)
    {
    }

    public function ligadoParaOPrincipal(Pasta $pasta, Tenant $escritorio): bool
    {
        $principal = $pasta->getClientePrincipal();
        if ($principal === null) {
            return false;
        }
        $linha = $this->envios->doClienteDoTenant($principal, $escritorio);
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
