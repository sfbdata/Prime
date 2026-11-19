<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;

/**
 * Onde a `documentos_e_pecas` age numa chamada: a pasta (já do escritório e liberada), o escritório e a pessoa do token,
 * e as abas ocultas para ela (o contrato de honorários some quando o Financeiro está oculto). Só dados: quem monta é a
 * {@see DocumentosEPecasFerramenta}, depois de todas as guardas.
 */
final class DocumentosEPecasContexto
{
    /** @param list<string> $abasOcultas */
    public function __construct(
        public readonly Pasta $pasta,
        public readonly Tenant $escritorio,
        public readonly User $pessoa,
        public readonly array $abasOcultas,
    ) {
    }
}
