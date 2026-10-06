<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\DTO\SugestoesDeLimpezaOutput;
use App\Pasta\Service\SugestoesDeLimpeza;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras da faixa "Ganhe espaço" (L9, DOC-62/64/66; dc `expLimpeza` L4686-4697) e o "nome
 * parecido" (DOC-63). São regras fixas, não IA — e cada uma tem o seu caso de "não marca".
 */
#[CoversClass(SugestoesDeLimpeza::class)]
#[CoversClass(SugestoesDeLimpezaOutput::class)]
final class SugestoesDeLimpezaTest extends TestCase
{
    private const MB = 1024 * 1024;
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @return array{id: int, nome: string, tamanho: int, sha256: ?string, paginas: ?int, carregadoEm: string} */
    private static function arq(int $id, string $nome, int $tamanho = 1000, ?string $sha = null, ?int $paginas = null, string $em = '2026-01-01 10:00:00'): array
    {
        return ['id' => $id, 'nome' => $nome, 'tamanho' => $tamanho, 'sha256' => $sha, 'paginas' => $paginas, 'carregadoEm' => $em];
    }

    /** @return array<string, list<int>> regra => ids */
    private static function idsPorRegra(SugestoesDeLimpezaOutput $out): array
    {
        $r = [];
        foreach ($out->grupos as $g) {
            $r[$g['regra']] = $g['ids'];
        }

        return $r;
    }

