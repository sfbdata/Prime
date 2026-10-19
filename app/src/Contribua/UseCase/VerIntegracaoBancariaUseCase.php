<?php

declare(strict_types=1);

namespace App\Contribua\UseCase;

use App\Contribua\DTO\IntegracaoBancariaView;
use App\Contribua\Repository\IntegracaoBancariaRepository;

/** A integração bancária como o Master a vê (desenho 08, L80-101): sem o conteúdo do certificado. */
final class VerIntegracaoBancariaUseCase
{
    public function __construct(
        private readonly IntegracaoBancariaRepository $integracoes,
    ) {
    }

    public function executar(): IntegracaoBancariaView
    {
        $i = $this->integracoes->atual();

        return new IntegracaoBancariaView($i->getModalidade(), $i->getBanco(), $i->getAmbiente(), (string) $i->getClientId(), $i->temCertificado(), $i->getArquivos(), $i->getRecursos(), $i->getEstado(), $i->getResultadoDoTeste());
    }
}
