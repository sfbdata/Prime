<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ConflitoDeNumeroCnj;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Aviso "outro número de processo" (DOC-81, `processoDe` de bj-docsug.js L77-84). Provado nos dois
 * sentidos: o caso em que TUDO bate com o cadastro (nenhum aviso) e o caso em que aparece número
 * estranho; e o vazio.
 */
#[CoversClass(ConflitoDeNumeroCnj::class)]
final class ConflitoDeNumeroCnjTest extends TestCase
{
    private const DO_CADASTRO = '0701134-57.2025.8.07.0007';
    private const OUTRO       = '0009999-11.2024.8.26.0100';

    #[TestDox('arquivo com outro número CNJ: aviso com o número e a fonte, sem assumir nenhum')]
    public function testOutroNumeroNoArquivo(): void
    {
        $c = ConflitoDeNumeroCnj::procurar([self::DO_CADASTRO], 'Pasta 12 FULANO', ['Sentença ' . self::OUTRO . '.pdf']);

        self::assertTrue($c->temConflito());
        self::assertSame(self::DO_CADASTRO, $c->principal);
        self::assertSame([['numero' => self::OUTRO, 'fonte' => 'arquivo Sentença ' . self::OUTRO . '.pdf']], $c->conflitos);
        self::assertSame(
            'Outro número de processo aparece nesta pasta: ' . self::OUTRO . ' (arquivo Sentença ' . self::OUTRO . '.pdf). Nenhum foi assumido; confirme qual é o processo desta pasta.',
            $c->texto(),
        );
    }

    #[TestDox('tudo bate com o cadastro (com e sem máscara): nenhum aviso')]
    public function testTudoBateComOCadastro(): void
    {
        // O cadastro guarda só os dígitos; o arquivo, com máscara. É o mesmo processo.
        $c = ConflitoDeNumeroCnj::procurar(['07011345720258070007'], 'Pasta ' . self::DO_CADASTRO, ['Inicial ' . self::DO_CADASTRO . '.pdf', 'rg.pdf']);

        self::assertFalse($c->temConflito());
        self::assertSame([], $c->conflitos);
        self::assertSame('', $c->texto());
    }

    #[TestDox('número do SEGUNDO processo vinculado não é conflito (divergência consciente do desenho)')]
    public function testSegundoProcessoVinculadoNaoEConflito(): void
    {
        $c = ConflitoDeNumeroCnj::procurar([self::DO_CADASTRO, self::OUTRO], '', ['Recurso ' . self::OUTRO . '.pdf']);

        self::assertFalse($c->temConflito());
    }

    #[TestDox('sem processo vinculado: o primeiro número achado é o principal e os demais são o conflito, como no desenho')]
    public function testSemCadastro(): void
    {
        $c = ConflitoDeNumeroCnj::procurar([], 'Pasta ' . self::DO_CADASTRO, ['x ' . self::OUTRO . '.pdf', 'y ' . self::OUTRO . '.pdf']);

        self::assertSame(self::DO_CADASTRO, $c->principal);
        self::assertSame([['numero' => self::OUTRO, 'fonte' => 'arquivo x ' . self::OUTRO . '.pdf']], $c->conflitos, 'o mesmo número em dois arquivos é UM conflito');
    }

    #[TestDox('vazio: sem cadastro e sem número em lugar nenhum, nada a avisar; número sem máscara no arquivo não conta')]
    public function testVazio(): void
    {
        $c = ConflitoDeNumeroCnj::procurar([], '', []);
        self::assertNull($c->principal);
        self::assertFalse($c->temConflito());

        $semMascara = ConflitoDeNumeroCnj::procurar([self::DO_CADASTRO], '', ['00099991120248260100.pdf', '']);
        self::assertFalse($semMascara->temConflito());
    }

    #[TestDox('muitos números: o texto lista 5 e resume o resto')]
    public function testTextoResumeMuitos(): void
    {
        $arquivos = [];
        for ($i = 1; $i <= 7; ++$i) {
            $arquivos[] = \sprintf('doc %07d-11.2024.8.26.0100.pdf', $i);
        }

        $c = ConflitoDeNumeroCnj::procurar([self::DO_CADASTRO], '', $arquivos);

        self::assertCount(7, $c->conflitos);
        self::assertStringStartsWith('Outros números de processo aparecem nesta pasta: ', $c->texto());
        self::assertStringContainsString(' e mais 2. Nenhum foi assumido', $c->texto());
    }

    #[TestDox('daPasta: sem escritório na sessão ou pasta de outro escritório, nada; documento de outro escritório não conta')]
    public function testDaPastaIsolaPorEscritorio(): void
    {
        $meu   = $this->tenant(1);
        $outro = $this->tenant(2);

        $pasta = new Pasta();
        $pasta->setTenant($meu);
        $pasta->setNup('12');
        $pasta->getDocumentos()->add($this->documento($outro, 'Sentença ' . self::OUTRO));

        self::assertFalse(ConflitoDeNumeroCnj::daPasta($pasta, [self::DO_CADASTRO], $meu)->temConflito(), 'o arquivo de outro escritório não entra');

        $pasta->getDocumentos()->add($this->documento($meu, 'Sentença ' . self::OUTRO));
        self::assertTrue(ConflitoDeNumeroCnj::daPasta($pasta, [self::DO_CADASTRO], $meu)->temConflito());
        self::assertFalse(ConflitoDeNumeroCnj::daPasta($pasta, [self::DO_CADASTRO], $outro)->temConflito(), 'pasta de outro escritório');
        self::assertFalse(ConflitoDeNumeroCnj::daPasta($pasta, [self::DO_CADASTRO], null)->temConflito(), 'sem escritório na sessão');
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    private function documento(Tenant $tenant, string $titulo): PastaDocumento
    {
        return (new PastaDocumento())->setTenant($tenant)->setTitulo($titulo)->setNomeOriginal('arquivo.pdf');
    }
}
