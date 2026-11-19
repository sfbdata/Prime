<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\PortaDaLeituraDeDocumentos;

/**
 * A porta do conector de I.A. na leitura de documentos da pasta (`docs/specs/mcp-remoto-oauth.md`,
 * `ler_documentos_e_modelos`, e 14.a). No lugar do aceite da BlueJus I.A. ({@see
 * \App\Inteligencia\Service\RegraDaLeituraDeDocumentos}), vale a liberação do conector nos dois níveis ({@see
 * LiberacaoDoConector}), que o portão já conferiu na requisição e que aqui se confere de novo, porque a liberação pode
 * mudar no meio.
 *
 * Quem chama passa esta porta como parâmetro de
 * {@see \App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta::anexos()}; ninguém a recebe pela interface no construtor.
 */
final class PortaDoConectorNaLeituraDeDocumentos implements PortaDaLeituraDeDocumentos
{
    public function __construct(private readonly LiberacaoDoConector $liberacao)
    {
    }

    public function aceiteCobre(Tenant $tenant): bool
    {
        try {
            $this->liberacao->exigir($tenant);
        } catch (AcessoDoConectorRecusado) {
            return false;
        }

        return true;
    }
}
