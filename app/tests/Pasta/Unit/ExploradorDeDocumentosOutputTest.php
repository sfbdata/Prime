<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
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

    private const PASTA_ID = 9;

    /** @param PastaSecao[] $secoes @param PastaDocumento[] $docs */
    private function montar(array $secoes, array $docs): ExploradorDeDocumentosOutput
    {
        return ExploradorDeDocumentosOutput::montar(
            $secoes,
            $docs,
            self::ROTULOS,
            self::url(...),
            self::csrf(...),
            self::PASTA_ID,
        );
    }

    /** @param array<string, mixed> $params */
    private static function url(string $rota, array $params): string
    {
        return '/' . $rota . '/' . implode(',', $params);
    }

    private static function csrf(string $id): string
    {
        return 'tok_' . $id;
    }

    #[TestDox('D4: o JSON leva as URLs e o token ÚNICO das ações em lote da pasta (pex_lote_<pastaId>)')]
    public function testLote(): void
    {
        $out = $this->montar([], []);

        self::assertSame('/pasta_documentos_mover_lote/9', $out->urlMoverLote);
        self::assertSame('/pasta_documentos_excluir_lote/9', $out->urlExcluirLote);
        self::assertSame('tok_pex_lote_9', $out->csrfLote);
        self::assertSame('pex_lote_9', ExploradorDeDocumentosOutput::idDoTokenDeLote(9));

        $json = json_decode($out->json(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tok_pex_lote_9', $json['csrfLote']);
        self::assertSame('/pasta_documentos_mover_lote/9', $json['urlMoverLote']);
        self::assertSame('/pasta_documentos_excluir_lote/9', $json['urlExcluirLote']);
    }

    #[TestDox('D2: cada arquivo e cada pasta trazem favorito e favoritoEm (a hora em que marcou, do mapa recebido); o topo traz urlFavorito e o token pex_favorito_<pastaId>')]
    public function testFavoritos(): void
    {
        $marcada    = $this->secao(1, 'Marcada');
        $desmarcada = $this->secao(2, 'Desmarcada');
        $docMarcado = $this->documento(10, 'marcado.pdf', null);
        $docLivre   = $this->documento(11, 'livre.pdf', $marcada);

        $out = ExploradorDeDocumentosOutput::montar(
            [$marcada, $desmarcada],
            [$docMarcado, $docLivre],
            self::ROTULOS,
            self::url(...),
            self::csrf(...),
            self::PASTA_ID,
            ['documentos' => [10 => '2026-10-06T10:15:00'], 'secoes' => [1 => '2026-10-05T09:00:00']],
        );

        self::assertTrue($out->pastas[0]['favorito']);
        self::assertSame('2026-10-05T09:00:00', $out->pastas[0]['favoritoEm']);
        self::assertNull($out->pastas[1]['favoritoEm']);
        self::assertSame('2026-10-06T10:15:00', $out->arquivos[0]['favoritoEm']);
        self::assertNull($out->arquivos[1]['favoritoEm']);
        self::assertFalse($out->pastas[1]['favorito']);
        self::assertTrue($out->arquivos[0]['favorito']);
        self::assertFalse($out->arquivos[1]['favorito']);
        self::assertSame('/pasta_documentos_favorito/9', $out->urlFavorito);
        self::assertSame('tok_pex_favorito_9', $out->csrfFavorito);
        self::assertSame('pex_favorito_9', ExploradorDeDocumentosOutput::idDoTokenDeFavorito(9));

        $json = json_decode($out->json(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/pasta_documentos_favorito/9', $json['urlFavorito']);
        self::assertSame('tok_pex_favorito_9', $json['csrfFavorito']);
        self::assertTrue($json['arquivos'][0]['favorito']);
        self::assertTrue($json['pastas'][0]['favorito']);
        self::assertSame('2026-10-06T10:15:00', $json['arquivos'][0]['favoritoEm']);
        self::assertSame('2026-10-05T09:00:00', $json['pastas'][0]['favoritoEm']);
    }

    #[TestDox('D2: favorito sem hora conhecida (`true` no mapa) continua favorito, com favoritoEm NULL')]
    public function testFavoritoSemHora(): void
    {
        $secao = $this->secao(1, 'S');
        $doc   = $this->documento(10, 'a.pdf', null);

        $out = ExploradorDeDocumentosOutput::montar([$secao], [$doc], self::ROTULOS, self::url(...), self::csrf(...), self::PASTA_ID, ['documentos' => [10 => true], 'secoes' => [1 => true]]);

        self::assertTrue($out->arquivos[0]['favorito']);
        self::assertNull($out->arquivos[0]['favoritoEm']);
        self::assertTrue($out->pastas[0]['favorito']);
        self::assertNull($out->pastas[0]['favoritoEm']);
    }

    #[TestDox('D2: sem o conjunto de favoritos (ou com ele vazio) nada vem marcado — e arquivo() avulso nasce false')]
    public function testSemFavoritosNadaVemMarcado(): void
    {
        $secao = $this->secao(1, 'S');
        $doc   = $this->documento(10, 'a.pdf', null);

        $out = $this->montar([$secao], [$doc]);

        self::assertFalse($out->pastas[0]['favorito']);
        self::assertFalse($out->arquivos[0]['favorito']);
        self::assertFalse(ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...))['favorito']);
        self::assertTrue(ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...), true)['favorito']);
        self::assertNull($out->pastas[0]['favoritoEm']);
        self::assertNull($out->arquivos[0]['favoritoEm']);
        self::assertNull(ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...))['favoritoEm']);
        self::assertSame('2026-10-06T10:15:00', ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...), true, '2026-10-06T10:15:00')['favoritoEm']);
        self::assertNull(ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...), false, '2026-10-06T10:15:00')['favoritoEm'], 'sem favorito não há hora');
    }

    #[TestDox('D1: o arquivo leva quem enviou (nome), quando foi modificado e as páginas — NULL quando não há')]
    public function testMetadadosDaD1(): void
    {
        $com = $this->documento(1, 'com.pdf', null);
        $com->setEnviadoPor((new User())->setEmail('ana@test.com')->setFullName('Ana Lima'));
        $com->marcarModificadoEm(new \DateTimeImmutable('2026-10-06 14:30:00'));
        $com->setPaginas(12);
        $sem = $this->documento(2, 'sem.pdf', null);

        $out = $this->montar([], [$com, $sem]);

        self::assertSame('Ana Lima', $out->arquivos[0]['enviadoPor'], 'o nome, nunca o e-mail');
        self::assertSame('2026-10-06 14:30:00', $out->arquivos[0]['modificadoEm']);
        self::assertSame(12, $out->arquivos[0]['paginas']);
        self::assertNull($out->arquivos[1]['enviadoPor']);
        self::assertNull($out->arquivos[1]['modificadoEm']);
        self::assertNull($out->arquivos[1]['paginas']);
    }

    #[TestDox('arquivo() estático é a MESMA forma da listagem — upload e edição respondem com ela')]
    public function testArquivoEstaticoEhAFormaDaListagem(): void
    {
        $secao = $this->secao(7, 'Procurações');
        $doc   = $this->documento(42, 'x.pdf', $secao, 3);

        $out = $this->montar([$secao], [$doc]);

        self::assertSame(
            $out->arquivos[0],
            ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...)),
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

    #[TestDox('L9: identicoA pelo sha256 no MESMO nível (outra seção não conta), nomeParecidoCom, regraLimpeza, doDrive e o topo limpeza só da raiz no JSON')]
    public function testDuplicadosELimpeza(): void
    {
        $secao = $this->secao(7, 'Procurações');
        $sha   = str_repeat('a', 64);
        $em    = new \ReflectionProperty(PastaDocumento::class, 'carregadoEm');

        $antigo = $this->documento(1, 'Procuração Gleisson.pdf', null);
        $antigo->setSha256($sha);
        $antigo->setTamanhoBytes(3000);
        $em->setValue($antigo, new \DateTimeImmutable('2026-01-01 10:00:00'));

        $copia = $this->documento(2, 'procuracao_gleisson_assinada.pdf', null);
        $copia->setSha256($sha);
        $copia->setTamanhoBytes(3000);
        $em->setValue($copia, new \DateTimeImmutable('2026-02-01 10:00:00'));

        $naSecao = $this->documento(5, 'outra procuracao gleisson.pdf', $secao);   // mesmo sha, outro nível
        $naSecao->setSha256($sha);
        $naSecao->setTamanhoBytes(3000);

        $vazio = $this->documento(3, 'sem titulo.pdf', null);
        $vazio->setTamanhoBytes(0);

        $doDrive = $this->documento(6, 'ata.pdf', null);       // 0 byte do Drive: rastro do DT-8
        $doDrive->setTamanhoBytes(0);
        $doDrive->setDriveFileId('drive-abc');

        $semHash = $this->documento(4, 'contestacao.pdf', null);   // sha NULL: nunca "idêntico"

        $out = $this->montar([$secao], [$antigo, $copia, $naSecao, $vazio, $doDrive, $semHash]);

        $porId = array_column($out->arquivos, null, 'id');
        self::assertSame(2, $porId[1]['identicoA'], 'o mais antigo aponta a cópia');
        self::assertSame(1, $porId[2]['identicoA'], 'a cópia aponta o que fica');
        self::assertNull($porId[5]['identicoA'], 'mesmo sha em outra seção: não é par');
        self::assertNull($porId[3]['identicoA']);
        self::assertNull($porId[4]['identicoA']);
        self::assertSame(['id' => 2, 'percentual' => 100], $porId[1]['nomeParecidoCom']);
        self::assertNull($porId[5]['nomeParecidoCom'], 'nome parecido também só no mesmo nível');
        self::assertSame('identico', $porId[2]['regraLimpeza']);
        self::assertSame('vazio', $porId[3]['regraLimpeza']);
        self::assertNull($porId[6]['regraLimpeza'], 'do Drive, 0 byte não é sugerido');
        self::assertTrue($porId[6]['doDrive']);
        self::assertFalse($porId[3]['doDrive']);
        self::assertNull($porId[1]['regraLimpeza'], 'o que fica não é sugerido');

        self::assertSame([
            ['regra' => 'identico', 'ids' => [2], 'bytes' => 3000, 'rotulo' => '1 cópia idêntica'],
            ['regra' => 'vazio', 'ids' => [3], 'bytes' => 0, 'rotulo' => '1 vazio'],
        ], $out->limpeza);
        self::assertSame($out->limpeza, json_decode($out->json(), true, 512, JSON_THROW_ON_ERROR)['limpeza']);
    }

    #[TestDox('L9: sem nada a sugerir, limpeza é [] no JSON — e arquivo() sozinho (upload/edição) traz identicoA/nomeParecidoCom NULL')]
    public function testSemLimpeza(): void
    {
        $doc = $this->documento(1, 'peticao.pdf', null);
        $out = $this->montar([], [$doc, $this->documento(2, 'procuracao.pdf', null)]);

        self::assertSame([], $out->limpeza);
        self::assertSame([], json_decode($out->json(), true, 512, JSON_THROW_ON_ERROR)['limpeza']);

        $avulso = ExploradorDeDocumentosOutput::arquivo($doc, self::ROTULOS, self::url(...), self::csrf(...));
        self::assertArrayHasKey('regraLimpeza', $avulso);
        self::assertNull($avulso['regraLimpeza']);
        self::assertFalse($avulso['doDrive']);
        self::assertArrayHasKey('identicoA', $avulso);
        self::assertNull($avulso['identicoA']);
        self::assertArrayHasKey('nomeParecidoCom', $avulso);
        self::assertNull($avulso['nomeParecidoCom']);
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
