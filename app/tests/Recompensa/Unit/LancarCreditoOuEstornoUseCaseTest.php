<?php

declare(strict_types=1);

namespace App\Tests\Recompensa\Unit;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Recompensa\DTO\LancarCreditoOuEstornoInput;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Recompensa\Exception\IndicadorNaoEncontrado;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\LancamentoDeRecompensaRepository;
use App\Recompensa\UseCase\LancarCreditoOuEstornoUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use App\Tests\Indicacao\Support\Pessoas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Validator\Validation;

#[CoversClass(LancarCreditoOuEstornoUseCase::class)]
#[CoversClass(LancarCreditoOuEstornoInput::class)]
final class LancarCreditoOuEstornoUseCaseTest extends TestCase
{
    private const CHAVE = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;
    /** @var array<string, LancamentoDeRecompensa> */
    private array $gravados = [];
    /** @var list<string> */
    private array $eventos = [];
    private bool $escopoNaGravacao = false;

    protected function setUp(): void
    {
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = Pessoas::master();
        $this->gravados = [];
        $this->eventos = [];
    }

    private function caso(int $indicacoes = 3): LancarCreditoOuEstornoUseCase
    {
        $repo = $this->createStub(LancamentoDeRecompensaRepository::class);
        $repo->method('travarChave')->willReturnCallback(function (string $chave): void {
            $this->eventos[] = 'trava';
        });
        $repo->method('buscarPorChave')->willReturnCallback(fn (string $chave): ?LancamentoDeRecompensa => $this->gravados[$chave] ?? null);
        $repo->method('salvar')->willReturnCallback(function (LancamentoDeRecompensa $l): void {
            $this->eventos[] = 'salva';
            $this->escopoNaGravacao = $this->escopo->estaLigado();
            $this->gravados[$l->getChaveDeIdempotencia()] = $l;
        });
        $indicador = $this->createStub(IndicacaoRepository::class);
        $indicador->method('contarDoUsuario')->willReturn($indicacoes);
        // A mesma trava por usuário da baixa: um estorno não passa entre a leitura do saldo e o pagamento.
        $indicador->method('emTransacaoTravada')->willReturnCallback(function (int $userId, \Closure $trabalho): mixed {
            $this->eventos[] = 'trava-do-usuario';

            return $trabalho();
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(static fn (string $classe, int $id): ?User => $classe === User::class ? Pessoas::usuario($id) : null);

        return new LancarCreditoOuEstornoUseCase($repo, $indicador, $em, new MockClock(new \DateTimeImmutable('2026-10-26 12:00:00')), $this->escopo);
    }

    private function pedido(int $valor = 1500, string $chave = self::CHAVE, ?string $obs = 'Ajuste', int $userId = 7): LancarCreditoOuEstornoInput
    {
        return new LancarCreditoOuEstornoInput($this->master, $userId, $valor, $obs, $chave);
    }

    #[TestDox('crédito: grava um lançamento bonus com sinal, autor e data, travando a chave antes e no escopo de plataforma')]
    public function testCredito(): void
    {
        $this->caso()->executar($this->pedido(1500));

        self::assertCount(1, $this->gravados);
        $l = $this->gravados[self::CHAVE];
        self::assertSame(1500, $l->getValorCentavos());
        self::assertSame('bonus', $l->getTipo());
        self::assertSame(7, $l->getUser()?->getId());
        self::assertSame($this->master, $l->getCriadoPor());
        self::assertSame('2026-10-26 12:00:00', $l->getCriadoEm()->format('Y-m-d H:i:s'));
        self::assertSame(['trava-do-usuario', 'trava', 'salva'], $this->eventos);
        self::assertTrue($this->escopoNaGravacao);
    }

    #[TestDox('estorno: o valor negativo é gravado com o sinal')]
    public function testEstorno(): void
    {
        $this->caso()->executar($this->pedido(-400));

        self::assertSame(-400, $this->gravados[self::CHAVE]->getValorCentavos());
    }

    #[TestDox('idempotência: repetir a mesma chave não lança de novo e termina sem erro')]
    public function testIdempotente(): void
    {
        $caso = $this->caso();
        $caso->executar($this->pedido(1500));
        $caso->executar($this->pedido(1500));
        $caso->executar($this->pedido(1500, obs: 'outra observação'));

        self::assertCount(1, $this->gravados);
        self::assertSame(['trava-do-usuario', 'trava', 'salva', 'trava-do-usuario', 'trava', 'trava-do-usuario', 'trava'], $this->eventos);
    }

    #[TestDox('a mesma chave com outro valor ou outro indicador é recusada e nada novo é gravado')]
    public function testChaveReusadaComOutroConteudo(): void
    {
        $caso = $this->caso();
        $caso->executar($this->pedido(1500));

        foreach ([$this->pedido(1600), $this->pedido(1500, userId: 8)] as $i => $pedido) {
            try {
                $caso->executar($pedido);
                self::fail('devia recusar o caso ' . $i);
            } catch (RecompensaRecusada) {
                self::assertCount(1, $this->gravados, 'caso ' . $i);
            }
        }
    }

    #[TestDox('quem nunca indicou: IndicadorNaoEncontrado e nada é gravado')]
    public function testNuncaIndicou(): void
    {
        $this->expectException(IndicadorNaoEncontrado::class);
        try {
            $this->caso(0)->executar($this->pedido());
        } finally {
            self::assertSame([], $this->gravados);
        }
    }

    #[TestDox('valor zero ou acima do teto na entidade recusa antes de gravar')]
    public function testValorRecusadoNaEntidade(): void
    {
        foreach ([0, 10000001, -10000001] as $valor) {
            try {
                $this->caso()->executar($this->pedido($valor));
                self::fail('devia recusar ' . $valor);
            } catch (RecompensaRecusada) {
                self::assertSame([], $this->gravados);
            }
        }
    }

    #[TestDox('doJson lê o valor com sinal; a validação recusa chave curta ou com símbolo e observação longa')]
    public function testEntrada(): void
    {
        $input = LancarCreditoOuEstornoInput::doJson($this->master, 7, ['valor' => '-10,50', 'observacao' => '  erro de digitação ', 'chave' => self::CHAVE]);
        $validador = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        self::assertSame(-1050, $input->valorCentavos);
        self::assertSame('erro de digitação', $input->observacao);
        self::assertCount(0, $validador->validate($input));
        foreach ([['chave' => ''], ['chave' => 'curta'], ['chave' => str_repeat('a', 65)], ['chave' => 'aaaaaaaaaaaaaaaa; DROP'], ['observacao' => str_repeat('a', 256)]] as $i => $troca) {
            $ruim = LancarCreditoOuEstornoInput::doJson($this->master, 7, $troca + ['valor' => '5', 'chave' => self::CHAVE]);
            self::assertGreaterThan(0, count($validador->validate($ruim)), 'caso ' . $i);
        }
    }
}
