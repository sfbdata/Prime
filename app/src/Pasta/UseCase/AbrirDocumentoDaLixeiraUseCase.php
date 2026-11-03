<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Abrir um arquivo que está na lixeira, antes de restaurar ou excluir de vez (pedido do Samuel,
 * 03/11/2026). Só leitura: nada muda no banco nem no arquivo.
 *
 * Quem pode é quem vê a lixeira da pasta (EDITAR a pasta; a rota checa). Aqui: o tenant da pasta é
 * reconferido, o documento tem de estar NA LIXEIRA desta pasta e deste escritório (a mesma prova de
 * posse do restaurar) e o contrato de honorários com o Financeiro oculto para quem pede não sai
 * (regra única `VisibilidadeDasAbasDaPasta`). Qualquer um desses falhando devolve NULL, sem dizer
 * se o documento existe.
 */
final class AbrirDocumentoDaLixeiraUseCase
{
    public function __construct(
        private readonly PastaDocumentoRepository $documentos,
        private readonly AcessoALixeira $lixeira,
    ) {
    }

    /** @param list<string> $abasOcultas */
    public function executar(Pasta $pasta, Tenant $tenant, int $documentoId, array $abasOcultas = []): ?PastaDocumento
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        $achados = $this->lixeira->comLixeiraVisivel(
            fn (): array => $this->documentos->findNaLixeiraDaPasta([$documentoId], $pasta, $tenant),
        );

        return VisibilidadeDasAbasDaPasta::documentosVisiveis($achados, $abasOcultas)[0] ?? null;
    }
}
