<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Fluxo\FluxoDoChatDaCentral;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Service\InterpretadorDeRespostaDoChat;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(FluxoDoChatDaCentral::class)]
final class FluxoDoChatDaCentralTest extends TestCase
{
    /** @var list<array{string, int, string}> */
    private array $gravadas = [];

    private function tenant(): Tenant
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 7);

        return $tenant;
    }

    private function usuario(): User
    {
        $user = new User();
        DefineId::em($user, 3);

        return $user;
    }

    private function analise(Tenant $tenant, ?User $solicitante, array $resumo = ['mensagemId' => 55], string $alvoTipo = AnaliseDeInteligencia::ALVO_CONVERSA_IA): AnaliseDeInteligencia
    {
        return new AnaliseDeInteligencia($tenant, $solicitante, TipoDeAnalise::ChatDaCentral, $alvoTipo, 9, PromptDoChatDaCentral::VERSAO, 'h', $resumo);
    }

    /** @param array<string, mixed>|null $contexto */
    private function fluxo(?array $contexto): FluxoDoChatDaCentral
    {
        $central = new class ($contexto, $this) implements ConversaDaIaNaCentral {
            public function __construct(private readonly ?array $contexto, private readonly FluxoDoChatDaCentralTest $teste)
            {
            }

            public function contextoDaPergunta(Tenant $tenant, int $conversaId, int $mensagemId, User $solicitante): ?array
            {
                return $this->contexto;
            }

            public function gravarResposta(Tenant $tenant, int $conversaId, User $dono, string $texto, \DateTimeImmutable $agora): void
            {
                $this->teste->registrar($texto, $conversaId);
            }

            public function apagarMensagensDaIaAntesDe(\DateTimeImmutable $limite): int
            {
                return 0;
            }
        };
        $montador = (new \ReflectionClass(MontadorDeContextoDoPush::class))->newInstanceWithoutConstructor();

        return new FluxoDoChatDaCentral($central, new PromptDoChatDaCentral(new MockClock('2026-10-27 10:00:00')), new InterpretadorDeRespostaDoChat(), $montador, new MockClock('2026-10-27 10:00:00'));
    }

    public function registrar(string $texto, int $conversaId): void
    {
        $this->gravadas[] = ['', $conversaId, $texto];
    }

    #[TestDox('tipo é chat_central')]
    public function testTipo(): void
    {
        self::assertSame(TipoDeAnalise::ChatDaCentral, $this->fluxo(null)->tipo());
    }

    #[TestDox('contexto null da Central: \LogicException (o handler marca falhou sem chamar o provedor)')]
    public function testContextoNull(): void
    {
        $this->expectException(\LogicException::class);

        $this->fluxo(null)->preparar($this->analise($this->tenant(), $this->usuario()), $this->tenant());
    }

    #[TestDox('sem solicitante, sem mensagemId ou com alvo que não é conversa: \LogicException')]
    public function testAnaliseInconsistente(): void
    {
        $contexto = ['texto' => 'Oi', 'nomeDaPessoa' => 'Ana', 'historico' => []];
        $casos = [
            $this->analise($this->tenant(), null),
            $this->analise($this->tenant(), $this->usuario(), []),
            $this->analise($this->tenant(), $this->usuario(), ['mensagemId' => 55], AnaliseDeInteligencia::ALVO_PASTA),
        ];
        foreach ($casos as $analise) {
            try {
                $this->fluxo($contexto)->preparar($analise, $this->tenant());
                self::fail('devia lançar');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('o aoConcluir grava a resposta e os pontos como linhas "• " na conversa da análise; sem texto de pasta, equipe ou produtividade no sistema')]
    public function testAoConcluir(): void
    {
        $this->fluxo(null)->aoConcluir($this->analise($this->tenant(), $this->usuario()), $this->tenant(), new ResultadoDaAnalise("Resposta\nem duas linhas", [['tipo' => 'info', 'texto' => 'Ponto um'], ['tipo' => 'ok', 'texto' => 'Ponto dois']], null));

        self::assertSame([['', 9, "Resposta\nem duas linhas\n• Ponto um\n• Ponto dois"]], $this->gravadas);
    }
}
