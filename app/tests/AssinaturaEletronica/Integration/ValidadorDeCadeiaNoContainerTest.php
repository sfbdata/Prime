<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Integration;

use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\HttpValidadorDeCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\ScopingHttpClient;

/**
 * O alias do validador de cadeia (etapa 6, bloco 6B): no container é o cliente HTTP, e sem `ASSINATURA_VALIDADOR_URL`
 * e `ASSINATURA_VALIDADOR_TOKEN` ele fica não configurado (os testes nunca chamam o validador de verdade).
 */
#[CoversNothing]
#[Group('assinatura')]
final class ValidadorDeCadeiaNoContainerTest extends KernelTestCase
{
    #[TestDox('ValidadorDeCadeia é o HttpValidadorDeCadeia; sem as variáveis, não configurado e indisponível')]
    public function testAliasSemConfiguracao(): void
    {
        $validador = self::getContainer()->get(ValidadorDeCadeia::class);

        self::assertInstanceOf(HttpValidadorDeCadeia::class, $validador);
        self::assertFalse($validador->configurado());
        $this->expectException(CadeiaIndisponivel::class);
        $validador->conferir('%PDF-1.7', ValidadorDeCadeia::ANCORA_ICP);
    }

    /**
     * O MockHttpClient dos testes de unidade não segue redirecionamento nem conta tempo: o que prova os 45 s e o "nenhum
     * redirecionamento" (etapa 6, seção 2.1) é o cliente que o container entrega ao validador, lido por dentro.
     */
    #[TestDox('o validador recebe o cliente assinatura_validador.client: 45 s de timeout e de max_duration, nenhum redirecionamento')]
    public function testClienteNomeado(): void
    {
        $validador = self::getContainer()->get(ValidadorDeCadeia::class);
        self::assertInstanceOf(HttpValidadorDeCadeia::class, $validador);

        $opcoes = self::opcoesDoEscopo(self::propriedade($validador, 'http'));

        self::assertSame(['^https?://'], array_keys($opcoes));
        self::assertEquals(45, $opcoes['^https?://']['timeout']);
        self::assertEquals(45, $opcoes['^https?://']['max_duration']);
        self::assertSame(0, $opcoes['^https?://']['max_redirects']);
    }

    /**
     * Desce pelos decoradores do cliente (UriTemplateHttpClient e outros, pelo `client` do DecoratorTrait) até o
     * ScopingHttpClient do `scoped_clients`, e devolve as opções por escopo dele.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function opcoesDoEscopo(object $cliente): array
    {
        for ($passos = 0; $passos < 10; ++$passos) {
            if ($cliente instanceof ScopingHttpClient) {
                return self::propriedade($cliente, 'defaultOptionsByRegexp');
            }
            $cliente = self::propriedade($cliente, 'client');
        }

        self::fail('O cliente do validador não passa por um ScopingHttpClient.');
    }

    private static function propriedade(object $objeto, string $nome): mixed
    {
        for ($classe = new \ReflectionClass($objeto); $classe !== false; $classe = $classe->getParentClass()) {
            if ($classe->hasProperty($nome)) {
                return $classe->getProperty($nome)->getValue($objeto);
            }
        }

        self::fail(\sprintf('%s não tem a propriedade %s.', $objeto::class, $nome));
    }
}
