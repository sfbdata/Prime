<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\TextoDoEnvioAoCliente;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Assunto, mensagem padrão (desenho `mailCorpo`, dc N:5236-5240) e o miolo HTML do e-mail ao cliente. */
#[CoversClass(TextoDoEnvioAoCliente::class)]
final class TextoDoEnvioAoClienteTest extends TestCase
{
    #[TestDox('A mensagem padrão do desenho, com o primeiro nome e os pontos rotulados')]
    public function testCorpoPadrao(): void
    {
        $corpo = TextoDoEnvioAoCliente::corpo('MARIA DA SILVA', '0701134-57.2025.8.07.0007', 'A ré contestou.', [
            ['tipo' => 'prazo', 'texto' => '15 dias para réplica.'],
            ['tipo' => 'providencia', 'texto' => 'Enviar documentos.'],
            ['tipo' => 'ok', 'texto' => 'Nada mais.'],
        ]);

        self::assertSame(
            "Olá, Maria.\n\nSegue uma atualização sobre o seu processo 0701134-57.2025.8.07.0007.\n\nA ré contestou.\n\n"
            . "Prazo: 15 dias para réplica.\nPróximo passo: Enviar documentos.\nPonto: Nada mais.\n\n"
            . "Qualquer dúvida, estamos à disposição.\n\nEste resumo foi revisado pelo escritório e não substitui a orientação do seu advogado.",
            $corpo,
        );
        self::assertStringNotContainsString('—', $corpo);
        self::assertStringStartsWith("Olá.\n\n", TextoDoEnvioAoCliente::corpo(null, '1', 'R', []));
        self::assertSame('Atualização do seu processo 0701134-57.2025.8.07.0007', TextoDoEnvioAoCliente::assunto('0701134-57.2025.8.07.0007'));
    }

    #[TestDox('O miolo HTML escapa o texto e vira parágrafos com quebra de linha')]
    public function testHtmlEscapado(): void
    {
        self::assertSame(
            "<p style=\"margin:0 0 14px;\">Olá &lt;script&gt;<br>\nlinha 2</p>" . '<p style="margin:0 0 14px;">&quot;fim&quot; &amp; tchau</p>',
            TextoDoEnvioAoCliente::html("Olá <script>\r\nlinha 2\n\n\n\"fim\" & tchau"),
        );
    }

    #[TestDox('Nomes: "Maria" no corpo e "Maria da" no vazio do cartão')]
    public function testNomes(): void
    {
        self::assertSame('Maria', TextoDoEnvioAoCliente::primeiroNome('  MARIA DA SILVA'));
        self::assertSame('', TextoDoEnvioAoCliente::primeiroNome(null));
        self::assertSame('Maria da', TextoDoEnvioAoCliente::doisPrimeirosNomes('Maria  da Silva'));
    }
}
