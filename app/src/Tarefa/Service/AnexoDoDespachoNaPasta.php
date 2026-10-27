<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\UseCase\UploadPecaUseCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Junta o anexo do despacho aos documentos da pasta pelo mesmo caminho do upload da aba Documentos
 * (`UploadPecaUseCase`: tipo pelo conteúdo, teto por tipo, hash, páginas e autor), em "Demais documentos", sem
 * seção, com a descrição "Despacho · Meta #N" (04B L145: `origem: 'Despacho · Meta #' + número`).
 */
final class AnexoDoDespachoNaPasta implements AnexoDoDespacho
{
    public const ORIGEM = 'Despacho · Meta #%d';

    public function __construct(
        private readonly UploadPecaUseCase $upload,
    ) {
    }

    public function juntar(Tarefa $meta, UploadedFile $arquivo, User $autor, Tenant $escritorio, \Closure $aoGravar): PastaDocumento
    {
        return $this->upload->executar(
            $meta->getPasta(),
            null,
            $arquivo,
            PastaDocumento::CATEGORIA_DEMAIS,
            sprintf(self::ORIGEM, $meta->getNumero() ?? (int) $meta->getId()),
            null,
            $escritorio,
            false,
            $autor,
            $aoGravar,
        )->documento;
    }
}
