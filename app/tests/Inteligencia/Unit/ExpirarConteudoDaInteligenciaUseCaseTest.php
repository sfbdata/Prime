<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\Entity\ConfiguracaoDaPlataformaDeIa;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\ConfiguracaoDaPlataformaDeIaRepository;
use App\Inteligencia\UseCase\ExpirarConteudoDaInteligenciaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExpirarConteudoDaInteligenciaUseCase::class)]
final class ExpirarConteudoDaInteligenciaUseCaseTest extends TestCase
{
    private function sut(AnaliseDeInteligenciaRepository $analises, ConfiguracaoDaPlataformaDeIa $configuracao, ?ConversaDaIaNaCentral $central = null): ExpirarConteudoDaInteligenciaUseCase
    {
        $plataforma = $this->createStub(ConfiguracaoDaPlataformaDeIaRepository::class);
        $plataforma->method('obter')->willReturn($configuracao);

        return new ExpirarConteudoDaInteligenciaUseCase($analises, $plataforma, $central ?? $this->createStub(ConversaDaIaNaCentral::class));
    }

    #[TestDox('sem escolha do Master: o limite é 12 meses antes de agora e as contagens vêm do repositório')]
    public function testPadraoDeDozeMeses(): void
    {
        $agora = new \DateTimeImmutable('2026-10-24 00:20:00');
        $repositorio = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repositorio->expects(self::once())
            ->method('expirarConteudoAntesDe')
            ->with(new \DateTimeImmutable('2025-10-24 00:20:00'), $agora)
            ->willReturn(['analises' => 3, 'perguntas' => 2]);

        $central = $this->createMock(ConversaDaIaNaCentral::class);
        $central->expects(self::once())
            ->method('apagarMensagensDaIaAntesDe')
            ->with(new \DateTimeImmutable('2025-10-24 00:20:00'))
            ->willReturn(5);

        $resultado = $this->sut($repositorio, new ConfiguracaoDaPlataformaDeIa(), $central)->executar($agora);

        self::assertSame(12, $resultado['meses']);
        self::assertEquals(new \DateTimeImmutable('2025-10-24 00:20:00'), $resultado['limite']);
        self::assertSame(3, $resultado['analises']);
        self::assertSame(2, $resultado['perguntas']);
        self::assertSame(5, $resultado['mensagens'], 'as mensagens do chat saem com o MESMO limite');
    }

    #[TestDox('o Master escolheu 3 meses: o limite acompanha')]
    public function testMesesDoMaster(): void
    {
        $agora = new \DateTimeImmutable('2026-10-24 00:20:00');
        $configuracao = new ConfiguracaoDaPlataformaDeIa();
        $configuracao->definirRetencao(3, (new User())->setRoles(['ROLE_SUPER_ADMIN']), $agora);
        $repositorio = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repositorio->expects(self::once())
            ->method('expirarConteudoAntesDe')
            ->with(new \DateTimeImmutable('2026-07-24 00:20:00'), $agora)
            ->willReturn(['analises' => 0, 'perguntas' => 0]);

        $resultado = $this->sut($repositorio, $configuracao)->executar($agora);

        self::assertSame(3, $resultado['meses']);
    }
}
