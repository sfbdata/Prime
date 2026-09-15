<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Backend em memória para testes — e a principal mitigação do risco R1.
 *
 * ## Por que ele existe, além de ser rápido
 *
 * O `ArmazenamentoLocal` **ignora o escopo** em sete das nove categorias, porque o disco atual é
 * plano nelas. Consequência: uma chave construída com o tenant ERRADO resolve para o mesmo
 * caminho da certa, o teste passa verde, e o defeito só aparece na E4, como 404 — ou, pior, como
 * vazamento entre escritórios.
 *
 * Este dublê fecha essa cegueira de propósito: a chave interna dele **inclui o escopo**. Rodar um
 * teste funcional contra ele faz o tenant errado quebrar **agora**, na fatia em que o erro foi
 * escrito, mesmo nas categorias que o disco trata como planas.
 *
 * É por isso que a suíte de contrato roda contra os DOIS backends: o local prova que o caminho
 * não mudou, este prova que o endereçamento está certo.
 *
 * ## Onde ele NÃO é fiel, e por quê
 *
 * Dois pontos em que ele diverge do `ArmazenamentoLocal`, ambos fora do que o contrato promete:
 *
 *  - **MIME**: devolve sempre `application/octet-stream`; o local devolve o tipo real. O
 *    contrato não assere MIME em caso nenhum, então um teste que dependa dele está dependendo
 *    de detalhe de backend e vai mentir aqui;
 *  - **colisão de chave cunhada**: não verifica se a chave já existe; o local tenta cinco vezes
 *    (`ArmazenamentoLocal::cunharChaveLivre()`). Com 128 bits a diferença é teórica, mas quem
 *    for testar comportamento de colisão precisa do backend real.
 */
final class ArmazenamentoEmMemoria implements ArmazenamentoDeArquivos
{
    /** @var array<string, array{conteudo: string, mime: string, em: \DateTimeImmutable}> */
    private array $arquivos = [];

    /** @var list<string> */
    public array $chavesGravadas = [];

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $chave = $destino instanceof NovoArquivo ? $destino->cunharChave() : $destino;

        $buffer = fopen('php://temp', 'w+b');
        if ($buffer === false) {
            throw new FalhaDeArmazenamento('Não foi possível abrir o buffer em memória.');
        }

        try {
            $fonte->escreverEm($buffer);
            rewind($buffer);
            $conteudo = stream_get_contents($buffer);
            if ($conteudo === false) {
                throw new FalhaDeArmazenamento('Não foi possível ler o buffer em memória.');
            }
        } finally {
            fclose($buffer);
        }

        if ($fonte->caminhoLocalOuNull() !== null && $fonte->consomeOrigem()) {
            @unlink($fonte->caminhoLocalOuNull());
        }

        $this->arquivos[$this->indice($chave)] = [
            'conteudo' => $conteudo,
            'mime'     => 'application/octet-stream',
            'em'       => new \DateTimeImmutable(),
        ];
        $this->chavesGravadas[] = $chave->comoTexto();

        return new ArquivoArmazenado($chave, strlen($conteudo), 'application/octet-stream');
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        $conteudo = $this->ler($chave);

        $recurso = fopen('php://temp', 'w+b');
        if ($recurso === false) {
            throw new FalhaDeArmazenamento('Não foi possível abrir o recurso em memória.');
        }

        fwrite($recurso, $conteudo);
        rewind($recurso);

        return $recurso;
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        $indice = $this->indice($chave);

        if (!array_key_exists($indice, $this->arquivos)) {
            throw ArquivoNaoEncontrado::para($chave);
        }

        return $this->arquivos[$indice]['conteudo'];
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return array_key_exists($this->indice($chave), $this->arquivos);
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        unset($this->arquivos[$this->indice($chave)]);
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        $indice = $this->indice($chave);

        if (!array_key_exists($indice, $this->arquivos)) {
            return null;
        }

        $registro = $this->arquivos[$indice];

        return new MetadadosDeArquivo(
            tamanhoBytes: strlen($registro['conteudo']),
            mimeType: $registro['mime'],
            atualizadoEm: $registro['em'],
            checksum: null,
        );
    }

    /**
     * O índice inclui o ESCOPO — é esta linha que torna o tenant errado visível.
     *
     * Não trocar por `$chave->nome`: seria fiel ao disco local de hoje e reproduziria exatamente
     * a cegueira que este dublê existe para eliminar.
     */
    private function indice(ChaveDeArquivo $chave): string
    {
        return $chave->comoTexto();
    }
}
