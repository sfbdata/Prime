<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\RegistroDeAuditoriaOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\ListarAuditoriaDaPastaUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListarAuditoriaDaPastaUseCase::class)]
#[CoversClass(RegistroDeAuditoriaOutput::class)]
final class ListarAuditoriaDaPastaUseCaseTest extends TestCase
{
    #[TestDox('consulta a trilha do alvo com tenant e limite 50; N é a contagem total, não a da página')]
    public function testMontaATrilha(): void
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 42);
        $pasta = new Pasta();
        DefineId::em($pasta, 7);
        $quem = new User();
        $quem->setFullName('Ana Lima');

        $push = new AnaliseDeInteligencia($tenant, $quem, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('a', 64), []);
        DefineId::em($push, 1);
        $agente = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('b', 64), [], Agente::Juridico);
        DefineId::em($agente, 2);
        $agente->excluir($quem);

        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->expects(self::once())->method('listarTrilhaDoAlvo')
            ->with($tenant, AnaliseDeInteligencia::ALVO_PASTA, 7, ListarAuditoriaDaPastaUseCase::LIMITE)
            ->willReturn([$push, $agente]);
        $repo->expects(self::once())->method('contarTrilhaDoAlvo')
            ->with($tenant, AnaliseDeInteligencia::ALVO_PASTA, 7)
            ->willReturn(60);

        $saida = (new ListarAuditoriaDaPastaUseCase($repo))->executar($pasta, $tenant);

        self::assertSame(50, ListarAuditoriaDaPastaUseCase::LIMITE);
        self::assertSame(60, $saida->total);
        self::assertTrue($saida->temMaisQueOsListados());
        self::assertCount(2, $saida->registros);
        self::assertSame('Análise IA do Push', $saida->registros[0]->oQue);
        self::assertSame('Ana Lima', $saida->registros[0]->solicitanteNome);
        self::assertSame('Na fila', $saida->registros[0]->statusRotulo);
        self::assertFalse($saida->registros[0]->excluida);
        self::assertSame('Agente Jurídico', $saida->registros[1]->oQue);
        self::assertNull($saida->registros[1]->solicitanteNome);
        self::assertTrue($saida->registros[1]->excluida);
    }

    #[TestDox('C6-IA-01: downloads e impressões entram na lista por data, com o rótulo da análise e o autor, e somam no N')]
    public function testJuntaDownloadsEImpressoes(): void
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 42);
        $pasta = new Pasta();
        DefineId::em($pasta, 7);

        $analise = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::AnalisePasta, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('b', 64), [], Agente::Gestor);
        DefineId::em($analise, 2);
        $criadaEm = $analise->getCriadaEm();

        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('listarTrilhaDoAlvo')->willReturn([$analise]);
        $repo->method('contarTrilhaDoAlvo')->willReturn(1);
        $repo->expects(self::once())->method('listarExportacoesDoAlvo')
            ->with($tenant, AnaliseDeInteligencia::ALVO_PASTA, 7, ['ia_baixar', 'ia_imprime'], ListarAuditoriaDaPastaUseCase::LIMITE)
            ->willReturn([
                ['acao' => 'ia_imprime', 'analiseId' => 2, 'quando' => $criadaEm->modify('+2 hours'), 'autorNome' => 'Bia Souza', 'agente' => Agente::Gestor, 'tipo' => TipoDeAnalise::AnalisePasta],
                ['acao' => 'ia_baixar', 'analiseId' => 9, 'quando' => $criadaEm->modify('+1 hour'), 'autorNome' => null, 'agente' => null, 'tipo' => TipoDeAnalise::ResumoPush],
                ['acao' => 'ia_baixar', 'analiseId' => 2, 'quando' => $criadaEm->modify('-1 hour'), 'autorNome' => 'Ana Lima', 'agente' => Agente::Gestor, 'tipo' => TipoDeAnalise::AnalisePasta],
            ]);
        $repo->expects(self::once())->method('contarExportacoesDoAlvo')
            ->with($tenant, AnaliseDeInteligencia::ALVO_PASTA, 7, ['ia_baixar', 'ia_imprime'])
            ->willReturn(3);

        $saida = (new ListarAuditoriaDaPastaUseCase($repo))->executar($pasta, $tenant);

        self::assertSame(4, $saida->total, '1 análise + 3 exportações');
        self::assertSame(
            ['impressao', 'download', 'analise', 'download'],
            array_map(static fn (RegistroDeAuditoriaOutput $r): string => $r->acao, $saida->registros),
            'as duas fontes, mais recente primeiro',
        );
        self::assertTrue($saida->registros[0]->ehImpressao());
        self::assertSame('Agente Gestor', $saida->registros[0]->oQue);
        self::assertSame('Bia Souza', $saida->registros[0]->solicitanteNome);
        self::assertTrue($saida->registros[1]->ehDownload());
        self::assertSame('Análise IA do Push', $saida->registros[1]->oQue);
        self::assertSame(9, $saida->registros[1]->analiseId);
        self::assertFalse($saida->registros[2]->ehDownload() || $saida->registros[2]->ehImpressao());
        self::assertSame('Ana Lima', $saida->registros[3]->solicitanteNome);
    }

    #[TestDox('C6-IA-01: a junção das duas fontes é cortada no limite (50)')]
    public function testJuncaoRespeitaOLimite(): void
    {
        $tenant = new Tenant();
        DefineId::em($tenant, 42);
        $pasta = new Pasta();
        DefineId::em($pasta, 7);
        $analise = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('c', 64), []);
        DefineId::em($analise, 3);

        $exportacoes = [];
        for ($i = 0; $i < ListarAuditoriaDaPastaUseCase::LIMITE; ++$i) {
            $exportacoes[] = ['acao' => 'ia_baixar', 'analiseId' => 3, 'quando' => $analise->getCriadaEm()->modify('+' . (ListarAuditoriaDaPastaUseCase::LIMITE - $i) . ' minutes'), 'autorNome' => null, 'agente' => null, 'tipo' => TipoDeAnalise::ResumoPush];
        }
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('listarTrilhaDoAlvo')->willReturn([$analise]);
        $repo->method('contarTrilhaDoAlvo')->willReturn(1);
        $repo->method('listarExportacoesDoAlvo')->willReturn($exportacoes);
        $repo->method('contarExportacoesDoAlvo')->willReturn(70);

        $saida = (new ListarAuditoriaDaPastaUseCase($repo))->executar($pasta, $tenant);

        self::assertSame(71, $saida->total);
        self::assertCount(ListarAuditoriaDaPastaUseCase::LIMITE, $saida->registros);
        self::assertTrue($saida->temMaisQueOsListados());
        foreach ($saida->registros as $r) {
            self::assertTrue($r->ehDownload(), 'a análise, mais antiga que as 50 exportações, fica fora da página');
        }
    }
}
