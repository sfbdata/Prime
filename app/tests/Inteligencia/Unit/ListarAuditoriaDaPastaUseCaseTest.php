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
}
