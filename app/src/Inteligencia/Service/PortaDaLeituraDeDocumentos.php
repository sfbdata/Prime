<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Tenant\Tenant;

/**
 * A porta do aceite na leitura de documentos da pasta (`docs/specs/mcp-remoto-oauth.md`, `ler_documentos_e_modelos`,
 * e 14.a): quem chama o {@see \App\Inteligencia\Contexto\LeitorDeDocumentosDaPasta} diz qual aceite vale. A I.A. usa a
 * {@see RegraDaLeituraDeDocumentos}, sem mudança (o aceite da BlueJus I.A. dado na versão que cita os documentos); o
 * conector usa a dele (a liberação do conector), porque o aceite da BlueJus I.A. não cobre o conector.
 *
 * O escritório chega explícito: nenhuma implementação lê sessão, Security ou Request.
 */
interface PortaDaLeituraDeDocumentos
{
    /** O aceite que vale para quem chama cobre a leitura de documentos deste escritório? */
    public function aceiteCobre(Tenant $tenant): bool;
}
