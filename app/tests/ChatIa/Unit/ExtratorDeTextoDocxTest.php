<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Service\ExtratorDeTextoDocx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtratorDeTextoDocx::class)]
final class ExtratorDeTextoDocxTest extends TestCase
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @param array<string, string> $arquivos */
    private function zip(array $arquivos): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new \ZipArchive();
        $zip->open($caminho, \ZipArchive::OVERWRITE);
        foreach ($arquivos as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }
        $zip->close();
        $bytes = (string) file_get_contents($caminho);
        unlink($caminho);

        return $bytes;
    }

    private function docx(string $corpo): string
    {
        return $this->zip([
            '[Content_Types].xml' => '<Types/>',
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '"><w:body>' . $corpo . '</w:body></w:document>',
        ]);
    }

    #[TestDox('Extrai o texto dos parágrafos, um por linha, ignorando o resto')]
    public function testExtraiTexto(): void
    {
        $texto = (new ExtratorDeTextoDocx())->extrair($this->docx(
            '<w:p><w:r><w:t>Primeira</w:t></w:r><w:r><w:t xml:space="preserve"> linha</w:t></w:r></w:p><w:p><w:r><w:t>Segunda</w:t></w:r></w:p>',
        ));

        self::assertStringContainsString('Primeira linha', $texto);
        self::assertStringContainsString('Segunda', $texto);
        self::assertStringContainsString("\n", $texto);
    }

    #[TestDox('Texto acima de 60 mil caracteres é cortado com aviso')]
    public function testCortaNoTeto(): void
    {
        $texto = (new ExtratorDeTextoDocx())->extrair($this->docx('<w:p><w:r><w:t>' . str_repeat('a', 70000) . '</w:t></w:r></w:p>'));

        self::assertStringEndsWith(ExtratorDeTextoDocx::AVISO_DE_CORTE, $texto);
        self::assertLessThan(60100, mb_strlen($texto));
    }

    #[TestDox('Recusa: não-ZIP, ZIP sem document.xml, DOCTYPE/ENTITY e razão de compressão absurda (zip bomb)')]
    public function testRecusas(): void
    {
        $extrator = new ExtratorDeTextoDocx();
        $casos = [
            'não é zip' => 'texto qualquer',
            'sem document.xml' => $this->zip(['[Content_Types].xml' => '<Types/>', 'x.txt' => 'oi']),
            'com entidade' => $this->zip([
                '[Content_Types].xml' => '<Types/>',
                'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY e "x">]><w:document xmlns:w="' . self::W . '"/>',
            ]),
            'zip bomb' => $this->zip([
                '[Content_Types].xml' => '<Types/>',
                'word/document.xml' => '<w:document xmlns:w="' . self::W . '">' . str_repeat(' ', 5_000_000) . '</w:document>',
            ]),
        ];

        foreach ($casos as $rotulo => $bytes) {
            try {
                $extrator->extrair($bytes);
                self::fail("Devia recusar: $rotulo");
            } catch (AnexoDoChatInvalidoException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
