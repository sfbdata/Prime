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
 * linha de dados do desenho, "tamanho · data" ("1,2 MB · hoje", "240 KB · 18/08"), e o ícone pelo tipo
 * (o `fi` pequeno do desenho).
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

    #[TestDox('PDF: nome do arquivo, extensão em maiúsculas, ícone do PDF e a linha "tamanho · hoje" no dia; o caminho não sai')]
    public function testPdf(): void
    {
        $d = self::documento('Procuração assinada.pdf', (int) (1.2 * 1024 * 1024));

        $saida = DocumentoDoIndiceOutput::fromEntity($d, $d->getCarregadoEm());

        self::assertSame(31, $saida->id);
        self::assertSame('Procuração assinada.pdf', $saida->nome);
        self::assertSame('PDF', $saida->extensao);
        self::assertSame('1,2 MB · hoje', $saida->dados());
        self::assertSame('bi-file-earmark-pdf-fill', $saida->icone());
        self::assertSame('pdf', $saida->familia());
        self::assertStringNotContainsString('segredo', (string) json_encode(get_object_vars($saida)));
    }

    #[TestDox('data como o desenho: "hoje" no dia, "18/08" no ano, com o ano quando é de outro ano')]
    public function testDataCurta(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-19 15:00');

        self::assertSame('hoje', DocumentoDoIndiceOutput::dataCurta(new \DateTimeImmutable('2026-10-19 08:00'), $hoje));
        self::assertSame('18/08', DocumentoDoIndiceOutput::dataCurta(new \DateTimeImmutable('2026-08-18 10:00'), $hoje));
        self::assertSame('18/08/2025', DocumentoDoIndiceOutput::dataCurta(new \DateTimeImmutable('2025-08-18 10:00'), $hoje));
    }

    #[TestDox('Word e Excel com os ícones do desenho; sem extensão conhecida, a folha genérica; sem extensão, "Arquivo"')]
    public function testOutrosTipos(): void
    {
        self::assertSame('bi-file-earmark-word-fill', DocumentoDoIndiceOutput::fromEntity(self::documento('a.docx', 10), new \DateTimeImmutable())->icone());
        self::assertSame('bi-file-earmark-excel-fill', DocumentoDoIndiceOutput::fromEntity(self::documento('a.xlsx', 10), new \DateTimeImmutable())->icone());
        $odt = DocumentoDoIndiceOutput::fromEntity(self::documento('a.odt', 10), new \DateTimeImmutable());
        self::assertSame('bi-file-earmark-fill', $odt->icone());
        self::assertSame('outro', $odt->familia());
        self::assertSame('ODT', $odt->tipo);
        self::assertSame('Arquivo', DocumentoDoIndiceOutput::fromEntity(self::documento('sem_extensao', 10), new \DateTimeImmutable())->tipo);
    }
}
