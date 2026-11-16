<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Service\ModelosDePautas;

/**
 * "Modelos prontos" do passo Cargos e pautas (desenho 18, L262): cria, no fim da ordem, as pautas do modelo escolhido pelo
 * mesmo caminho de "adicionar pauta" (AdicionarPautaUseCase: escritório, rascunho, validação das opções e abstenção
 * automática). Devolve os títulos para o aviso "Modelo aplicado: ...". Modelo desconhecido é recusado antes de gravar.
 */
final class AplicarModeloDePautasUseCase
{
    public function __construct(private readonly AdicionarPautaUseCase $adicionar)
    {
    }

    /** @return list<string> os títulos das pautas criadas */
    public function executar(Tenant $tenant, int $assembleiaId, string $modelo): array
    {
        $titulos = [];
        foreach (ModelosDePautas::pautasDoModelo($modelo) as $input) {
            $this->adicionar->executar($tenant, $assembleiaId, $input);
            $titulos[] = $input->titulo;
        }

        return $titulos;
    }
}
