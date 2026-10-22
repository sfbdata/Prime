<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O anexo do despacho entra nos documentos da pasta da meta (spec `minhas-metas-esteira-2210.md`, C4; desenho 04B
 * L145), pela regra de chaves (skill `armazenamento-de-arquivos`), com a origem "Despacho · Meta #N".
 *
 * `juntar()` roda DENTRO da transação do despacho: grava o arquivo e o documento. Se a transação voltar depois,
 * quem chama passa o documento a `descartar()`, que tira o arquivo do storage (o registro já voltou com o ROLLBACK).
 */
interface AnexoDoDespacho
{
    /** @throws \InvalidArgumentException tipo ou tamanho recusado (a mensagem é a que o usuário lê) */
    public function juntar(Tarefa $meta, UploadedFile $arquivo, User $autor, Tenant $escritorio): PastaDocumento;

    public function descartar(PastaDocumento $documento): void;
}
