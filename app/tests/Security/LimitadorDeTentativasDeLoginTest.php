<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LimitadorDeTentativasDeLogin;
use App\Shared\Contract\ConfiguracaoDaPlataforma;
use App\Tests\Support\ConfiguracaoDaPlataformaFalsa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

#[CoversClass(LimitadorDeTentativasDeLogin::class)]
final class LimitadorDeTentativasDeLoginTest extends TestCase
{
    private InMemoryStorage $armazenamento;

    protected function setUp(): void
    {
        $this->armazenamento = new InMemoryStorage();
    }

    private function limitador(ConfiguracaoDaPlataforma $config): LimitadorDeTentativasDeLogin
    {
        return new LimitadorDeTentativasDeLogin($config, $this->armazenamento, 'segredo-de-teste');
    }

    private function pedido(string $usuario, string $ip = '10.0.0.1'): Request
    {
        $pedido = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $pedido->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $usuario);

        return $pedido;
    }

    /** Quantas tentativas passam, do mesmo usuário e IP, antes do bloqueio (teto de 200 só por segurança). */
    private function aceitas(LimitadorDeTentativasDeLogin $limitador, Request $pedido): int
    {
        $n = 0;
        while ($n < 200 && $limitador->consume($pedido)->isAccepted()) {
            ++$n;
        }

        return $n;
    }

    #[TestDox('Sem valor gravado (leitor devolve vazio), o limite é 5: a 6ª tentativa é barrada')]
    public function testPadraoCinco(): void
    {
        self::assertSame(5, $this->aceitas($this->limitador(new ConfiguracaoDaPlataformaFalsa()), $this->pedido('a@adv.com')));
    }

    public function testSegredoVazioNaoDerrubaOLogin(): void
    {
        $limitador = new LimitadorDeTentativasDeLogin(new ConfiguracaoDaPlataformaFalsa(), $this->armazenamento, '');

        self::assertSame(5, $this->aceitas($limitador, $this->pedido('a@adv.com')));
    }

    #[TestDox('Com tentativas = 3, a 4ª é barrada')]
    public function testTresBloqueiaNaQuarta(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa(['tentativas' => '3']);

        self::assertSame(3, $this->aceitas($this->limitador($config), $this->pedido('a@adv.com')));
    }

    #[TestDox('Com tentativas = 10, a 6ª passa e a 11ª é barrada')]
    public function testDezDeixaASexta(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa(['tentativas' => '10']);

        self::assertSame(10, $this->aceitas($this->limitador($config), $this->pedido('a@adv.com')));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidos(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negativo' => ['-3'];
        yield 'acima do teto' => ['51'];
        yield 'não numérico' => ['abc'];
        yield 'decimal' => ['2.5'];
        yield 'vazio' => [''];
        yield 'enorme' => ['99999999999999999999'];
    }

    #[DataProvider('invalidos')]
    #[TestDox('Valor inválido "$valor" cai em 5')]
    public function testInvalidoCaiEmCinco(string $valor): void
    {
        $limitador = $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => $valor]));

        self::assertSame(5, $limitador->limiteVigente());
        self::assertSame(5, $this->aceitas($limitador, $this->pedido('a@adv.com')));
    }

    #[TestDox('Limites nas bordas (1 e 50) valem')]
    public function testBordas(): void
    {
        self::assertSame(1, $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '1']))->limiteVigente());
        self::assertSame(50, $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '50']))->limiteVigente());
    }

    #[TestDox('Leitor que lança cai em 5 e o limitador não lança')]
    public function testLeitorQueLancaCaiEmCinco(): void
    {
        $leitor = new class () implements ConfiguracaoDaPlataforma {
            public function valor(string $chave): string
            {
                throw new \RuntimeException('banco fora');
            }
        };

        self::assertSame(5, $this->aceitas($this->limitador($leitor), $this->pedido('a@adv.com')));
    }

    #[TestDox('O limite por IP é 5x: com n = 3, o mesmo IP passa 15 tentativas em usuários diferentes e a 16ª é barrada')]
    public function testLimitePorIpECincoVezes(): void
    {
        $limitador = $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '3']));

        $aceitas = 0;
        for ($i = 0; $i < 40; ++$i) {
            if ($limitador->consume($this->pedido('u' . $i . '@adv.com', '10.9.9.9'))->isAccepted()) {
                ++$aceitas;
            }
        }

        self::assertSame(15, $aceitas);
        // Outro IP não é afetado.
        self::assertTrue($limitador->consume($this->pedido('u0@adv.com', '10.8.8.8'))->isAccepted());
    }

    #[TestDox('reset zera só o contador usuário+IP: outro usuário do mesmo IP e o limite por IP seguem contados')]
    public function testResetZeraSoUsuarioEIp(): void
    {
        $limitador = $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '2']));
        $ana = $this->pedido('ana@adv.com');
        $bia = $this->pedido('bia@adv.com');

        $limitador->consume($ana);
        $limitador->consume($ana);
        self::assertFalse($limitador->consume($ana)->isAccepted(), 'ana esgotou');
        $limitador->consume($bia);

        $limitador->reset($ana);

        // Ana volta a ter 2 (o IP já gastou 4 dos 10: sobrevive ao reset, então ainda sobram 6).
        self::assertTrue($limitador->consume($ana)->isAccepted());
        self::assertTrue($limitador->consume($ana)->isAccepted());
        self::assertFalse($limitador->consume($ana)->isAccepted());
        // Bia (1 gasta) não foi zerada: sobra 1.
        self::assertTrue($limitador->consume($bia)->isAccepted());
        self::assertFalse($limitador->consume($bia)->isAccepted());
    }

    #[TestDox('O reset não devolve as tentativas do IP: o limite por IP continua gasto')]
    public function testResetPreservaOContadorDoIp(): void
    {
        $limitador = $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '1']));
        // Limite do IP = 5. Cinco usuários diferentes gastam o IP todo.
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($limitador->consume($this->pedido('x' . $i . '@adv.com'))->isAccepted());
        }
        $limitador->reset($this->pedido('x0@adv.com'));

        self::assertFalse($limitador->consume($this->pedido('x0@adv.com'))->isAccepted(), 'o IP segue esgotado');
    }

    #[TestDox('peek não gasta tentativa: depois de n consumos, o peek já vem sem fichas')]
    public function testPeekNaoConsome(): void
    {
        $limitador = $this->limitador(new ConfiguracaoDaPlataformaFalsa(['tentativas' => '2']));
        $pedido = $this->pedido('a@adv.com');

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(2, $limitador->peek($pedido)->getRemainingTokens());
        }
        $limitador->consume($pedido);
        $limitador->consume($pedido);

        self::assertSame(0, $limitador->peek($pedido)->getRemainingTokens());
    }

    #[TestDox('Mudar a configuração vale na chamada seguinte para janelas novas; a janela já aberta termina com o limite antigo')]
    public function testMudancaValeParaJanelasNovas(): void
    {
        $config = new ConfiguracaoDaPlataformaFalsa(['tentativas' => '10']);
        $limitador = $this->limitador($config);
        $limitador->consume($this->pedido('antigo@adv.com'));

        $config->definir('tentativas', '2');

        // Usuário novo: janela nova, já com o limite 2.
        self::assertSame(2, $this->aceitas($limitador, $this->pedido('novo@adv.com')));
        // Janela aberta antes da mudança: segue com o limite com que nasceu (10), 1 já gasto.
        self::assertSame(9, $this->aceitas($limitador, $this->pedido('antigo@adv.com')));
    }
}
