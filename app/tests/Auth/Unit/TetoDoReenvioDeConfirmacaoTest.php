<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Auth\Service\TetoDoReenvioDeConfirmacao;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Os tetos do reenvio do link de confirmação, extraídos do `ContaConfirmacaoController` sem mudar a regra: 5 links em
 * 24 h (o diário vale primeiro) e um a cada 2 minutos. A contagem em si se prova no functional das duas rotas.
 */
#[CoversClass(TetoDoReenvioDeConfirmacao::class)]
final class TetoDoReenvioDeConfirmacaoTest extends TestCase
{
    private const AGORA = '2026-10-22 10:00:00';

    private ConfirmacaoEmailRepository&MockObject $repositorio;
    private User $user;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(ConfirmacaoEmailRepository::class);
        $this->user = (new User())->setEmail('ana@adv.com');
    }

    private function teto(): TetoDoReenvioDeConfirmacao
    {
        return new TetoDoReenvioDeConfirmacao($this->repositorio, new MockClock(new \DateTimeImmutable(self::AGORA)));
    }

    #[TestDox('Sem emissão recente: pode reenviar; as janelas são de 24 h e de 2 minutos atrás')]
    public function testPodeReenviar(): void
    {
        $this->repositorio->expects(self::once())->method('contarEmissoesDesde')
            ->with($this->user, new \DateTimeImmutable('2026-10-21 10:00:00'))->willReturn(TetoDoReenvioDeConfirmacao::TETO_DIARIO - 1);
        $this->repositorio->expects(self::once())->method('houveEmissaoDesde')
            ->with($this->user, new \DateTimeImmutable('2026-10-22 09:58:00'))->willReturn(false);

        self::assertNull($this->teto()->recusa($this->user));
    }

    #[TestDox('Cinco links nas últimas 24 h: recusa pelo teto diário, sem olhar o de 2 minutos')]
    public function testTetoDiario(): void
    {
        $this->repositorio->method('contarEmissoesDesde')->willReturn(TetoDoReenvioDeConfirmacao::TETO_DIARIO);
        $this->repositorio->expects(self::never())->method('houveEmissaoDesde');

        self::assertSame(TetoDoReenvioDeConfirmacao::TETO_DIARIO_ATINGIDO, $this->teto()->recusa($this->user));
    }

    #[TestDox('Link emitido há menos de 2 minutos: pede para aguardar')]
    public function testIntervalo(): void
    {
        $this->repositorio->method('contarEmissoesDesde')->willReturn(1);
        $this->repositorio->method('houveEmissaoDesde')->willReturn(true);

        self::assertSame(TetoDoReenvioDeConfirmacao::AGUARDE, $this->teto()->recusa($this->user));
    }
}
