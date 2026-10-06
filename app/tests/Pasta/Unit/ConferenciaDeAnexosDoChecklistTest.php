<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ConferenciaDeAnexosDoChecklist;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A regra "sem anexo" do desenho (`ckAuditar`, dc L4141-4146): item × nome dos arquivos.
 * Provada nos dois sentidos — o filtro que encontra TUDO e o que não encontra NADA — e no caso
 * vazio, porque teste de filtro que só prova o caso cheio já reabriu defeito neste projeto.
 */
#[CoversClass(ConferenciaDeAnexosDoChecklist::class)]
final class ConferenciaDeAnexosDoChecklistTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function padroes(): iterable
    {
        yield 'procuração'         => ['PROCURAÇÃO AD JUDICIA', '/procura/u'];
        yield 'identidade'         => ['Documento de identidade', '/\brg\b|cnh|identidade|_rg|rg_/u'];
        yield 'residência'         => ['Comprovante de residência', '/resid|endereco/u'];
        yield 'contrato'           => ['Contrato de honorários', '/contrato/u'];
        yield 'hipossuficiência'   => ['Declaração de hipossuficiência', '/hipossuf|gratuidade|pobreza/u'];
        yield '1ª palavra de 5+'   => ['Certidão de casamento', '/certid/u'];
        yield 'palavra curta pula' => ['RG do pai', '/rg do /u'];
        yield 'regex é literal'    => ['(cópia) x', '/\(copia/u'];
    }

    #[DataProvider('padroes')]
    #[TestDox('padrão do item "$titulo" é $esperado')]
    public function testPadraoDoItem(string $titulo, string $esperado): void
    {
        self::assertSame($esperado, ConferenciaDeAnexosDoChecklist::padraoDoItem($titulo));
    }

    #[TestDox('item marcado com arquivo correspondente não acusa; marcado sem arquivo acusa; pendente sem arquivo não acusa')]
    public function testMarcadoSemAnexo(): void
    {
        $c = ConferenciaDeAnexosDoChecklist::conferir(
            [
                ['id' => 1, 'titulo' => 'PROCURAÇÃO', 'concluido' => true],
                ['id' => 2, 'titulo' => 'DOCUMENTO DE IDENTIDADE', 'concluido' => true],
                ['id' => 3, 'titulo' => 'COMPROVANTE DE RESIDÊNCIA', 'concluido' => false],
            ],
            ['PROCURACAO ASSINADA.PDF', 'foto.jpg'],
        );

        self::assertTrue($c->temAnexo(1));
        self::assertFalse($c->temAnexo(2));
        self::assertFalse($c->temAnexo(3));
        self::assertSame([2], $c->marcadosSemAnexo, 'só o MARCADO sem arquivo; o pendente sem arquivo é o esperado');
        self::assertTrue($c->marcadoSemAnexo(2));
        self::assertFalse($c->marcadoSemAnexo(3));
        self::assertSame(1, $c->totalMarcadosSemAnexo());
    }

    #[TestDox('acentos e maiúsculas não importam; "rg" casa como palavra e com sublinhado, não dentro de outra palavra')]
    public function testNormalizacaoEFronteiraDoRg(): void
    {
        $itens = [['id' => 7, 'titulo' => 'Identidade', 'concluido' => true]];

        self::assertTrue(ConferenciaDeAnexosDoChecklist::conferir($itens, ['RG.pdf'])->temAnexo(7));
        self::assertTrue(ConferenciaDeAnexosDoChecklist::conferir($itens, ['scan_rg_frente.png'])->temAnexo(7));
        self::assertTrue(ConferenciaDeAnexosDoChecklist::conferir($itens, ['CNH do cliente.pdf'])->temAnexo(7));
        self::assertFalse(ConferenciaDeAnexosDoChecklist::conferir($itens, ['cargo.pdf'])->temAnexo(7), '"rg" dentro de "cargo" não é RG');
    }

    #[TestDox('o filtro que encontra TUDO: cada item marcado tem arquivo, nenhuma acusação')]
    public function testEncontraTudo(): void
    {
        $c = ConferenciaDeAnexosDoChecklist::conferir(
            [
                ['id' => 1, 'titulo' => 'Procuração', 'concluido' => true],
                ['id' => 2, 'titulo' => 'Contrato de honorários', 'concluido' => true],
                ['id' => 3, 'titulo' => 'Declaração de hipossuficiência', 'concluido' => true],
            ],
            ['procuracao.pdf', 'CONTRATO.PDF', 'pedido de gratuidade.pdf'],
        );

        self::assertSame([], $c->marcadosSemAnexo);
        self::assertSame(0, $c->totalMarcadosSemAnexo());
        self::assertSame([1 => true, 2 => true, 3 => true], $c->temAnexoPorItem);
    }

    #[TestDox('o filtro que não encontra NADA: pasta sem arquivo nenhum acusa todos os marcados')]
    public function testNaoEncontraNada(): void
    {
        $c = ConferenciaDeAnexosDoChecklist::conferir(
            [
                ['id' => 1, 'titulo' => 'Procuração', 'concluido' => true],
                ['id' => 2, 'titulo' => 'Contrato', 'concluido' => true],
            ],
            [],
        );

        self::assertSame([1, 2], $c->marcadosSemAnexo);
        self::assertSame([1 => false, 2 => false], $c->temAnexoPorItem);
    }

    #[TestDox('checklist vazio: nada a conferir, nenhuma acusação; item desconhecido não acusa')]
    public function testVazio(): void
    {
        $c = ConferenciaDeAnexosDoChecklist::conferir([], ['procuracao.pdf']);

        self::assertSame([], $c->temAnexoPorItem);
        self::assertSame(0, $c->totalMarcadosSemAnexo());
        self::assertTrue($c->temAnexo(999), 'item criado depois da renderização não foi conferido: não há "sem anexo"');
        self::assertFalse($c->marcadoSemAnexo(999));
    }

    #[TestDox('daPasta: sem escritório na sessão não confere; item e documento de outro escritório são descartados')]
    public function testDaPastaIsolaPorEscritorio(): void
    {
        $meu   = $this->tenant(1);
        $outro = $this->tenant(2);

        $itemMeu   = $this->item($meu, 10, 'Procuração', true);
        $itemOutro = $this->item($outro, 11, 'Contrato', true);
        $docOutro  = $this->documento($outro, 'PROCURAÇÃO', 'procuracao.pdf');

        $c = ConferenciaDeAnexosDoChecklist::daPasta([$itemMeu, $itemOutro], [$docOutro], $meu);
        self::assertSame([10 => false], $c->temAnexoPorItem, 'o arquivo de outro escritório não conta como anexo, e o item dele nem entra');
        self::assertSame([10], $c->marcadosSemAnexo);

        $comMeuDoc = ConferenciaDeAnexosDoChecklist::daPasta([$itemMeu], [$this->documento($meu, 'X', 'Procuracao_assinada.pdf')], $meu);
        self::assertSame([], $comMeuDoc->marcadosSemAnexo, 'o nome ORIGINAL também vale');

        $semSessao = ConferenciaDeAnexosDoChecklist::daPasta([$itemMeu], [], null);
        self::assertSame([], $semSessao->temAnexoPorItem);
        self::assertSame(0, $semSessao->totalMarcadosSemAnexo());
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function item(Tenant $tenant, int $id, string $titulo, bool $concluido): PastaChecklistItem
    {
        $item = (new PastaChecklistItem())->setTitulo($titulo)->setConcluido($concluido)->setTenant($tenant);
        (new \ReflectionProperty(PastaChecklistItem::class, 'id'))->setValue($item, $id);

        return $item;
    }

    private function documento(Tenant $tenant, string $titulo, string $nomeOriginal): PastaDocumento
    {
        return (new PastaDocumento())
            ->setTenant($tenant)
            ->setTitulo($titulo)
            ->setNomeOriginal($nomeOriginal);
    }
}
