<?php

declare(strict_types=1);

namespace App\Djen\Service;

use App\Entity\Tenant\Tenant;

/**
 * O que acontece com as publicações NOVAS ao fim de cada captação, depois da reconciliação (hoje: o
 * "Avisar quando chegar" do Push, spec push-avisar-quando-chegar §3). Interface para a captação não
 * depender de quem avisa (o domínio Pasta) e ser testável por abstração.
 *
 * Contrato: nunca lança. Falha de quem avisa é registrada por ele e não derruba a captação, que já
 * gravou as publicações.
 */
interface AvisoDePublicacoesNovasInterface
{
    /** @param list<string> $djenIdsNovos as publicações que a captação acabou de criar */
    public function avisar(Tenant $tenant, array $djenIdsNovos): void;
}
