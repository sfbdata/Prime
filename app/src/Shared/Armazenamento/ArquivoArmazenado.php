<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * O que `gravar()` devolve: a chave definitiva mais os metadados medidos **depois** da escrita.
 *
 * Devolver tamanho e MIME daqui não é conveniência — é a correção de uma classe de bug já
 * materializada em produção. Hoje `salvar(UploadedFile)` chama `UploadedFile::move()`, que
 * invalida o objeto; quem tentava ler `getSize()` depois tomava "stat failed" e o upload de anexo
 * do Kanban terminava em 500 (ver `AdicionarAnexoUseCase.php:26-33`). Com o tamanho vindo no
 * retorno, não sobra motivo para o chamador tocar na origem depois de gravar.
 *
 * `chave` é a chave FINAL: quando o destino era um {@see NovoArquivo}, é aqui que o nome cunhado
 * pelo storage aparece.
 */
final readonly class ArquivoArmazenado
{
    public function __construct(
        public ChaveDeArquivo $chave,
        public int $tamanhoBytes,
        public string $mimeType,
    ) {
    }
}
