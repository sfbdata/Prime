<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Service\ApresentadorDeDespachos;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;

/**
 * "Extrato" do DESPACHO (desenho 06 de 24/10, `dpExtrato`, l.1393): a página de impressão, para todos que veem a
 * demanda. Fora do escopo de quem pede, "não encontrada" (404), como as outras leituras.
 */
final class MontarExtratoDeDespachosUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly ApresentadorDeDespachos $despachos,
    ) {
    }

    /** @return array{codigo: string, itens: list<array{titulo: string, txt: string, ass: string}>} */
    public function executar(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        return ['codigo' => $demanda->getCodigo(), 'itens' => $this->despachos->extrato($ctx, $demanda)];
    }
}
