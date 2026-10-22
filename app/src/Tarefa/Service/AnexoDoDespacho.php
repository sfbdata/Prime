<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\ChaveDeArquivo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O anexo do despacho entra nos documentos da pasta da meta (spec `minhas-metas-esteira-2210.md`, C4; desenho 04B
 * L145), pela regra de chaves (skill `armazenamento-de-arquivos`), com a origem "Despacho · Meta #N".
 *
 * `juntar()` roda DENTRO da transação do despacho ({@see TransacaoDoDespacho}): grava o arquivo e o documento, e
 * entrega a chave a `$aoGravar` logo depois da gravação, antes de qualquer passo que possa falhar. Quem decide se o
 * arquivo sai é a transação, só quando o COMMIT comprovadamente não aconteceu.
 */
interface AnexoDoDespacho
{
    /**
     * @param \Closure(ChaveDeArquivo): void $aoGravar
     *
     * @throws \InvalidArgumentException tipo ou tamanho recusado (a mensagem é a que o usuário lê)
     */
    public function juntar(Tarefa $meta, UploadedFile $arquivo, User $autor, Tenant $escritorio, \Closure $aoGravar): PastaDocumento;
}
