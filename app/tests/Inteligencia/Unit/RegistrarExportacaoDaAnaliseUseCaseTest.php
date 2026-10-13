<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\RegistrarExportacaoDaAnaliseUseCase;
use App\Pasta\Entity\Pasta;
use App\Tests\Inteligencia\Support\DefineId;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * C6-IA-01: download e impressão deixam 1 linha no `audit_log`, gravada à mão. O EntityManager é
 * mock pelo mesmo motivo do `MontarZipDeDocumentosUseCaseTest`: aqui ele só recebe o `AuditLog` e
 * o `flush`. Nenhuma recusa (ação desconhecida, análise alheia, excluída ou sem resultado) grava.
 */
#[CoversClass(RegistrarExportacaoDaAnaliseUseCase::class)]
final class RegistrarExportacaoDaAnaliseUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private Pasta $pasta;
    private User $autor;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 42);
        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        DefineId::em($this->pasta, 7);
        $this->autor = new User();
        $this->autor->setEmail('autora@escritorio.test');
        DefineId::em($this->autor, 9);
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    private function analise(?Agente $agente, bool $concluida = true): AnaliseDeInteligencia
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
        if ($concluida) {
            $analise->iniciarProcessamento();
            $analise->concluir('Resumo sigiloso.', [], null, '{}', 'falso', 'modelo', 1, 1, 1, 'Texto sigiloso do modelo.');
        }

        return $analise;
    }

    private function useCase(?AnaliseDeInteligencia $encontrada): RegistrarExportacaoDaAnaliseUseCase
    {
        $this->analises->expects(self::once())->method('findOneDoTenantEAlvo')
            ->with(55, $this->tenant, AnaliseDeInteligencia::ALVO_PASTA, 7)
            ->willReturn($encontrada);

        return new RegistrarExportacaoDaAnaliseUseCase($this->analises, $this->em);
    }

    /** @return \ArrayObject<int, object> */
    private function capturar(): \ArrayObject
    {
        $gravados = new \ArrayObject();
        $this->em->method('persist')->willReturnCallback(static function (object $o) use ($gravados): void {
            $gravados->append($o);
        });

        return $gravados;
    }

    #[TestDox('download: 1 linha "ia_baixar" com análise, tenant, autor, pasta e agente; nenhum texto do modelo')]
    public function testDownload(): void
    {
        $gravados = $this->capturar();
        $this->em->expects(self::once())->method('flush');

        $this->useCase($this->analise(Agente::Gestor))->executar(55, $this->pasta, $this->tenant, $this->autor, RegistrarExportacaoDaAnaliseUseCase::PARA_DOWNLOAD);

        self::assertCount(1, $gravados);
        $log = $gravados[0];
        self::assertInstanceOf(AuditLog::class, $log);
        self::assertSame('ia_baixar', $log->getAction());
        self::assertSame(AnaliseDeInteligencia::class, $log->getEntityClass());
        self::assertSame('55', $log->getEntityId());
        self::assertSame(42, $log->getTenantId());
        self::assertSame(9, $log->getActorUserId());
        self::assertSame('autora@escritorio.test', $log->getActorEmail());
        self::assertSame(['pasta_id' => 7, 'agente' => 'gestor'], $log->getChanges());
        self::assertStringNotContainsString('sigiloso', (string) json_encode($log->getChanges()));
    }

    #[TestDox('impressão: 1 linha "ia_imprime"; análise do Push registra agente "push"')]
    public function testImpressao(): void
    {
        $gravados = $this->capturar();
        $this->em->expects(self::once())->method('flush');

        $this->useCase($this->analise(null))->executar(55, $this->pasta, $this->tenant, $this->autor, RegistrarExportacaoDaAnaliseUseCase::PARA_IMPRESSAO);

        self::assertCount(1, $gravados);
        self::assertSame('ia_imprime', $gravados[0]->getAction());
        self::assertSame('push', $gravados[0]->getChanges()['agente']);
    }

    #[TestDox('as duas ações cabem em audit_log.action, VARCHAR(10)')]
    public function testAcoesCabemNaColuna(): void
    {
        foreach (RegistrarExportacaoDaAnaliseUseCase::ACOES as $acao) {
            self::assertLessThanOrEqual(10, strlen($acao), $acao);
        }
        self::assertSame(['download', 'impressao'], array_keys(RegistrarExportacaoDaAnaliseUseCase::ACOES));
    }

    /** @return iterable<string, array{string}> */
    public static function acoesRecusadas(): iterable
    {
        yield 'cópia (o desenho não registra)' => ['copia'];
        yield 'vazia' => [''];
        yield 'a ação gravada no lugar da pedida' => ['ia_baixar'];
        yield 'maiúscula' => ['DOWNLOAD'];
    }

    #[DataProvider('acoesRecusadas')]
    #[TestDox('ação fora de download|impressao: recusada antes de consultar ou gravar')]
    public function testAcaoRecusada(string $para): void
    {
        self::assertFalse(RegistrarExportacaoDaAnaliseUseCase::aceita($para));
        $this->analises->expects(self::never())->method('findOneDoTenantEAlvo');
        $this->em->expects(self::never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        (new RegistrarExportacaoDaAnaliseUseCase($this->analises, $this->em))->executar(55, $this->pasta, $this->tenant, $this->autor, $para);
    }

    /** @return iterable<string, array{?bool}> */
    public static function naoEncontradas(): iterable
    {
        yield 'de outro escritório ou pasta (a consulta não acha)' => [null];
        yield 'sem resultado (pendente)' => [false];
        yield 'excluída' => [true];
    }

    #[DataProvider('naoEncontradas')]
    #[TestDox('análise não encontrada, sem resultado ou excluída: 404 e nada gravado')]
    public function testNaoEncontrada(?bool $caso): void
    {
        $analise = match ($caso) {
            null => null,
            false => $this->analise(Agente::Gestor, concluida: false),
            true => $this->analise(Agente::Gestor),
        };
        if ($caso === true) {
            $analise->excluir($this->autor);
        }
        $this->em->expects(self::never())->method('persist');
        $this->em->expects(self::never())->method('flush');
        $this->expectException(AnaliseNaoEncontradaException::class);

        $this->useCase($analise)->executar(55, $this->pasta, $this->tenant, $this->autor, RegistrarExportacaoDaAnaliseUseCase::PARA_DOWNLOAD);
    }
}
