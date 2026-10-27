<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\RegistrarPublicacaoDoDiarioInput;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Djen\Exception\PublicacaoDoDiarioInvalidaException;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Djen\UseCase\RegistrarPublicacaoDoDiarioUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegistrarPublicacaoDoDiarioUseCase::class)]
#[Group('djen')]
final class RegistrarPublicacaoDoDiarioUseCaseTest extends TestCase
{
    private function input(array $troca = []): RegistrarPublicacaoDoDiarioInput
    {
        $v = $troca + ['data' => '2026-10-20', 'seccional' => 'OAB/DF', 'tipo' => 'etico', 'ato' => 'Notificação', 'numero' => 'PED 1', 'orgao' => 'TED', 'prazo' => '15', 'texto' => 'Texto'];

        return new RegistrarPublicacaoDoDiarioInput($v['data'], $v['seccional'], $v['tipo'], $v['ato'], $v['numero'], $v['orgao'], $v['prazo'], $v['texto']);
    }

    private function executar(RegistrarPublicacaoDoDiarioInput $input, ?PublicacaoDoDiarioDaOabRepository $repo = null)
    {
        $uc = new RegistrarPublicacaoDoDiarioUseCase($repo ?? $this->createMock(PublicacaoDoDiarioDaOabRepository::class));

        return $uc->executar(new Tenant(), new User(), $input, new \DateTimeImmutable('2026-10-27 10:00'));
    }

    #[Test]
    public function registraEGuarda(): void
    {
        $repo = $this->createMock(PublicacaoDoDiarioDaOabRepository::class);
        $repo->expects(self::once())->method('salvar');

        $p = $this->executar($this->input(), $repo);

        self::assertSame(TipoDeAtoDoDiario::EticoDisciplinar, $p->getTipo());
        self::assertSame(15, $p->getPrazoDias());
        self::assertSame('2026-10-20', $p->getDataPublicacao()->format('Y-m-d'));
    }

    #[Test]
    public function processoEPrazoSaoOpcionais(): void
    {
        $p = $this->executar($this->input(['numero' => '', 'prazo' => '']));

        self::assertNull($p->getNumeroProcesso());
        self::assertNull($p->getPrazoDias());
    }

    /** @return iterable<string, array{array<string,string>, string}> */
    public static function invalidos(): iterable
    {
        yield 'sem ato' => [['ato' => ''], 'Preencha: ato.'];
        yield 'sem data e texto' => [['data' => '', 'texto' => ''], 'Preencha: data, texto.'];
        yield 'data futura' => [['data' => '2026-10-28'], 'futura'];
        yield 'data inexistente' => [['data' => '2026-02-31'], 'inválida'];
        yield 'tipo desconhecido' => [['tipo' => 'x'], 'Tipo'];
        yield 'prazo zero' => [['prazo' => '0'], 'razo'];
        yield 'prazo texto' => [['prazo' => 'abc'], 'razo'];
        yield 'ato longo' => [['ato' => str_repeat('a', 256)], '255'];
    }

    /** @param array<string,string> $troca */
    #[Test]
    #[DataProvider('invalidos')]
    public function recusaDadoInvalido(array $troca, string $trecho): void
    {
        $this->expectException(PublicacaoDoDiarioInvalidaException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($trecho, '/') . '/');

        $this->executar($this->input($troca));
    }
}
