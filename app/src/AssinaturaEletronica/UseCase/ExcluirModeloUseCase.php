<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Exception\ModeloNaoEncontrado;
use App\AssinaturaEletronica\Repository\ModeloDoEscritorioRepository;
use App\Entity\Tenant\Tenant;

/**
 * "Excluir" de um modelo do escritório, depois da confirmação da tela (D5): o modelo sai de vez, sem Lixeira. Nenhum
 * documento aponta para ele (só guardam a chave `esc-<id>` e os blocos congelados), então nada enviado muda.
 */
final readonly class ExcluirModeloUseCase
{
    public function __construct(private ModeloDoEscritorioRepository $modelos)
    {
    }

    /** @throws ModeloNaoEncontrado */
    public function executar(Tenant $tenant, int $id): void
    {
        $modelo = $this->modelos->doEscritorio($id, $tenant) ?? throw new ModeloNaoEncontrado();

        $this->modelos->remover($modelo);
    }
}
