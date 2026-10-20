<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Entity\Auth\User;
use App\Indicacao\Entity\Indicacao;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\UseCase\AtribuirCadastroPeloLinkUseCase;
use App\Repository\UserRepository;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** A conta criada pelo link curto ligada a quem indicou (spec indicar-bluejus.md, comportamento 7). */
#[CoversClass(AtribuirCadastroPeloLinkUseCase::class)]
#[CoversClass(Indicacao::class)]
final class AtribuirCadastroPeloLinkUseCaseTest extends TestCase
{
    private const CODIGO = 'ABCD2345';

    private User $ana;
    private User $nova;
    /** @var list<Indicacao> */
    private array $gravadas = [];
    private bool $jaAtribuida = false;
    private bool $consultouLimite = false;

    protected function setUp(): void
    {
        $this->ana = Pessoas::usuario(7);
        $this->ana->setIsActive(true);
        $this->nova = Pessoas::usuario(50, 'Nova Pessoa', null);
        $this->nova->setIsActive(true);
    }

    private function useCase(): AtribuirCadastroPeloLinkUseCase
    {
        $codigos = $this->createStub(IndicacaoCodigoRepository::class);
        $codigos->method('donoDoCodigo')->willReturnCallback(fn (string $c): ?User => $c === self::CODIGO ? $this->ana : null);

        $indicacoes = $this->createStub(IndicacaoRepository::class);
        $indicacoes->method('contaJaAtribuida')->willReturnCallback(fn (): bool => $this->jaAtribuida || $this->gravadas !== []);
        $indicacoes->method('gravarAtribuicao')->willReturnCallback(function (Indicacao $i): bool {
            $this->gravadas[] = $i;

            return true;
        });
        $indicacoes->method('contarDoUsuarioDesde')->willReturnCallback(function (): int {
            $this->consultouLimite = true;

            return 999;
        });

        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('find')->willReturnCallback(fn (mixed $id): ?User => match ($id) {
            7 => $this->ana,
            50 => $this->nova,
            default => null,
        });

        return new AtribuirCadastroPeloLinkUseCase($codigos, $indicacoes, $usuarios, new MockClock(new \DateTimeImmutable('2026-10-20 10:00:00')));
    }

    #[TestDox('código válido de pessoa ativa: liga a conta nova como indicação de canal link, sem e-mail nem nome nem escritório')]
    public function testCodigoValidoLiga(): void
    {
        self::assertTrue($this->useCase()->executar(' abcd2345 ', 50));

        self::assertCount(1, $this->gravadas);
        $i = $this->gravadas[0];
        self::assertSame($this->ana, $i->getIndicadoPor());
        self::assertSame($this->nova, $i->getIndicadoUser());
        self::assertSame(CanalDeIndicacao::Link, $i->getCanal());
        self::assertNull($i->getEmail());
        self::assertNull($i->getNome());
        self::assertNull($i->getTenantOrigem());
        self::assertSame('2026-10-20 10:00:00', $i->getCriadaEm()->format('Y-m-d H:i:s'));
    }

    /** @return iterable<string, array{?string}> */
    public static function codigosQueNaoValem(): iterable
    {
        yield 'sem código (chave nula: veio de /i/ inexistente)' => [null];
        yield 'vazio' => [''];
        yield 'malformado' => ['ABC-123'];
        yield 'inexistente' => ['ZZZZ9999'];
    }

    #[DataProvider('codigosQueNaoValem')]
    #[TestDox('código ausente, malformado ou inexistente: não liga')]
    public function testCodigoInvalidoNaoLiga(?string $codigo): void
    {
        self::assertFalse($this->useCase()->executar($codigo, 50));
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('dono do código inativo: não liga')]
    public function testDonoInativoNaoLiga(): void
    {
        $this->ana->setIsActive(false);

        self::assertFalse($this->useCase()->executar(self::CODIGO, 50));
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('autoindicação (o dono do código é a própria conta nova): não liga')]
    public function testAutoindicacaoNaoLiga(): void
    {
        self::assertFalse($this->useCase()->executar(self::CODIGO, 7));
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('conta nova que não existe: não liga')]
    public function testContaNovaInexistente(): void
    {
        self::assertFalse($this->useCase()->executar(self::CODIGO, 404));
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('repetido: a mesma conta nova é atribuída uma vez só')]
    public function testRepetidoNaoDuplica(): void
    {
        $useCase = $this->useCase();

        self::assertTrue($useCase->executar(self::CODIGO, 50));
        self::assertFalse($useCase->executar(self::CODIGO, 50));
        self::assertCount(1, $this->gravadas);
    }

    #[TestDox('conta já atribuída antes (por outro caminho ou corrida): não liga')]
    public function testJaAtribuida(): void
    {
        $this->jaAtribuida = true;

        self::assertFalse($this->useCase()->executar(self::CODIGO, 50));
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('pausa e limite não impedem: atribuir não é envio e nem consulta a contagem')]
    public function testPausadoENoLimiteLiga(): void
    {
        // A pausa e os limites nem entram no caso de uso; a contagem dublada estourada provaria a trava se fosse lida.
        self::assertTrue($this->useCase()->executar(self::CODIGO, 50));
        self::assertFalse($this->consultouLimite);
        self::assertCount(1, $this->gravadas);
    }

    #[TestDox('a entidade recusa a autoindicação')]
    public function testEntidadeRecusaAutoindicacao(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Indicacao::porCadastroDoLink($this->ana, null, $this->ana, new \DateTimeImmutable());
    }
}
