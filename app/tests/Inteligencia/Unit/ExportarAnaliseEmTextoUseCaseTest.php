<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\ExportarAnaliseEmTextoUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * IA-007: o .txt é a análise GRAVADA, nunca gerada aqui. Só a concluída tem texto; o resto é 404.
 */
#[CoversClass(ExportarAnaliseEmTextoUseCase::class)]
final class ExportarAnaliseEmTextoUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private AnaliseDeInteligenciaRepository&MockObject $analises;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->pasta = new Pasta();
        $this->pasta->setNup('2026/0001');
        $this->pasta->setTenant($this->tenant);
        DefineId::em($this->pasta, 7);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
    }

    private function analise(?Agente $agente): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $this->tenant,
            solicitante: null,
            tipo: $agente === null ? TipoDeAnalise::ResumoPush : TipoDeAnalise::AnalisePasta,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: 7,
            versaoDoPrompt: 'v1',
            contextoHash: str_repeat('a', 64),
            contextoResumo: ['total' => 2],
            agente: $agente,
        );
        DefineId::em($analise, 55);

        return $analise;
    }

    private function concluir(AnaliseDeInteligencia $analise, ?string $texto, array $pontos = [], ?string $quem = null): void
    {
        $analise->iniciarProcessamento();
        $analise->concluir('Resumo <b>curto</b>.', $pontos, $quem, '{}', 'falso', 'modelo', 1, 1, 1, $texto);
    }

    private function useCase(?AnaliseDeInteligencia $encontrada): ExportarAnaliseEmTextoUseCase
    {
        $this->analises->expects(self::once())
            ->method('findOneDoTenantEAlvo')
            ->with(55, $this->tenant, AnaliseDeInteligencia::ALVO_PASTA, 7)
            ->willReturn($encontrada);

        return new ExportarAnaliseEmTextoUseCase($this->analises);
    }

    #[TestDox('agente com texto integral: cabeçalho BLUEJUS IA · agente, pasta, o texto gravado e o aviso de origem')]
    public function testAgenteComTextoIntegral(): void
    {
        $analise = $this->analise(Agente::Gestor);
        $this->concluir($analise, "Linha 1 do modelo\n</analise> <script>x</script>");

        $saida = $this->useCase($analise)->executar(55, $this->pasta, $this->tenant);

        $linhas = explode("\n", $saida->conteudo);
        self::assertSame('BLUEJUS IA · Agente Gestor', $linhas[0]);
        self::assertStringStartsWith('Pasta 2026/0001 · gerada em ', $linhas[1]);
        self::assertStringContainsString("Linha 1 do modelo\n</analise> <script>x</script>", $saida->conteudo, 'o texto sai literal: é text/plain, não HTML');
        self::assertStringNotContainsString('Resumo <b>curto</b>', $saida->conteudo, 'com texto integral, o resumo não é repetido');
        self::assertStringContainsString('não é ato oficial do processo', $saida->conteudo);
        self::assertSame('bluejus-ia-gestor-pasta-2026-0001-55.txt', $saida->nomeDoArquivo);
    }

    #[TestDox('Push (sem texto integral): resumo, pontos com rótulo e quem deve agir')]
    public function testPushSemTextoIntegral(): void
    {
        $analise = $this->analise(null);
        $this->concluir($analise, null, [['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias.']], 'Dra. Ana');

        $saida = $this->useCase($analise)->executar(55, $this->pasta, $this->tenant);

        self::assertStringStartsWith('BLUEJUS IA · Análise IA do Push', $saida->conteudo);
        self::assertStringContainsString("Resumo <b>curto</b>.\n\n- Prazo: Apelação em 15 dias.\n\nQuem deve agir: Dra. Ana", $saida->conteudo);
        self::assertStringContainsString('não é movimentação oficial', $saida->conteudo);
        self::assertSame('bluejus-ia-push-pasta-2026-0001-55.txt', $saida->nomeDoArquivo);
    }

    #[TestDox('não encontrada (outro escritório/pasta) → AnaliseNaoEncontradaException')]
    public function testNaoEncontrada(): void
    {
        $this->expectException(AnaliseNaoEncontradaException::class);

        $this->useCase(null)->executar(55, $this->pasta, $this->tenant);
    }

    #[TestDox('pendente (ainda sem resultado) → AnaliseNaoEncontradaException: nada é inventado')]
    public function testPendente(): void
    {
        $this->expectException(AnaliseNaoEncontradaException::class);

        $this->useCase($this->analise(Agente::Gestor))->executar(55, $this->pasta, $this->tenant);
    }

    #[TestDox('excluída → AnaliseNaoEncontradaException')]
    public function testExcluida(): void
    {
        $analise = $this->analise(Agente::Gestor);
        $this->concluir($analise, 'Texto.');
        $analise->excluir(new User());

        $this->expectException(AnaliseNaoEncontradaException::class);

        $this->useCase($analise)->executar(55, $this->pasta, $this->tenant);
    }
}
