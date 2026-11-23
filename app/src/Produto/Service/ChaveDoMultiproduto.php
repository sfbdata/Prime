<?php

declare(strict_types=1);

namespace App\Produto\Service;

use App\Produto\Enum\ModoDoMultiproduto;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lê a chave `MULTIPRODUTO_MODO` (parâmetro `multiproduto.modo` do services.yaml, padrão `desligado`). Hoje governa
 * a guarda do produto e a exigência do Expediente no conector de I.A.; o menu entra no PR 7 (spec multiproduto, D3).
 *
 * Classe própria, e não o parâmetro direto em cada consumidor, para o teste trocar o modo no container
 * (`set(ChaveDoMultiproduto::class, new ChaveDoMultiproduto('bloqueio'))`), como faz com a `ChaveDasCampanhas`.
 */
final class ChaveDoMultiproduto
{
    private readonly ModoDoMultiproduto $modo;

    public function __construct(
        #[Autowire('%multiproduto.modo%')]
        string $modo,
    ) {
        $this->modo = ModoDoMultiproduto::doTexto($modo);
    }

    public function modo(): ModoDoMultiproduto
    {
        return $this->modo;
    }

    /** Fora do `desligado`: a guarda age (registrando ou bloqueando). */
    public function ligada(): bool
    {
        return ModoDoMultiproduto::Desligado !== $this->modo;
    }
}
