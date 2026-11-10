<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Tenant\Tenant;

/**
 * O repositório de verdade, com um gancho logo antes da trava: o teste grava ali o que "a outra aba" gravou entre a
 * leitura e a trava, ou faz a trava não achar o documento. Sem gancho, igual ao de verdade.
 */
final class DocumentosComTravaEspiada extends DocumentoParaAssinarRepository
{
    /** @var (\Closure(DocumentoParaAssinar): bool)|null roda antes da trava; falso faz a trava não achar o documento */
    public ?\Closure $antesDaTrava = null;

    public int $travas = 0;

    public function travarERecarregar(DocumentoParaAssinar $documento, Tenant $tenant): bool
    {
        ++$this->travas;
        if ($this->antesDaTrava !== null && !($this->antesDaTrava)($documento)) {
            return false;
        }

        return parent::travarERecarregar($documento, $tenant);
    }
}
