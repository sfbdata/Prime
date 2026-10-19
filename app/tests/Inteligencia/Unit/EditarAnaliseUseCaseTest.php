<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\EditarAnaliseInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\EdicaoDaAnaliseInvalidaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\EditarAnaliseUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditarAnaliseUseCase::class)]
#[CoversClass(EditarAnaliseInput::class)]
final class EditarAnaliseUseCaseTest extends TestCase
{
    private function concluida(): AnaliseDeInteligencia
    {
        $a = new AnaliseDeInteligencia(
            tenant: new Tenant(),
            solicitante: null,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'push-v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: [],
        );
        $a->iniciarProcessamento();
        $a->concluir('Resumo.', [['tipo' => 'prazo', 'texto' => 'Prazo.']], null, '{}', 'falso', 'falso-1', 1, 1, 1);

        return $a;
    }

    /** @return array{EditarAnaliseUseCase, AnaliseDeInteligenciaRepository&\PHPUnit\Framework\MockObject\MockObject} */
    private function useCase(?AnaliseDeInteligencia $achada): array
    {
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('findOneDoTenantEAlvo')->willReturn($achada);

        return [new EditarAnaliseUseCase($repo), $repo];
    }

    #[TestDox('grava a edição e salva; quebra de linha e caracteres de controle/bidi saem')]
    public function testGravaLimpo(): void
    {
        $a = $this->concluida();
        [$useCase, $repo] = $this->useCase($a);
        $repo->expects(self::once())->method('salvar')->with($a, true);

        $saida = $useCase->executar(1, 7, new EditarAnaliseInput("Linha um\nlinha\u{202E} dois\x00", [0 => "Prazo\tnovo."]), new User(), new Tenant());

        self::assertTrue($saida->editada);
        self::assertSame('Linha um linha dois', $saida->resumo);
        self::assertSame('Prazo novo.', $saida->pontos[0]['texto']);
    }

    #[TestDox('sem mudança não salva')]
    public function testSemMudancaNaoSalva(): void
    {
        [$useCase, $repo] = $this->useCase($this->concluida());
        $repo->expects(self::never())->method('salvar');

        $saida = $useCase->executar(1, 7, new EditarAnaliseInput('Resumo.', [0 => 'Prazo.']), new User(), new Tenant());

        self::assertFalse($saida->editada);
    }

    #[TestDox('texto além do limite é recusado e nada é salvo')]
    public function testLimites(): void
    {
        [$useCase, $repo] = $this->useCase($this->concluida());
        $repo->expects(self::never())->method('salvar');

        try {
            $useCase->executar(1, 7, new EditarAnaliseInput(str_repeat('a', EditarAnaliseUseCase::MAXIMO_RESUMO + 1), []), new User(), new Tenant());
            self::fail('resumo longo');
        } catch (EdicaoDaAnaliseInvalidaException) {
        }

        $this->expectException(EdicaoDaAnaliseInvalidaException::class);
        $useCase->executar(1, 7, new EditarAnaliseInput('ok', [0 => str_repeat('a', EditarAnaliseUseCase::MAXIMO_PONTO + 1)]), new User(), new Tenant());
    }

    #[TestDox('análise de outro escritório/pasta ou excluída: não encontrada')]
    public function testNaoEncontrada(): void
    {
        [$useCase] = $this->useCase(null);
        try {
            $useCase->executar(1, 7, new EditarAnaliseInput('x', []), new User(), new Tenant());
            self::fail('sem análise');
        } catch (AnaliseNaoEncontradaException) {
        }

        $excluida = $this->concluida();
        $excluida->excluir(new User());
        [$useCase] = $this->useCase($excluida);
        $this->expectException(AnaliseNaoEncontradaException::class);
        $useCase->executar(1, 7, new EditarAnaliseInput('x', []), new User(), new Tenant());
    }
}
