<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\LixeiraDaPastaOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A lixeira de uma pasta para a UI (D7): o que está lá, quando e por quem foi excluído e de onde
 * saiu. Só leitura. Quem vê é quem pode editar a pasta (a rota checa; aqui só o tenant é
 * reconferido) — é a mesma permissão de excluir e restaurar.
 *
 * É um dos três lugares que enxergam a lixeira: a leitura inteira (as duas consultas e a montagem
 * do caminho, que sobe por `getPai()` de seções possivelmente excluídas) roda dentro do escopo da
 * `AcessoALixeira`, e o filtro volta antes de responder.
 */
final class ListarLixeiraDaPastaUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaSecaoRepository $secoes,
        private readonly AcessoALixeira $lixeira,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): LixeiraDaPastaOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        return $this->lixeira->comLixeiraVisivel(fn (): LixeiraDaPastaOutput => LixeiraDaPastaOutput::montar(
            $this->secoes->listarLixeiraDaPasta($pasta, $tenant),
            $this->documentos->listarLixeiraDaPasta($pasta, $tenant),
        ));
    }
}
