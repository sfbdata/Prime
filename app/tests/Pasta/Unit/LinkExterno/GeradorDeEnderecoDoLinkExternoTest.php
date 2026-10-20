<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit\LinkExterno;

use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Pasta\Service\GeradorDeEnderecoDoLinkExterno;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(GeradorDeEnderecoDoLinkExterno::class)]
final class GeradorDeEnderecoDoLinkExternoTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'acento e caixa' => ['MARIA JOSÉ DA CONCEIÇÃO SILVA', 'maria-jose-da-conceicao'];
        yield 'pontuação' => ['Empresa X Ltda.', 'empresa-x-ltda'];
        yield 'até 40 caracteres' => ['Abcdefghijklmnopqrstuvwxyz Abcdefghijklmnopqrstuvwxyz', 'abcdefghijklmnopqrstuvwxyz-abcdefghijklm'];
        yield 'espaços nas pontas' => ['  João  ', 'joao'];
    }

    #[DataProvider('nomes')]
    #[TestDox('Slug do desenho: sem acento, minúsculas, até 4 palavras e 40 caracteres')]
    public function testSlug(string $nome, string $esperado): void
    {
        self::assertSame($esperado, GeradorDeEnderecoDoLinkExterno::slug($nome));
    }

    #[TestDox('Sem nome do cliente (ou só símbolos), o endereço usa processo-<7 primeiros dígitos>')]
    public function testSemNome(): void
    {
        self::assertSame('processo-0701134', GeradorDeEnderecoDoLinkExterno::identificacao(null, '0701134-57.2025.8.07.0007'));
        self::assertSame('processo-0701134', GeradorDeEnderecoDoLinkExterno::identificacao('***', '07011345720258070007'));
    }

    #[TestDox('Endereço: bluejus-<slug>-<8 do alfabeto sem 0/1/i/l/o>')]
    public function testFormato(): void
    {
        $repo = $this->createMock(LinkExternoDoPushRepository::class);
        $repo->method('enderecoEmUso')->willReturn(false);

        $endereco = (new GeradorDeEnderecoDoLinkExterno($repo))->gerar('Maria Silva', '07011345720258070007');

        self::assertMatchesRegularExpression('/^bluejus-maria-silva-[23456789abcdefghjkmnpqrstuvwxyz]{8}$/', $endereco);
        self::assertMatchesRegularExpression('/^bluejus-[a-z0-9-]{3,70}$/', $endereco, 'precisa casar com o requirement da rota pública');
    }

    #[TestDox('Colisão com endereço existente sorteia de novo')]
    public function testColisao(): void
    {
        $vistos = [];
        $repo = $this->createMock(LinkExternoDoPushRepository::class);
        $repo->expects(self::exactly(3))->method('enderecoEmUso')
            ->willReturnCallback(static function (string $endereco) use (&$vistos): bool {
                $vistos[] = $endereco;

                return count($vistos) < 3;
            });

        $endereco = (new GeradorDeEnderecoDoLinkExterno($repo))->gerar(null, '07011345720258070007');

        self::assertSame($vistos[2], $endereco);
        self::assertStringStartsWith('bluejus-processo-0701134-', $endereco);
    }

    #[TestDox('Sem endereço livre depois de 10 sorteios, falha em vez de repetir um em uso')]
    public function testDesisteDepoisDeDezColisoes(): void
    {
        $repo = $this->createMock(LinkExternoDoPushRepository::class);
        $repo->expects(self::exactly(10))->method('enderecoEmUso')->willReturn(true);

        $this->expectException(\RuntimeException::class);
        (new GeradorDeEnderecoDoLinkExterno($repo))->gerar('Maria', '1');
    }

    #[TestDox('Código de acesso tem 6 dígitos')]
    public function testCodigo(): void
    {
        $gerador = new GeradorDeEnderecoDoLinkExterno($this->createStub(LinkExternoDoPushRepository::class));

        self::assertMatchesRegularExpression('/^[1-9]\d{5}$/', $gerador->gerarCodigo());
    }
}
