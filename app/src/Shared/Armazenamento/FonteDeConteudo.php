<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * De onde vêm os bytes que vão ser gravados — em três formas genéricas, **nenhuma do Symfony**
 * (INV-8).
 *
 * As três existem porque as três acontecem hoje, nos 18 pontos de escrita:
 *
 *  - `deTexto`        — HTML de peça (`SalvarPecaTextoUseCase:43`) e conteúdo lido do acervo;
 *  - `deArquivoLocal` — upload já movido pela ponte HTTP, e o temporário que o sync baixa do
 *                       Drive (`ReconciliadorDePasta:428→444`);
 *  - `deStream`       — recurso já aberto, sem materializar em disco.
 *
 * ## Por que não aceitar `UploadedFile` aqui
 *
 * Quatro dos 18 pontos de escrita não têm `UploadedFile` nenhum, e `UploadedFile::move()` é
 * justamente a primitiva que não existe em backend remoto. Um contrato moldado nela obrigaria
 * todo backend futuro a emular "mover". A tradução acontece na borda HTTP, onde `isValid()`,
 * `move_uploaded_file()` e o `chmod` são preservados (INV-10, fatia E2.4).
 *
 * ## `consumirOrigem`
 *
 * Diz que o chamador **abre mão da origem**: depois de `gravar()`, ela não pode mais ser
 * usada. É o que permite ao backend local fazer `rename()` atômico em vez de reler um arquivo
 * grande em memória — o download do Drive depende disso.
 *
 * Isto é contrato, não sugestão, e a suíte o cobra (`testConsumirOrigemRemoveAOrigem`): quem
 * passa `true` não pode encontrar a origem depois. Um backend livre para copiar e deixar a
 * origem para trás faria o chamador ter de limpar — e é exatamente esse "quem apaga?" ambíguo
 * que produz órfão em disco.
 */
final class FonteDeConteudo
{
    private const TAMANHO_DO_BLOCO = 1024 * 1024;

    /** @param resource|null $stream */
    private function __construct(
        private readonly ?string $texto,
        private readonly ?string $caminhoLocal,
        private readonly mixed $stream,
        private readonly bool $consumirOrigem,
    ) {
    }

    public static function deTexto(string $conteudo): self
    {
        return new self($conteudo, null, null, false);
    }

    /**
     * @param bool $consumirOrigem permissão para MOVER o arquivo de origem em vez de copiá-lo;
     *                             quem passa true aceita que a origem deixe de existir
     */
    public static function deArquivoLocal(string $caminho, bool $consumirOrigem = false): self
    {
        if ($caminho === '') {
            throw new FalhaDeArmazenamento('Caminho de origem vazio.');
        }

        return new self(null, $caminho, null, $consumirOrigem);
    }

    /** @param resource $stream aberto para leitura; o chamador continua dono dele */
    public static function deStream(mixed $stream): self
    {
        if (!is_resource($stream)) {
            throw new FalhaDeArmazenamento('Fonte de stream exige um recurso aberto.');
        }

        return new self(null, null, $stream, false);
    }

    /**
     * Caminho local da origem, quando existe.
     *
     * É **dica de otimização** para backend que também é local — é o que deixa o
     * `ArmazenamentoLocal` fazer `rename()` atômico em vez de reler o arquivo. Backend remoto
     * ignora e usa {@see escreverEm()}. Nunca é o caminho de DESTINO.
     */
    public function caminhoLocalOuNull(): ?string
    {
        return $this->caminhoLocal;
    }

    public function consomeOrigem(): bool
    {
        return $this->consumirOrigem;
    }

    /**
     * Empurra os bytes para um stream de destino já aberto e devolve quantos foram escritos.
     *
     * É o caminho genérico, que todo backend consegue usar. Streaming em blocos de propósito:
     * nenhuma das três formas carrega arquivo grande inteiro em memória (INV-4).
     *
     * Não fecha o stream de destino — quem abriu, fecha. Também não fecha o stream de ORIGEM
     * quando a fonte foi criada com `deStream()`: aquele recurso é do chamador.
     *
     * @param resource $destino
     */
    public function escreverEm(mixed $destino): int
    {
        if (!is_resource($destino)) {
            throw new FalhaDeArmazenamento('Destino de escrita precisa ser um recurso aberto.');
        }

        if ($this->texto !== null) {
            return $this->escreverTexto($destino, $this->texto);
        }

        if ($this->caminhoLocal !== null) {
            return $this->copiarDoArquivoLocal($destino);
        }

        return $this->copiarDeStream($destino, $this->stream);
    }

    /** @param resource $destino */
    private function escreverTexto(mixed $destino, string $texto): int
    {
        $total = strlen($texto);

        // Arquivo de 0 byte é legítimo: existem 114 em produção, vindos do Drive.
        if ($total === 0) {
            return 0;
        }

        $escrito = 0;
        while ($escrito < $total) {
            $n = fwrite($destino, substr($texto, $escrito, self::TAMANHO_DO_BLOCO));
            if ($n === false || $n === 0) {
                throw new FalhaDeArmazenamento('Escrita interrompida antes do fim do conteúdo.');
            }
            $escrito += $n;
        }

        return $escrito;
    }

    /** @param resource $destino */
    private function copiarDoArquivoLocal(mixed $destino): int
    {
        $origem = @fopen((string) $this->caminhoLocal, 'rb');
        if ($origem === false) {
            throw new FalhaDeArmazenamento(
                sprintf('Não foi possível abrir a origem %s.', $this->caminhoLocal),
            );
        }

        try {
            return $this->copiarDeStream($destino, $origem);
        } finally {
            fclose($origem);
        }
    }

    /**
     * @param resource $destino
     * @param resource $origem
     */
    private function copiarDeStream(mixed $destino, mixed $origem): int
    {
        $copiado = stream_copy_to_stream($origem, $destino);
        if ($copiado === false) {
            throw new FalhaDeArmazenamento('Falha ao copiar o conteúdo para o destino.');
        }

        return $copiado;
    }
}
