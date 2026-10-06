<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A montagem do JSON do explorador, sem kernel: contagens recursivas em memória (inclusive com
 * CICLO gravado no banco — o desfazer da auditoria grava `pai` direto pelo setter, sem os guards
 * dos UseCases), seção/pai de cada item, URLs e tokens vindos dos geradores injetados.
 */
#[CoversClass(ExploradorDeDocumentosOutput::class)]
final class ExploradorDeDocumentosOutputTest extends TestCase
{
    private const ROTULOS = ['DEMAIS' => 'Demais documentos', 'PROCURACAO' => 'Procuração'];

    private function secao(int $id, string $nome, ?PastaSecao $pai = null, int $ordem = 1): PastaSecao
    {
        $s = new PastaSecao();
        (new \ReflectionProperty(PastaSecao::class, 'id'))->setValue($s, $id);
        $s->setNome($nome);
        $s->setOrdem($ordem);
        if ($pai !== null) {
            $s->setPai($pai);
        }

        return $s;
    }

    private function documento(int $id, string $nome, ?PastaSecao $secao, int $ordem = 0): PastaDocumento
    {
        $d = new PastaDocumento();
        (new \ReflectionProperty(PastaDocumento::class, 'id'))->setValue($d, $id);
        $d->setTitulo($nome);
        $d->setNomeOriginal($nome);
        $d->setCategoria('DEMAIS');
        $d->setCaminhoArquivo('x/' . $nome);
        $d->setMimeType('application/pdf');
        $d->setTamanhoBytes(10);
        $d->setPasta(new Pasta());
        $d->setSecao($secao);
        $d->setOrdem($ordem);

        return $d;
    }

    /** @param PastaSecao[] $secoes @param PastaDocumento[] $docs */
    private function montar(array $secoes, array $docs): ExploradorDeDocumentosOutput
    {
        return ExploradorDeDocumentosOutput::montar(
            $secoes,
            $docs,
            self::ROTULOS,
            static fn (string $rota, array $params): string => '/' . $rota . '/' . implode(',', $params),
            static fn (string $id): string => 'tok_' . $id,
        );
    }

    /** @return array<string, mixed> */
    private function pasta(ExploradorDeDocumentosOutput $out, int $id): array
    {
        foreach ($out->pastas as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        self::fail("pasta #{$id} ausente");
    }

    #[TestDox('árvore normal: subpastas DESCENDENTES e arquivos da própria + descendência; o da raiz não entra em pasta nenhuma')]
    public function testContagemRecursivaNormal(): void
    {
        $pai   = $this->secao(1, 'PAI');
        $filha = $this->secao(2, 'FILHA', $pai);
        $neta  = $this->secao(3, 'NETA', $filha);

        $out = $this->montar([$pai, $filha, $neta], [
            $this->documento(10, 'pai.pdf', $pai),
            $this->documento(11, 'filha.pdf', $filha),
            $this->documento(12, 'neta.pdf', $neta),
            $this->documento(13, 'raiz.pdf', null),
        ]);

        self::assertSame(['subpastas' => 2, 'arquivos' => 3], [
            'subpastas' => $this->pasta($out, 1)['subpastas'], 'arquivos' => $this->pasta($out, 1)['arquivos'],
        ]);
        self::assertSame(1, $this->pasta($out, 2)['subpastas']);
        self::assertSame(2, $this->pasta($out, 2)['arquivos']);
        self::assertSame(0, $this->pasta($out, 3)['subpastas']);
        self::assertSame(1, $this->pasta($out, 3)['arquivos']);

        self::assertNull($this->pasta($out, 1)['paiId']);
        self::assertSame(1, $this->pasta($out, 2)['paiId']);
        self::assertSame(4, $out->totalArquivos);
        self::assertSame(3, $out->totalPastas);
    }

    #[TestDox('CICLO A→B→A gravado no banco: conta cada pasta uma vez e termina — nada de recursão infinita')]
    public function testCicloNaoRecorreInfinitamente(): void
    {
        $a = $this->secao(1, 'A');
        $b = $this->secao(2, 'B', $a);
        $a->setPai($b);     // o ciclo: A é filha de B, que é filha de A

        $out = $this->montar([$a, $b], [
            $this->documento(10, 'a.pdf', $a),
            $this->documento(11, 'b.pdf', $b),
        ]);

        /* De A: desce em B (1 subpasta, 1 arquivo) e, ao voltar em A, para — A já está no caminho.
           O resultado é o conteúdo REAL do galho (B e seus arquivos), não um número inflado. */
        self::assertSame(1, $this->pasta($out, 1)['subpastas']);
        self::assertSame(2, $this->pasta($out, 1)['arquivos']);
        self::assertSame(1, $this->pasta($out, 2)['subpastas']);
        self::assertSame(2, $this->pasta($out, 2)['arquivos']);
    }

    #[TestDox('arquivo: seção, rótulo da categoria, URLs e tokens pelos geradores; JSON sem < > & crus')]
    public function testArquivoEJson(): void
    {
        $secao = $this->secao(7, 'Procurações');
        $out   = $this->montar([$secao], [$this->documento(42, '</script>x&y.pdf', $secao, 3)]);

        $a = $out->arquivos[0];
        self::assertSame(42, $a['id']);
        self::assertSame(7, $a['secaoId']);
        self::assertSame(3, $a['ordem']);
        self::assertSame('DEMAIS', $a['categoria']);
        self::assertSame('Demais documentos', $a['categoriaRotulo']);
        self::assertSame('/pasta_documento_view/42', $a['viewUrl']);
        self::assertSame('/pasta_documento_download/42', $a['downloadUrl']);
        self::assertSame('/pasta_documento_mover_secao/42', $a['urlMover']);
        self::assertSame('tok_pasta_doc_mover_42', $a['csrfMover']);
        self::assertSame('tok_edit_documento_42', $a['csrfEditar']);
        self::assertSame('tok_delete_documento_42', $a['csrfExcluir']);

        $p = $this->pasta($out, 7);
        self::assertSame('/pasta_secao_renomear/7', $p['urlRenomear']);
        self::assertSame('tok_pasta_secao_mover_7', $p['csrfMover']);
        self::assertSame(self::ROTULOS, $out->categorias);

        $json = $out->json();
        self::assertDoesNotMatchRegularExpression('/[<>&]/', $json, 'HEX_TAG/AMP: nada que feche um <script>');
        self::assertSame('</script>x&y.pdf', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['arquivos'][0]['nome']);
    }
}
