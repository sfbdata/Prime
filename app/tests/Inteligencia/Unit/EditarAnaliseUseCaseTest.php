<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\EditarAnaliseInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Exception\EdicaoDaAnaliseConcorrenteException;
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
    /** @param list<array{tipo: string, texto: string}> $pontos */
    private function concluida(
        string $resumo = 'Resumo.',
        array $pontos = [['tipo' => 'prazo', 'texto' => 'Prazo.']],
        TipoDeAnalise $tipo = TipoDeAnalise::ResumoPush,
    ): AnaliseDeInteligencia {
        $a = new AnaliseDeInteligencia(
            tenant: new Tenant(),
            solicitante: null,
            tipo: $tipo,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'push-v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: [],
            agente: $tipo === TipoDeAnalise::AnalisePasta ? Agente::Gestor : null,
        );
        $a->iniciarProcessamento();
        $a->concluir($resumo, $pontos, null, '{}', 'falso', 'falso-1', 1, 1, 1);

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

    #[TestDox('análise de agente não é editável aqui (404): o desenho é o cartão do Push')]
    public function testAgenteNaoEditavel(): void
    {
        [$useCase, $repo] = $this->useCase($this->concluida(tipo: TipoDeAnalise::AnalisePasta));
        $repo->expects(self::never())->method('salvar');

        $this->expectException(AnaliseNaoEncontradaException::class);
        $useCase->executar(1, 7, new EditarAnaliseInput('x', []), new User(), new Tenant());
    }

    #[TestDox('texto que volta igual depois da limpeza (zero-width, longo demais) não marca "editado" nem é recusado')]
    public function testTextoNaoMexidoFicaComoEstava(): void
    {
        $longo = str_repeat('a', EditarAnaliseUseCase::MAXIMO_PONTO + 20);
        $a = $this->concluida("Resumo\u{200B} do modelo.", [['tipo' => 'info', 'texto' => $longo], ['tipo' => 'ok', 'texto' => 'Outro.']]);
        [$useCase, $repo] = $this->useCase($a);

        $saida = $useCase->executar(1, 7, new EditarAnaliseInput("Resumo\u{200B} do modelo.", [0 => $longo, 1 => 'Outro.']), new User(), new Tenant());
        self::assertFalse($saida->editada);

        // Remover só o segundo ponto: o primeiro (longo, intacto) fica sem 422.
        $repo->expects(self::once())->method('salvar');
        $saida = $useCase->executar(1, 7, new EditarAnaliseInput("Resumo\u{200B} do modelo.", [0 => $longo]), new User(), new Tenant());
        self::assertTrue($saida->editada);
        self::assertSame([$longo], array_column($saida->pontos, 'texto'));
        self::assertSame("Resumo\u{200B} do modelo.", $saida->resumo);
    }

    #[TestDox('o texto editado segue a regra semTraco: travessão e hífen solto viram vírgula')]
    public function testSemTraco(): void
    {
        [$useCase] = $this->useCase($this->concluida());

        $saida = $useCase->executar(1, 7, new EditarAnaliseInput('Sentença publicada — prazo aberto - contrarrazões.', []), new User(), new Tenant());

        self::assertSame('Sentença publicada, prazo aberto, contrarrazões.', $saida->resumo);
    }

    #[TestDox('versão diferente da que a tela viu (outra pessoa salvou) → recusa sem salvar; versão igual passa')]
    public function testVersao(): void
    {
        $a = $this->concluida();
        [$useCase, $repo] = $this->useCase($a);
        $repo->expects(self::once())->method('salvar');

        $useCase->executar(1, 7, new EditarAnaliseInput('Primeira.', [], ''), new User(), new Tenant());

        try {
            $useCase->executar(1, 7, new EditarAnaliseInput('Segunda.', [], ''), new User(), new Tenant());
            self::fail('a tela viu a versão sem edição');
        } catch (EdicaoDaAnaliseConcorrenteException) {
            self::assertSame('Primeira.', $a->getResumoExibido());
        }

        $saida = $useCase->executar(1, 7, new EditarAnaliseInput('Primeira.', [], EditarAnaliseUseCase::versaoDe($a)), new User(), new Tenant());
        self::assertSame('Primeira.', $saida->resumo);
    }
}
