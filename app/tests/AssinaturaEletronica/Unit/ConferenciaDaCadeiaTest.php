<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Enum\NivelDeAssinatura;
use App\AssinaturaEletronica\Service\Cadeia\CadeiaIndisponivel;
use App\AssinaturaEletronica\Service\Cadeia\ConferenciaDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;
use App\Tests\AssinaturaEletronica\Support\CadeiaDeExemplo;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A conferência da cadeia (etapa 6, bloco 6B): a recusa do motor antes do validador, o limitador da seção 2.1 e a
 * âncora pedida por nível.
 */
#[CoversClass(ConferenciaDaCadeia::class)]
#[Group('assinatura')]
final class ConferenciaDaCadeiaTest extends TestCase
{
    private const PDF = "%PDF-1.7\nassinado\n%%EOF";

    private TestHandler $log;

    /** @var list<string> as âncoras pedidas ao validador, em ordem */
    private array $chamadas = [];

    protected function setUp(): void
    {
        $this->log = new TestHandler();
        $this->chamadas = [];
    }

    #[TestDox('ICP pede a âncora icp; gov.br pede qualquer; o desfecho vem da regra')]
    public function testAncoraPorNivel(): void
    {
        $conferencia = $this->conferencia();
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);

        self::assertTrue($conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(7))->confirmou());
        self::assertTrue($conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Govbr, ConferenciaDaCadeia::quemSignatario(8))->confirmou());
        self::assertSame(['icp', 'qualquer'], $this->chamadas);
    }

    #[TestDox('recusa do motor: não chama o validador, não gasta o limite e não tem cadeia para gravar')]
    public function testRecusaDoMotorNaoChama(): void
    {
        $conferencia = $this->conferencia(limite: 1);
        $semNova = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()], novas: 0);

        foreach (range(1, 3) as $_) {
            $decisao = $conferencia->conferir(self::PDF, $semNova, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(7));
            self::assertSame(RegraDaCadeia::SEM_ASSINATURA_NOVA, $decisao->motivo);
            self::assertFalse($decisao->temCadeia());
        }
        self::assertSame([], $this->chamadas);

        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);
        self::assertTrue($conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(7))->confirmou(), 'o limite segue inteiro');
    }

    #[TestDox('passou do limite: CadeiaIndisponivel sem chamar o validador; o aviso no log não leva o IP')]
    public function testLimite(): void
    {
        $conferencia = $this->conferencia(limite: 2);
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);
        $quem = ConferenciaDaCadeia::quemIp('203.0.113.9');
        $conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, $quem);
        $conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, $quem);

        try {
            $conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, $quem);
            self::fail('Devia lançar CadeiaIndisponivel.');
        } catch (CadeiaIndisponivel) {
        }

        self::assertCount(2, $this->chamadas);
        self::assertTrue($this->log->hasWarningThatContains('limite de conferências'));
        foreach ($this->log->getRecords() as $registro) {
            self::assertStringNotContainsString('203.0.113.9', $registro->message . json_encode($registro->context));
            self::assertSame(['tipo' => 'ip'], $registro->context);
        }
    }

    #[TestDox('o limite conta por chave: outro signatário segue conferindo')]
    public function testLimitePorChave(): void
    {
        $conferencia = $this->conferencia(limite: 1);
        $motor = CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]);
        $conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(7));

        self::assertTrue($conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(8))->confirmou());
        $this->expectException(CadeiaIndisponivel::class);
        $conferencia->conferir(self::PDF, $motor, NivelDeAssinatura::Icp, ConferenciaDaCadeia::quemSignatario(7));
    }

    #[TestDox('nível sem validador: LogicException antes de qualquer coisa')]
    public function testNivelSemValidador(): void
    {
        $this->expectException(\LogicException::class);
        $this->conferencia()->conferir(self::PDF, CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]), NivelDeAssinatura::Simples, ConferenciaDaCadeia::quemSignatario(7));
    }

    #[TestDox('validador indisponível: a CadeiaIndisponivel sobe para quem chamou')]
    public function testValidadorIndisponivel(): void
    {
        $this->expectException(CadeiaIndisponivel::class);
        $this->conferencia(indisponivel: true)->conferir(self::PDF, CadeiaDeExemplo::motor([CadeiaDeExemplo::assinaturaDoMotor()]), NivelDeAssinatura::Govbr, ConferenciaDaCadeia::quemSignatario(7));
    }

    private function conferencia(int $limite = 6, bool $indisponivel = false): ConferenciaDaCadeia
    {
        $validador = new class (\Closure::fromCallable([$this, 'anotar']), $indisponivel) implements ValidadorDeCadeia {
            /** @param \Closure(string): void $anotar */
            public function __construct(private readonly \Closure $anotar, private readonly bool $indisponivel)
            {
            }

            public function configurado(): bool
            {
                return true;
            }

            public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
            {
                ($this->anotar)($ancora);
                if ($this->indisponivel) {
                    throw new CadeiaIndisponivel('Fora do ar.');
                }

                return CadeiaDeExemplo::resultado([CadeiaDeExemplo::item()]);
            }
        };
        $limitador = new RateLimiterFactory(
            ['id' => 'assinatura_validador_conferencia', 'policy' => 'sliding_window', 'limit' => $limite, 'interval' => '10 minutes'],
            new InMemoryStorage(),
        );

        return new ConferenciaDaCadeia($validador, new RegraDaCadeia(), $limitador, new Logger('teste', [$this->log]));
    }

    private function anotar(string $ancora): void
    {
        $this->chamadas[] = $ancora;
    }
}
