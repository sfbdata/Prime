<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Produto\Enum\Produto;
use App\Produto\EventListener\GuardaDoProduto;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Zenstruck\Foundry\Test\Factories;

/**
 * `/assinar/{token}` de outro escritório não pode dar 404 a quem está logado num escritório só Voto (spec
 * multiproduto, §6.1): a página do signatário é pública, e a guarda não age nela.
 */
#[CoversClass(GuardaDoProduto::class)]
final class GuardaDoProdutoNaAssinaturaPublicaTest extends JusPrimeWebTestCase
{
    use Factories;
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;
    use ComEscritoriosPorProduto;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->csrfPrevisivel();
        $this->modo('bloqueio');
    }

    #[TestDox('bloqueio: o link de assinatura de outro escritório abre para quem está logado num escritório só Voto')]
    public function testAssinaturaDeOutroEscritorioPassa(): void
    {
        $this->entrar(true);
        [, $tokens] = $this->documentoEnviado();
        $this->sair($this->client);
        $soVoto = $this->escritorio(Produto::Voto);
        $this->logarComTenant($this->client, $this->primario($soVoto), $soVoto);

        $this->client->request('GET', '/assinar/' . $tokens[0]);

        self::assertResponseIsSuccessful();
    }
}
