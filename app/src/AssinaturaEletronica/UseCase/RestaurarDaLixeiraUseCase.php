<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\AssinaturaEletronica\Service\TrilhaDoDocumento;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * "Restaurar" da Lixeira (desenho): volta à lista com "Restaurado da Lixeira" na trilha. O envio cancelado pela
 * exclusão não é reativado. O que não estava na Lixeira fica como está, sem evento novo (conferido com a linha travada
 * e relida).
 */
final readonly class RestaurarDaLixeiraUseCase
{
    public function __construct(
        private DocumentosDaSelecao $selecao,
        private DocumentoParaAssinarRepository $documentos,
        private TrilhaDoDocumento $trilha,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(User $ator, Tenant $tenant, array $ids): void
    {
        $documentos = $this->selecao->carregar($ids, $tenant);

        $this->documentos->emTransacao(function () use ($documentos, $tenant, $ator): void {
            $this->selecao->travar($documentos, $tenant);
            foreach ($documentos as $documento) {
                if (!$documento->estaNaLixeira()) {
                    continue;
                }

                $documento->restaurar();
                $this->trilha->acrescentar($documento, TipoDeEvento::Restaurado, [], $ator);
            }
        });
    }
}