    #[TestDox('o filtro remove TUDO: pasta sem nada a sugerir devolve grupos vazios, nenhum idêntico e nenhum parecido')]
    public function testNadaASugerir(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'Petição inicial.pdf', 200_000, self::SHA_A, 12),
            self::arq(2, 'Procuração.pdf', 50_000, self::SHA_B, 1),
            self::arq(3, 'Íntegra do processo.pdf', 50 * self::MB, null, 100),   // limiares exatos: não passa de nenhum
        ]);

        self::assertSame([], $out->grupos);
        self::assertSame([], $out->identicoA);
        self::assertSame([], $out->nomeParecidoCom);
    }

    #[TestDox('pasta sem arquivo: nada')]
    public function testPastaVazia(): void
    {
        $out = SugestoesDeLimpeza::avaliar([]);

        self::assertSame([], $out->grupos);
        self::assertSame([], $out->identicoA);
    }

    #[TestDox('idêntico: mesmo sha256 em qualquer seção; fica o MAIS ANTIGO (empate pelo menor id), as cópias são sugeridas; o que fica aponta para outra cópia')]
    public function testIdenticoFicaOMaisAntigo(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(10, 'procuracao_assinada.pdf', 3000, self::SHA_A, 3, '2026-03-01 09:00:00'),
            self::arq(11, 'PROCURAÇÃO_GLEISSON.pdf', 3000, self::SHA_A, 3, '2026-02-01 09:00:00'),  // o mais antigo
            self::arq(12, 'copia.pdf', 3000, self::SHA_A, 3, '2026-03-01 09:00:00'),
        ]);

        self::assertSame(['identico' => [10, 12]], self::idsPorRegra($out));
        self::assertSame(6000, $out->grupos[0]['bytes']);
        self::assertSame('2 cópias idênticas', $out->grupos[0]['rotulo']);
        self::assertSame(11, $out->identicoA[10]);
        self::assertSame(11, $out->identicoA[12]);
        self::assertSame(10, $out->identicoA[11], 'o que fica aponta o segundo mais antigo (empate 10×12 pelo menor id)');
    }

    #[TestDox('sha NULL não marca: dois arquivos do acervo ainda sem hash não são "idênticos", nem com o mesmo nome e tamanho')]
    public function testShaNuloNaoMarca(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'contrato.pdf', 5000, null),
            self::arq(2, 'contrato.pdf', 5000, null),
            self::arq(3, 'outro.pdf', 5000, self::SHA_A),
        ]);

        self::assertSame([], $out->grupos);
        self::assertSame([], $out->identicoA);
    }

    #[TestDox('sha único não marca: um só arquivo com aquele hash não é cópia de ninguém')]
    public function testShaUnicoNaoMarca(): void
    {
        $out = SugestoesDeLimpeza::avaliar([self::arq(1, 'a.pdf', 10, self::SHA_A), self::arq(2, 'b.pdf', 10, self::SHA_B)]);

        self::assertSame([], $out->identicoA);
    }

    #[TestDox('0 KB é "vazio" — e dois vazios (mesmo hash por definição) NÃO viram grupo de idênticos')]
    public function testVazio(): void
    {
        $vazioSha = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'DOCUMENTO_SEM_TITULO.pdf', 0, $vazioSha),
            self::arq(2, 'outro_vazio.pdf', 0, $vazioSha),
            self::arq(3, 'cheio.pdf', 1),
        ]);

        self::assertSame(['vazio' => [1, 2]], self::idsPorRegra($out));
        self::assertSame(0, $out->grupos[0]['bytes']);
        self::assertSame('2 vazios', $out->grupos[0]['rotulo']);
        self::assertSame([], $out->identicoA);
    }

    #[TestDox('cópia do processo: nome de processo E mais de 100 páginas — 100 exatas não marcam; sem acento casa "íntegra"; número CNJ casa')]
    public function testCopiaDoProcessoPorPaginas(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'PROCESSO_1059316-72_INTEGRA.pdf', 2 * self::MB, null, 412),
            self::arq(2, 'Íntegra dos autos.pdf', 2 * self::MB, null, 101),
            self::arq(3, 'autos completos.pdf', 2 * self::MB, null, 100),          // limiar exato: não
            self::arq(4, 'peças 0701234-55.2024.pdf', 2 * self::MB, null, 150),    // número CNJ
            self::arq(5, 'Contestação.pdf', 2 * self::MB, null, 300),               // muitas páginas, nome comum: não
        ]);

        self::assertSame(['copia_processo' => [1, 2, 4]], self::idsPorRegra($out));
        self::assertSame('3 cópias do processo', $out->grupos[0]['rotulo']);
    }

    #[TestDox('cópia do processo com páginas NULL: só vale o critério dos MB — 50 MB exatos não marcam, 50 MB + 1 byte marca')]
    public function testCopiaDoProcessoSemPaginas(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'Processo completo.pdf', 50 * self::MB, null, null),
            self::arq(2, 'Processo completo (2).pdf', 50 * self::MB + 1, null, null),
            self::arq(3, 'Processo digitalizado.zip', 10 * self::MB, null, null),
        ]);

        self::assertSame(['copia_processo' => [2]], self::idsPorRegra($out));
    }

    #[TestDox('muito grande: MAIS de 100 MB — 100 MB exatos não marcam')]
    public function testMuitoGrande(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'video.mp4', 100 * self::MB),
            self::arq(2, 'audiencia.mp4', 100 * self::MB + 1),
        ]);

        self::assertSame(['muito_grande' => [2]], self::idsPorRegra($out));
        self::assertSame(100 * self::MB + 1, $out->grupos[0]['bytes']);
        self::assertSame('1 muito grande', $out->grupos[0]['rotulo']);
    }

    #[TestDox('uma regra por arquivo, na ordem do desenho: idêntico > vazio > cópia do processo > muito grande; os grupos saem nessa ordem')]
    public function testPrecedenciaEOrdem(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'gravacao.mp4', 200 * self::MB),                                          // muito grande
            self::arq(2, 'processo integral.pdf', 200 * self::MB, self::SHA_A, 500, '2026-01-01 00:00:00'),
            self::arq(3, 'processo integral (cópia).pdf', 200 * self::MB, self::SHA_A, 500, '2026-02-01 00:00:00'), // idêntico vence processo e grande
            self::arq(4, 'vazio.pdf', 0),
        ]);

        self::assertSame([
            'identico'       => [3],
            'vazio'          => [4],
            'copia_processo' => [2],
            'muito_grande'   => [1],
        ], self::idsPorRegra($out));
        self::assertSame(['identico', 'vazio', 'copia_processo', 'muito_grande'], array_column($out->grupos, 'regra'));
    }

    #[TestDox('nome parecido (≥ 85%): só informa — com acento/extensão diferentes; nunca entra num grupo de limpeza')]
    public function testNomeParecidoSoInforma(): void
    {
        $out = SugestoesDeLimpeza::avaliar([
            self::arq(1, 'Procuração Gleisson Bruno.pdf', 1000, self::SHA_A),
            self::arq(2, 'procuracao_gleisson_bruno_assinada.docx', 900, self::SHA_B),
            self::arq(3, 'Comprovante de residência.pdf', 800),
        ]);

        self::assertSame([1 => ['id' => 2, 'percentual' => 100], 2 => ['id' => 1, 'percentual' => 100]], $out->nomeParecidoCom);
        self::assertSame([], $out->grupos, 'nome parecido nunca é sugestão de excluir');
    }

    #[TestDox('rótulo no singular e no plural de cada regra; regra desconhecida é erro')]
    public function testRotulo(): void
    {
        self::assertSame('1 cópia idêntica', SugestoesDeLimpeza::rotulo('identico', 1));
        self::assertSame('1 vazio', SugestoesDeLimpeza::rotulo('vazio', 1));
        self::assertSame('1 cópia do processo', SugestoesDeLimpeza::rotulo('copia_processo', 1));
        self::assertSame('2 muito grandes', SugestoesDeLimpeza::rotulo('muito_grande', 2));

        $this->expectException(\InvalidArgumentException::class);
        SugestoesDeLimpeza::rotulo('ia', 1);
    }
}
