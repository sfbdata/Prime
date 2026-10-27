<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PoliticaDeEspelhamento::class)]
final class PoliticaDeEspelhamentoTest extends TestCase
{
    #[TestDox('vazia (o padrão) não espelha categoria nenhuma')]
    public function testVaziaDesliga(): void
    {
        foreach ([new PoliticaDeEspelhamento(), new PoliticaDeEspelhamento(''), new PoliticaDeEspelhamento(' , ,'), PoliticaDeEspelhamento::desligada()] as $politica) {
            self::assertFalse($politica->estaLigada());
            foreach (CategoriaDeArquivo::cases() as $categoria) {
                self::assertFalse($politica->espelha($categoria));
            }
        }
    }

    #[TestDox('liga só as categorias listadas, com espaços tolerados')]
    public function testLigaAsListadas(): void
    {
        $politica = new PoliticaDeEspelhamento(' foto_perfil , cliente_documento');

        self::assertTrue($politica->espelha(CategoriaDeArquivo::FOTO_PERFIL));
        self::assertTrue($politica->espelha(CategoriaDeArquivo::CLIENTE_DOCUMENTO));
        self::assertFalse($politica->espelha(CategoriaDeArquivo::PASTA_DOCUMENTO));
        self::assertSame(['foto_perfil', 'cliente_documento'], $politica->categoriasLigadas());
    }

    #[TestDox('entrada que não é categoria não liga nada e fica registrada como inválida')]
    public function testInvalidaNaoLiga(): void
    {
        $politica = new PoliticaDeEspelhamento('todas,*,PASTA_DOCUMENTO,pasta_documentos');

        self::assertFalse($politica->estaLigada(), 'nem curinga, nem maiúscula, nem plural ligam algo');
        self::assertSame(['todas', '*', 'PASTA_DOCUMENTO', 'pasta_documentos'], $politica->entradasInvalidas());
        self::assertCount(4, $politica->posicoesInvalidas());
    }
}
