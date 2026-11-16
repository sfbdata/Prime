<?php

declare(strict_types=1);

namespace App\Tests\Conector\Functional;

use App\Entity\Auth\User;
use App\Security\EntradaDoUsuario;
use App\Tests\Conector\Support\CenarioDaAutorizacaoTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * O app de I.A. manda a pessoa ao `/oauth/authorize`; sem sessão, ela faz login e tem de VOLTAR à tela de autorização
 * com o pedido inteiro, também quando tem vários escritórios (spec mcp-remoto-oauth 3.3; decisão 3 de 16/11). Essa
 * volta não passa pelo véu `/login/entrando`: sem escritório na sessão, o véu é desviado para a seleção. Para o resto do
 * sistema nada muda: o login passa pelo véu e, com vários escritórios, a seleção vem antes.
 */
#[CoversClass(EntradaDoUsuario::class)]
final class VoltaAoAutorizarDepoisDoLoginTest extends JusPrimeWebTestCase
{
    use CenarioDaAutorizacaoTrait;

    private const SENHA = 'senha-de-teste-123';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    private function pessoaComEscritorios(int $quantos): User
    {
        $pessoa = $this->pessoa();
        $pessoa->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($pessoa, self::SENHA));
        for ($i = 0; $i < $quantos; ++$i) {
            $tenant = $this->escritorio('Volta ' . $i);
            $this->vinculo($pessoa, $tenant);
            $this->liberarConector($tenant);
        }
        $this->em()->flush();

        return $pessoa;
    }

    /** Faz o login pelo formulário e devolve o `Location` da resposta. */
    private function entrar(User $pessoa): string
    {
        $crawler = $this->client->request('GET', '/login');
        $this->marcarTermosAceitos($this->client);
        $form = $crawler->selectButton('Entrar')->form();
        $form['email'] = (string) $pessoa->getEmail();
        $form['password'] = self::SENHA;
        $this->client->submit($form);

        self::assertResponseRedirects();

        return (string) $this->client->getResponse()->headers->get('Location');
    }

    /** O `?para=` do salto para o véu. */
    private static function paraDoVeu(string $local): string
    {
        self::assertSame('/login/entrando', parse_url($local, \PHP_URL_PATH), 'o login passa pelo véu');
        parse_str((string) parse_url($local, \PHP_URL_QUERY), $query);

        return (string) ($query['para'] ?? '');
    }

    /** O destino é o authorize com o pedido inteiro (a ordem da query pode mudar; os parâmetros, não). */
    private function exigirAutorizacao(string $destino): void
    {
        self::assertSame('/oauth/authorize', parse_url($destino, \PHP_URL_PATH), 'destino: ' . $destino);
        parse_str((string) parse_url($destino, \PHP_URL_QUERY), $query);
        $esperado = $this->pedidoValido();
        ksort($query);
        ksort($esperado);
        self::assertSame($esperado, $query);
    }

    #[TestDox('com dois escritórios, o login volta direto ao /oauth/authorize com o pedido inteiro (não à seleção), e a tela abre')]
    public function testDoisEscritoriosVoltamAoAutorizar(): void
    {
        $this->clienteValido();
        $pessoa = $this->pessoaComEscritorios(2);
        $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->pedidoValido()));
        self::assertResponseRedirects('/login');

        $destino = $this->entrar($pessoa);
        $this->exigirAutorizacao($destino);

        $tela = $this->client->request('GET', $destino);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $tela->filter('input[name="autorizar_conexao[tenantId]"]:not([disabled])'));
    }

    #[TestDox('com um escritório, o login também volta ao /oauth/authorize, já com o escritório na sessão')]
    public function testUmEscritorioVoltaAoAutorizar(): void
    {
        $this->clienteValido();
        $pessoa = $this->pessoaComEscritorios(1);
        $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->pedidoValido()));

        $this->exigirAutorizacao($this->entrar($pessoa));
        self::assertNotNull($this->client->getRequest()->getSession()->get('current_tenant_id'));
    }

    #[TestDox('navegador comum inalterado: com dois escritórios e outra tela pedida, o login passa pelo véu e vai à seleção')]
    public function testOutraTelaContinuaIndoASelecao(): void
    {
        $pessoa = $this->pessoaComEscritorios(2);
        $this->client->request('GET', '/agenda');
        self::assertResponseRedirects('/login');

        self::assertSame('/escritorio/selecionar', self::paraDoVeu($this->entrar($pessoa)));
    }

    #[TestDox('navegador comum inalterado: com um escritório, o login passa pelo véu e volta à tela pedida')]
    public function testUmEscritorioOutraTelaPassaPeloVeu(): void
    {
        $pessoa = $this->pessoaComEscritorios(1);
        $this->client->request('GET', '/agenda');

        self::assertSame('/agenda', self::paraDoVeu($this->entrar($pessoa)));
    }
}
