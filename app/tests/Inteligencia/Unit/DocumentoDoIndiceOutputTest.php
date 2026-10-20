<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Inteligencia\DTO\DocumentoDoIndiceOutput;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A linha da aba Documentos do drawer (spec inteligencia-drawer-da-pasta, F4): só metadados, com a
 * linha de dados "PDF · 1,2 MB · 12/10/2026" e o ícone pelo tipo (o `fi` pequeno do desenho).
 */
#[CoversClass(DocumentoDoIndiceOutput::class)]
final class DocumentoDoIndiceOutputTest extends TestCase
{
    private static function documento(string $nome, int $bytes): PastaDocumento
    {
        $d = new PastaDocumento();
        $d->setTitulo('Título interno');
        $d->setCategoria(PastaDocumento::CATEGORIA_DEMAIS);
        $d->setCaminhoArquivo('segredo/caminho/' . $nome);
        $d->setNomeOriginal($nome);
        $d->setMimeType('application/octet-stream');
        $d->setTamanhoBytes($bytes);
        DefineId::em($d, 31);

        return $d;
    }

    #[TestDox('tamanho legível com vírgula decimal, como o desenho (240 KB, 1,2 MB)')]
    public function testTamanhoLegivel(): void
    {
        self::assertSame('900 B', DocumentoDoIndiceOutput::tamanhoLegivel(900));
        self::assertSame('240 KB', DocumentoDoIndiceOutput::tamanhoLegivel(240 * 1024));
        self::assertSame('1,2 MB', DocumentoDoIndiceOutput::tamanhoLegivel((int) (1.2 * 1024 * 1024)));
        self::assertSame('2,0 GB', DocumentoDoIndiceOutput::tamanhoLegivel(2 * 1024 * 1024 * 1024));
    }

    #[TestDox('PDF: nome do arquivo, extensão em maiúsculas, ícone do PDF e a linha "PDF · tamanho · data"; o caminho não sai')]
    public function testPdf(): void
    {
        $d = self::documento('Procuração assinada.pdf', (int) (1.2 * 1024 * 1024));

        $saida = DocumentoDoIndiceOutput::fromEntity($d);

        self::assertSame(31, $saida->id);
        self::assertSame('Procuração assinada.pdf', $saida->nome);
        self::assertSame('PDF', $saida->extensao);
        self::assertSame('PDF · 1,2 MB · ' . $d->getCarregadoEm()->format('d/m/Y'), $saida->dados());
        self::assertSame('bi-file-earmark-pdf-fill', $saida->icone());
        self::assertSame('pdf', $saida->familia());
        self::assertStringNotContainsString('segredo', (string) json_encode(get_object_vars($saida)));
    }

    #[TestDox('Word e Excel com os ícones do desenho; sem extensão conhecida, a folha genérica; sem extensão, "Arquivo"')]
    public function testOutrosTipos(): void
    {
        self::assertSame('bi-file-earmark-word-fill', DocumentoDoIndiceOutput::fromEntity(self::documento('a.docx', 10))->icone());
        self::assertSame('bi-file-earmark-excel-fill', DocumentoDoIndiceOutput::fromEntity(self::documento('a.xlsx', 10))->icone());
        $odt = DocumentoDoIndiceOutput::fromEntity(self::documento('a.odt', 10));
        self::assertSame('bi-file-earmark-fill', $odt->icone());
        self::assertSame('outro', $odt->familia());
        self::assertSame('ODT', $odt->tipo);
        self::assertSame('Arquivo', DocumentoDoIndiceOutput::fromEntity(self::documento('sem_extensao', 10))->tipo);
    }
}
