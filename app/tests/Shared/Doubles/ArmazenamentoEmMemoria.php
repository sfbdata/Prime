<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use App\Shared\Armazenamento\ArmazenamentoComPrefixo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaComIsolamentoFisico;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\ResultadoDaRemocao;
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
 * Pontos em que ele diverge do `ArmazenamentoLocal`, todos fora do que o contrato promete:
 *
 *  - **MIME**: devolve sempre `application/octet-stream`; o local devolve o tipo real. O
 *    contrato não assere MIME em caso nenhum, então um teste que dependa dele está dependendo
 *    de detalhe de backend e vai mentir aqui;
 *  - **colisão de chave cunhada**: não verifica se a chave já existe; o local tenta cinco vezes
 *    (`ArmazenamentoLocal::cunharChaveLivre()`). Com 128 bits a diferença é teórica, mas quem
 *    for testar comportamento de colisão precisa do backend real;
 *  - **prefixo** (E2.5): não há diretório, então não há link, subpasta, oculto nem remoção
 *    parcial — `listar()` devolve toda chave do escopo e da categoria, e `excluirPrefixo()` nunca
 *    devolve sobra nem passa por `excluidas`. O que o disco faz de verdade é provado em
 *    `ArmazenamentoLocalPrefixoTest`; aqui se prova só o ESCOPO pedido (R1).
 */
final class ArmazenamentoEmMemoria implements ArmazenamentoDeArquivos, ArmazenamentoComPrefixo
{
    /** @var array<string, array{conteudo: string, mime: string, em: \DateTimeImmutable}> */
    private array $arquivos = [];

    /** @var array<string, ChaveDeArquivo> */
    private array $chavesPorIndice = [];

    /** @var list<string> */
    public array $chavesGravadas = [];

    /**
     * As chaves gravadas, como objeto — para afirmar escopo e categoria de quem gravou (R1).
     *
     * @var list<ChaveDeArquivo>
     */
    public array $gravadas = [];

    /**
     * Quando preenchida, `gravar()` lança esta exceção ANTES de tocar em qualquer coisa — a
     * origem fica onde estava, como no backend real quando a publicação falha. É o dublê que o
     * §11.2 pede para provar "falha de I/O na escrita não deixa linha no banco".
     */
    public ?\Throwable $falhaAoGravar = null;

    /**
     * Quando preenchidos, `gravar()` DEVOLVE estes metadados em vez dos reais (o conteúdo gravado
     * não muda). Serve para provar que o chamador persiste o que o storage mediu — e não a própria
     * conta (`strlen`, `filesize` da origem, metadado do Drive), que num teste comum coincidiria.
     */
    public ?int $tamanhoRelatado = null;
    public ?string $mimeRelatado = null;

    /**
     * As chaves efetivamente removidas por `excluir()`, na ordem (E2.5) — para afirmar QUE e
     * QUANDO um chamador apagou, inclusive em relação ao COMMIT.
     *
     * @var list<ChaveDeArquivo>
     */
    public array $excluidas = [];

    /**
     * Quando preenchida, é consultada a cada `excluir()` com a chave; se devolver uma exceção, ela
     * é lançada e o arquivo FICA. Seletiva de propósito: prova a remoção parcial em laço (a 2ª de
     * 3 falha, as outras saem).
     *
     * @var (\Closure(ChaveDeArquivo): ?\Throwable)|null
     */
    public ?\Closure $falhaAoExcluir = null;

    /**
     * Chamada a cada `excluir()` bem-sucedido, depois de remover — o gancho para o teste
     * perguntar ao banco, naquele instante, se a transação já tinha sido confirmada.
     *
     * @var (\Closure(ChaveDeArquivo): void)|null
     */
    public ?\Closure $aoExcluir = null;

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        if ($this->falhaAoGravar !== null) {
            throw $this->falhaAoGravar;
        }

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
        $this->chavesPorIndice[$this->indice($chave)] = $chave;
        $this->chavesGravadas[] = $chave->comoTexto();
        $this->gravadas[]       = $chave;

        return new ArquivoArmazenado(
            $chave,
            $this->tamanhoRelatado ?? strlen($conteudo),
            $this->mimeRelatado ?? 'application/octet-stream',
        );
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
        $falha = $this->falhaAoExcluir === null ? null : ($this->falhaAoExcluir)($chave);
        if ($falha !== null) {
            throw $falha;
        }

        $indice = $this->indice($chave);
        if (!array_key_exists($indice, $this->arquivos)) {
            return;
        }

        unset($this->arquivos[$indice]);
        $this->excluidas[] = $chave;

        if ($this->aoExcluir !== null) {
            ($this->aoExcluir)($chave);
        }
    }

    /** Põe um arquivo no armazenamento sem passar por `gravar()` — cenário de teste. */
    public function semear(ChaveDeArquivo $chave, string $conteudo = 'x'): void
    {
        $this->chavesPorIndice[$this->indice($chave)] = $chave;
        $this->arquivos[$this->indice($chave)] = [
            'conteudo' => $conteudo,
            'mime'     => 'application/octet-stream',
            'em'       => new \DateTimeImmutable(),
        ];
    }

    /**
     * Fiel ao contrato de D7: só o escopo e a categoria pedidos, e escopo global recusado — no
     * disco não existe "prefixo global", e aceitar aqui esconderia o defeito no teste.
     *
     * @return list<ChaveDeArquivo>
     */
    public function listar(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): iterable
    {
        $this->recusarEscopoGlobal($escopo);

        $chaves = [];
        foreach ($this->gravadasOuSemeadas() as $chave) {
            if ($chave->categoria === $categoria->paraCategoria() && $chave->escopo->ehIgualA($escopo)) {
                $chaves[] = $chave;
            }
        }

        return $chaves;
    }

    /** @var list<string> prefixos removidos, como "escopo/categoria" */
    public array $prefixosExcluidos = [];

    public function excluirPrefixo(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): ResultadoDaRemocao
    {
        $removidos = 0;
        foreach ($this->listar($escopo, $categoria) as $chave) {
            unset($this->arquivos[$this->indice($chave)]);
            $removidos++;
        }

        $this->prefixosExcluidos[] = $escopo->comoTexto() . '/' . $categoria->value;

        return new ResultadoDaRemocao($removidos, []);
    }

    /** @return list<ChaveDeArquivo> */
    private function gravadasOuSemeadas(): array
    {
        $chaves = [];
        foreach (array_keys($this->arquivos) as $indice) {
            $chaves[] = $this->chavesPorIndice[$indice] ?? throw new \LogicException('índice sem chave: ' . $indice);
        }

        return $chaves;
    }

    private function recusarEscopoGlobal(EscopoDeArquivo $escopo): void
    {
        if ($escopo->ehGlobal()) {
            throw new FalhaDeArmazenamento('Operação por prefixo exige escopo de escritório.');
        }
    }

    /** A última chave gravada; falha o teste se nada foi gravado. */
    public function ultimaGravada(): ChaveDeArquivo
    {
        if ($this->gravadas === []) {
            throw new \LogicException('Nada foi gravado neste armazenamento.');
        }

        return $this->gravadas[\count($this->gravadas) - 1];
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
