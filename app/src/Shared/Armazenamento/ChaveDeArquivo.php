<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Endereço de um arquivo que JÁ existe: escopo + categoria + o nome **exatamente como está no
 * banco**.
 *
 * ## A regra que manda aqui (D8): recusar, nunca corrigir
 *
 * O `$nome` é byte a byte o valor da coluna (`pasta_documento.caminho_arquivo`,
 * `justificativa_ponto.anexo_path`, `kanban_anexo.caminho`, …). O construtor **nunca**:
 *
 *  - aplica `trim()` nem mexe em espaço algum;
 *  - normaliza Unicode (NFC/NFD);
 *  - remove ponto final;
 *  - sanitiza, escapa ou "arruma" o que quer que seja;
 *  - exige formato de hash.
 *
 * Isso não é purismo — é o acervo real. Medido em `saas_ux`: **165 chaves terminam em `.`**
 * (hash gravado com extensão vazia) e **3 têm espaço nas bordas**. Um `trim()` "inofensivo"
 * tornaria esses 3 arquivos inalcançáveis para sempre, e um "tira o ponto sobrando", 165. A E0
 * contou 184 chaves fora do padrão em produção. Normalizá-las é trabalho do backfill da E3, com
 * migração de arquivo junto — não de um construtor.
 *
 * O que o construtor faz é **recusar** os cinco casos que quebrariam a fronteira de storage: nome
 * vazio, com `/`, com `\`, com `..`, com byte nulo ou com caractere de controle. Recusar é seguro
 * porque nenhum deles existe no acervo (medido: zero) — então nada legítimo é barrado.
 *
 * ## Por que a guarda vive aqui, e não na rota
 *
 * `ServirFotoControllerTest` documenta que o roteador do Symfony normaliza `../..` **antes** do
 * controller: um teste funcional de travessia passa verde com ou sem guarda, provando outra
 * barreira. A única forma de provar esta é chamando o construtor com o valor malicioso na mão —
 * que é o que `ChaveDeArquivoTest` faz.
 *
 * ## O que ela NÃO é
 *
 * Não é caminho. Não sabe onde o arquivo mora, e é exatamente por isso que existe: quem traduz
 * chave em caminho é o adapter do backend (`ResolvedorDeCaminhoLocal` hoje, outro na E4).
 *
 * Para gravar arquivo NOVO não se usa esta classe e sim {@see NovoArquivo}: lá o nome ainda não
 * existe e quem o cunha é o storage.
 */
final readonly class ChaveDeArquivo
{
    public function __construct(
        public EscopoDeArquivo $escopo,
        public CategoriaDeArquivo $categoria,
        public string $nome,
    ) {
        $this->recusarNomeImpossivel($nome);
    }

    /**
     * Recusa — nunca corrige. Cada caso tem motivo próprio:
     *
     *  - vazio: endereçaria o próprio diretório;
     *  - `/` e `\`: a chave viraria caminho, e um nome do banco passaria a escolher diretório;
     *  - `..`: travessia, mesmo depois de qualquer concatenação;
     *  - byte nulo: trunca a string em chamadas de sistema (clássico poison null byte);
     *  - caractere de controle: não aparece em nome legítimo e envenena log e cabeçalho HTTP.
     *
     * `.` sozinho também sai: é referência a diretório, não nome de arquivo. Atenção: isso é
     * diferente de nome que TERMINA em `.`, que é legítimo e existe 165 vezes em produção.
     */
    private function recusarNomeImpossivel(string $nome): void
    {
        if ($nome === '') {
            throw new ChaveDeArquivoInvalida('Nome de arquivo vazio.');
        }

        if ($nome === '.' || $nome === '..') {
            throw new ChaveDeArquivoInvalida(
                sprintf('Nome de arquivo "%s" é referência a diretório.', $nome),
            );
        }

        if (str_contains($nome, '/') || str_contains($nome, '\\')) {
            throw new ChaveDeArquivoInvalida(
                sprintf('Nome de arquivo não pode conter separador de caminho: %s', $this->paraMensagem($nome)),
            );
        }

        if (str_contains($nome, '..')) {
            throw new ChaveDeArquivoInvalida(
                sprintf('Nome de arquivo não pode conter "..": %s', $this->paraMensagem($nome)),
            );
        }

        // Sobre bytes, sem /u: nome legado pode não ser UTF-8 válido, e aqui só interessam
        // controles ASCII. Byte de continuação UTF-8 (\x80-\xBF) não cai nesta faixa.
        if (preg_match('/[\x00-\x1F\x7F]/', $nome) === 1) {
            throw new ChaveDeArquivoInvalida(
                sprintf('Nome de arquivo contém caractere de controle: %s', $this->paraMensagem($nome)),
            );
        }
    }

    public function ehIgualA(self $outra): bool
    {
        return $this->nome === $outra->nome
            && $this->categoria === $outra->categoria
            && $this->escopo->ehIgualA($outra->escopo);
    }

    /**
     * Forma estável para log, mensagem de erro e chave de mapa em memória.
     *
     * **Não é formato de armazenamento** e nenhum backend deve derivar caminho disto — o layout
     * físico é do adapter.
     */
    public function comoTexto(): string
    {
        return sprintf('%s/%s/%s', $this->escopo->comoTexto(), $this->categoria->value, $this->nome);
    }

    /** Torna visível em mensagem de erro um nome com caractere invisível, sem alterar a chave. */
    private function paraMensagem(string $nome): string
    {
        return '"' . addcslashes($nome, "\0..\37\177\\") . '"';
    }
}
