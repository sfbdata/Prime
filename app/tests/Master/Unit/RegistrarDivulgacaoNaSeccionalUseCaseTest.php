<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Entity\Auth\User;
use App\Master\DTO\RegistrarDivulgacaoNaSeccionalInput;
use App\Master\Entity\SeccionalOab;
use App\Master\Enum\StatusDaDivulgacao;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\UseCase\RegistrarDivulgacaoNaSeccionalUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Registrar a divulgação numa seccional (spec master-seccionais-oab, "Registro de divulgação"; desenho 05 L902): um
 * dos seis status, a cor da paleta (#rrggbb) e a data não futura (vazia = hoje), gravados como a marca da UF na própria
 * seccional, dentro do escopo de plataforma.
 */
#[CoversClass(RegistrarDivulgacaoNaSeccionalUseCase::class)]
#[CoversClass(RegistrarDivulgacaoNaSeccionalInput::class)]
#[CoversClass(StatusDaDivulgacao::class)]
final class RegistrarDivulgacaoNaSeccionalUseCaseTest extends TestCase
{
    private SeccionalOabRepository&MockObject $seccionais;
    private EscopoDeAuditoriaDePlataforma $escopo;
    private User $master;

    protected function setUp(): void
    {
        $this->seccionais = $this->createMock(SeccionalOabRepository::class);
        $this->seccionais->method('buscarPorUf')->willReturnCallback(static fn (string $uf): ?SeccionalOab => $uf === 'BA' ? new SeccionalOab('BA', 'OAB Seccional Bahia') : null);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->master = (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('sem data vale hoje; grava status, cor (em minúsculas) e data na seccional, dentro do escopo de plataforma')]
    public function testRegistraHoje(): void
    {
        $this->seccionais->expects($this->once())->method('salvar')->with($this->isInstanceOf(SeccionalOab::class), true)
            ->willReturnCallback(fn () => self::assertTrue($this->escopo->estaLigado()));

        $s = $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', ['status' => 'vis', 'cor' => ' #FCA5A5 ']));

        self::assertSame(StatusDaDivulgacao::Reuniao, $s->getMarcaStatus());
        self::assertSame('Reunião ou evento', $s->getMarcaStatus()?->rotulo());
        self::assertSame('#fca5a5', $s->getMarcaCor());
        self::assertSame('2026-10-21', $s->getMarcaEm()?->format('Y-m-d'));
        self::assertFalse($this->escopo->estaLigado());
    }

    #[TestDox('a marca nova troca a anterior inteira (só o estado atual); data no passado é aceita')]
    public function testTrocaAMarca(): void
    {
        $s = $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', ['status' => 'env', 'cor' => '#fde68a', 'data' => '2026-09-30']));
        $s->marcar(StatusDaDivulgacao::Convenio, '#86efac', new \DateTimeImmutable('2026-10-01'));

        self::assertSame(StatusDaDivulgacao::Convenio, $s->getMarcaStatus());
        self::assertSame('#86efac', $s->getMarcaCor());
        self::assertSame('2026-10-01', $s->getMarcaEm()?->format('Y-m-d'));
    }

    #[TestDox('os seis status do desenho, com os rótulos do tooltip do chip')]
    public function testSeisStatus(): void
    {
        self::assertSame(
            ['env' => 'Material enviado', 'vis' => 'Reunião ou evento', 'neg' => 'Em negociação', 'cli' => 'Parceria firmada', 'ret' => 'Retornar depois', 'sem' => 'Convênio feito'],
            array_combine(array_map(static fn (StatusDaDivulgacao $s): string => $s->value, StatusDaDivulgacao::cases()), array_map(static fn (StatusDaDivulgacao $s): string => $s->rotulo(), StatusDaDivulgacao::cases())),
        );
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function recusas(): iterable
    {
        yield 'status desconhecido' => [['status' => 'material', 'cor' => '#fde68a'], SeccionalRecusada::RECUSA_STATUS_DE_DIVULGACAO];
        yield 'sem status' => [['cor' => '#fde68a'], SeccionalRecusada::RECUSA_STATUS_DE_DIVULGACAO];
        yield 'sem cor' => [['status' => 'neg'], SeccionalRecusada::RECUSA_COR_DA_MARCA];
        yield 'cor fora do #rrggbb' => [['status' => 'neg', 'cor' => 'red'], SeccionalRecusada::RECUSA_COR_DA_MARCA];
        yield 'cor com estilo' => [['status' => 'neg', 'cor' => '#fff;background:url(x)'], SeccionalRecusada::RECUSA_COR_DA_MARCA];
        yield 'data no futuro' => [['status' => 'cli', 'cor' => '#fde68a', 'data' => '2026-10-22'], SeccionalRecusada::RECUSA_DATA_NO_FUTURO];
        yield 'data inexistente' => [['status' => 'cli', 'cor' => '#fde68a', 'data' => '2026-02-30'], SeccionalRecusada::RECUSA_DATA_FORMATO];
        yield 'data em outro formato' => [['status' => 'cli', 'cor' => '#fde68a', 'data' => '21/10/2026'], SeccionalRecusada::RECUSA_DATA_FORMATO];
    }

    /** @param array<string, string> $corpo */
    #[DataProvider('recusas')]
    #[TestDox('recusa $_dataName com a mensagem clara e não grava')]
    public function testRecusa(array $corpo, string $mensagem): void
    {
        $this->seccionais->expects($this->never())->method('salvar');

        $this->expectException(SeccionalRecusada::class);
        $this->expectExceptionMessage($mensagem);
        $this->useCase()->executar(RegistrarDivulgacaoNaSeccionalInput::doJson($this->master, 'BA', $corpo));
    }

    #[TestDox('linha ausente: SeccionalNaoEncontrada e nada é gravado')]
    public function testSemLinha(): void
    {
        $this->seccionais->expects($this->never())->method('salvar');

        $this->expectException(SeccionalNaoEncontrada::class);
        $this->useCase()->executar(new RegistrarDivulgacaoNaSeccionalInput($this->master, 'SP', 'env', '#fde68a'));
    }

    private function useCase(): RegistrarDivulgacaoNaSeccionalUseCase
    {
        return new RegistrarDivulgacaoNaSeccionalUseCase($this->seccionais, new MockClock('2026-10-21 15:30:00'), $this->escopo);
    }
}
