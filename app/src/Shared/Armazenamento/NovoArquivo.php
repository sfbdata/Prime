<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * Destino de gravação de um arquivo que **ainda não tem nome** (D8).
 *
 * A distinção entre isto e {@see ChaveDeArquivo} é o coração de D8:
 *
 *  - `NovoArquivo`    → arquivo novo; **o storage cunha o nome** e o devolve em `ArquivoArmazenado`;
 *  - `ChaveDeArquivo` → arquivo existente; o nome é o do banco e é intocável.
 *
 * ## Por que o storage cunha, e não o chamador
 *
 * É o que já acontece hoje: `ArquivoStorageService::salvar()` gera
 * `bin2hex(random_bytes(16))` e **retorna** o nome (`:17`, `:27`, `:37`). Mover essa geração para
 * o chamador espalharia `random_bytes` por 18 pontos de escrita e — pior — levaria
 * `UploadedFile::guessExtension()` para dentro do núcleo, contra INV-8.
 *
 * ## A entropia é requisito, não detalhe
 *
 * O nome cunhado é opaco, com 128 bits de entropia, equivalente ao de hoje. Nada de nome derivado
 * do arquivo do usuário, sequencial ou previsível: o bucket do R2 é privado, mas a chave circula
 * em URL, log e banco, e é ela que impede adivinhar o arquivo do vizinho.
 *
 * ## A extensão: saneada aqui, e nunca recusada
 *
 * A regra de não-normalização de D8 vale para o `$nome` de uma chave **legada**, não para a
 * extensão de um arquivo que ainda vai nascer. Aqui a extensão é saneada de propósito, e o que
 * não servir vira `bin` — **nunca** vira exceção (ver {@see normalizarExtensao()}).
 *
 * Isso corrige na origem o defeito que produziu as 165 chaves terminadas em `.` do acervo — elas
 * vieram de `salvarConteudo(..., extensao: '')`, que concatenava `hash . '.' . ''`. As 165
 * continuam endereçáveis por `ChaveDeArquivo` exatamente como estão; o que muda é que arquivo
 * novo não nasce mais assim.
 */
final readonly class NovoArquivo
{
    /** 16 bytes = 128 bits, igual ao `random_bytes(16)` de hoje. Não reduzir. */
    private const BYTES_DE_ENTROPIA = 16;

    public string $extensao;

    public function __construct(
        public EscopoDeArquivo $escopo,
        public CategoriaDeArquivo $categoria,
        string $extensao = '',
    ) {
        $this->extensao = $this->normalizarExtensao($extensao);
    }

    /**
     * Cunha o nome opaco e devolve a chave definitiva.
     *
     * Chamado pelo backend dentro de `gravar()`, nunca pelo chamador de negócio — é o backend que
     * sabe se a chave já está tomada e quem precisa devolvê-la em `ArquivoArmazenado`.
     */
    public function cunharChave(): ChaveDeArquivo
    {
        $nome = bin2hex(random_bytes(self::BYTES_DE_ENTROPIA)) . '.' . $this->extensao;

        return new ChaveDeArquivo($this->escopo, $this->categoria, $nome);
    }

    /**
     * Sempre devolve uma extensão utilizável. **Nunca lança.**
     *
     * Esta função já foi escrita recusando o que não casasse com `[a-z0-9]{1,16}`, e a revisão
     * mediu o estrago contra dado real: `pathinfo(PATHINFO_EXTENSION)` sobre os 20.954
     * `nome_original` de `pasta_documento` produz **18** "extensões" que seriam recusadas —
     * `açaí - 02 junho 2025`, `pdf canvelado por atraso - refeito`, `208／2024-1`… São arquivos
     * legítimos cujo nome simplesmente não tem extensão de verdade.
     *
     * O único chamador que deriva extensão de dado do usuário é o sync do Drive
     * (`ReconciliadorDePasta.php:440`). Recusar ali transformaria "arquivo com nome esquisito" em
     * "arquivo que não entra no sistema" — num sync que já acumula backlog. Um contrato de
     * storage não tem autoridade para reprovar o arquivo de um cliente por causa do nome dele.
     *
     * Então: o que não serve como extensão vira `bin`. Nada se perde — o nome original do
     * usuário mora em coluna própria (`nome_original`), nunca na chave.
     *
     * ⚠️ Isto MUDA o que o sync gravaria hoje: `hash.açaí - 02 junho 2025` passaria a
     * `hash.bin`. Decisão ratificada (D8). Na E2.4A só o upload HTTP usa isto, e a extensão
     * dele vem de `guessExtension()`, que já produz extensão válida; o sync entra na E2.4B.
     */
    private function normalizarExtensao(string $extensao): string
    {
        // Apara só espaço e ponto. Caractere de controle NÃO entra nesta lista de propósito:
        // aparado, `"pdf\0"` viraria `pdf` em silêncio — e byte nulo numa extensão é sinal de
        // ataque ou de bug, não desleixo de formatação. Deixando-o passar pelo trim, o regex
        // abaixo o reprova e a extensão vira `bin`, que é visível e inofensivo.
        $limpa = strtolower(trim($extensao, ' .'));

        return preg_match('/^[a-z0-9]{1,16}$/', $limpa) === 1 ? $limpa : 'bin';
    }
}
