<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\UseCase\VincularProcessoUseCase;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Texto do cartão do sistema ao vincular (P5d, decisão do coordenador de 20/10): número CNJ, a classe (sem ela, o
 * assunto) da tabela pública e o tribunal. A ação da pasta (texto livre) nunca entra.
 */
#[CoversClass(VincularProcessoUseCase::class)]
final class TextoDoRegistroDoVinculoTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function casos(): iterable
    {
        yield 'classe e tribunal' => ['PROCEDIMENTO COMUM', 'COBRANÇA', 'TJDFT', 'processo N vinculado à pasta (PROCEDIMENTO COMUM · TJDFT).'];
        yield 'sem classe, o assunto' => ['', 'COBRANÇA', 'TJDFT', 'processo N vinculado à pasta (COBRANÇA · TJDFT).'];
        yield 'só o tribunal' => ['', '', 'TJDFT', 'processo N vinculado à pasta (TJDFT).'];
        yield 'nada: sem parênteses' => ['', '', '', 'processo N vinculado à pasta.'];
        yield 'espaços contam como vazio' => ['  ', ' ', '', 'processo N vinculado à pasta.'];
    }

    #[DataProvider('casos')]
    #[TestDox('$esperado')]
    public function testTexto(string $classe, string $assunto, string $tribunal, string $esperado): void
    {
        $processo = new Processo();
        $processo->setClasseProcessual($classe);
        $processo->setAssuntoProcessual($assunto);
        $processo->setSiglaTribunal($tribunal);

        self::assertSame($esperado, VincularProcessoUseCase::textoDoRegistro($processo, 'N'));
    }
}
