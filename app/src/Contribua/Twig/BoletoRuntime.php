<?php

declare(strict_types=1);

namespace App\Contribua\Twig;

use App\Contribua\Service\SolicitanteAtual;
use App\Contribua\UseCase\GerarBoletoDeApoioUseCase;
use App\Shared\Service\DigitoVerificador;
use Twig\Extension\RuntimeExtensionInterface;

/** Dados do parcial do boleto na seção Apoie: null quando o botão "Gerar boleto" não aparece. */
final class BoletoRuntime implements RuntimeExtensionInterface
{
    public function __construct(
        private readonly SolicitanteAtual $solicitante,
        private readonly GerarBoletoDeApoioUseCase $boleto,
    ) {
    }

    /** @return array{documento: string}|null o documento do escritório (o mesmo do checkout), para pré-preencher */
    public function boleto(): ?array
    {
        $quem = $this->solicitante->agora();
        if (null === $quem || !$this->boleto->disponivelPara($quem)) {
            return null;
        }

        return ['documento' => DigitoVerificador::digitos($quem->escritorio->getCnpj())];
    }
}
