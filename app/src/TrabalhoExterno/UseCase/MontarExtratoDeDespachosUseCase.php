<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Service\ApresentadorDeDespachos;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PreviaDePerfil;

/**
 * "Extrato" do DESPACHO (desenho 06 de 24/10, `dpExtrato`, l.1393): a página de impressão, para todos que veem a
 * demanda. Fora do escopo de quem pede, "não encontrada" (404), como as outras leituras. Na prévia do Master
 * (`?como=`), o recorte e os nomes de quem está sendo pré-visualizado, como no detalhe.
 */
final class MontarExtratoDeDespachosUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly ApresentadorDeDespachos $despachos,
        private readonly PreviaDePerfil $previa,
    ) {
    }

    /** @return array{codigo: string, itens: list<array{titulo: string, txt: string, ass: string}>} */
    public function executar(Contexto $ctx, string $codigo): array
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);

        return ['codigo' => $demanda->getCodigo(), 'itens' => $this->despachos->extrato($ctx, $demanda)];
    }

    /** @return array{codigo: string, itens: list<array{titulo: string, txt: string, ass: string}>} */
    public function executarPrevia(Contexto $master, string $codigo, Perfil $como): array
    {
        [$demanda] = $this->localizador->localizar($master, $codigo);
        $ctx = $this->previa->paraDemanda($master, $como, $demanda) ?? throw new DemandaNaoEncontrada();

        return ['codigo' => $demanda->getCodigo(), 'itens' => $this->despachos->extrato($ctx, $demanda)];
    }
}
