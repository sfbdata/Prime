<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Controller\PastaController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O mapa de categorias da aba Documentos existe em dois lugares — o `DOCUMENT_TYPES` privado do
 * `PastaController` (que monta a tela) e `ExploradorDeDocumentosOutput::CATEGORIAS` (que a edição
 * e o upload usam para responder) — e os dois têm de dizer a mesma coisa, na mesma ordem: o
 * `<select>` do modal e o rótulo da linha nova saem um de cada.
 */
#[CoversClass(ExploradorDeDocumentosOutput::class)]
final class CategoriasDaAbaDocumentosTest extends TestCase
{
    #[TestDox('CATEGORIAS do explorador é idêntico ao DOCUMENT_TYPES do PastaController')]
    public function testOsDoisMapasSaoIguais(): void
    {
        $doController = (new \ReflectionClassConstant(PastaController::class, 'DOCUMENT_TYPES'))->getValue();

        self::assertSame($doController, ExploradorDeDocumentosOutput::CATEGORIAS);
    }

    #[TestDox('CONTRATO fica fora: é da aba Financeiro')]
    public function testContratoFicaFora(): void
    {
        self::assertArrayNotHasKey(PastaDocumento::CATEGORIA_CONTRATO, ExploradorDeDocumentosOutput::CATEGORIAS);
        self::assertCount(6, ExploradorDeDocumentosOutput::CATEGORIAS);
    }
}
