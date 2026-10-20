<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClientInterface;
use App\Djen\Service\DjenPublicacaoMapper;
use App\Djen\Service\EsperaEntreConsultasInterface;
use App\Djen\Service\NotificadorPublicacoesDjenInterface;
use App\Djen\UseCase\ReconciliarPublicacoesComProcessosUseCase;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Tenant\Tenant;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A captação com o monitoramento por processo (spec push-painel-monitoramento §1 e §2): grava a
 * última verificação de cada número consultado com sucesso, e o "Buscar agora" consulta só os
 * números que recebe. Que os pausados ficam fora da lista é da consulta
 * (`listarNumerosAcompanhadosDoTenant`), provada no functional.
 */
#[CoversClass(SincronizarPublicacoesDjenUseCase::class)]
final class SincronizarNumerosDjenUseCaseTest extends TestCase
{
    private DjenClientInterface&MockObject $djenClient;
    private OabMonitoradaRepository&MockObject $oabRepository;
    private PublicacaoDjenRepository&MockObject $publicacaoRepository;
    private ProcessoRepository&MockObject $processoRepository;
    private SincronizarPublicacoesDjenUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->djenClient = $this->createMock(DjenClientInterface::class);
        $this->oabRepository = $this->createMock(OabMonitoradaRepository::class);
        $this->publicacaoRepository = $this->createMock(PublicacaoDjenRepository::class);
        $this->processoRepository = $this->createMock(ProcessoRepository::class);
        $this->tenant = new Tenant();

        $this->sut = new SincronizarPublicacoesDjenUseCase(
            $this->djenClient,
            new DjenPublicacaoMapper(),
            $this->oabRepository,
            $this->publicacaoRepository,
            $this->processoRepository,
            $this->createMock(NotificadorPublicacoesDjenInterface::class),
            new ReconciliarPublicacoesComProcessosUseCase($this->publicacaoRepository, $this->processoRepository),
            $this->createMock(EsperaEntreConsultasInterface::class),
        );
    }

    private static function periodo(): PeriodoConsulta
    {
        return PeriodoConsulta::ultimosDias(30, new \DateTimeImmutable('2026-10-19'));
    }

    #[TestDox('Captação: cada número consultado com sucesso grava a última verificação; o que falhou, não')]
    public function testCaptacaoGravaUltimaVerificacao(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222']);
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === '11111111111111111111'
                ? new RespostaComunicacoes(0, [])
                : throw new ConsultaDjenException(MotivoFalhaDjen::Timeout),
        );

        $this->processoRepository->expects(self::once())->method('registrarVerificacaoDosNumeros')
            ->with($this->tenant, ['11111111111111111111'], self::isInstanceOf(\DateTimeImmutable::class));

        $this->sut->sincronizarProcessosCobrindoTodos($this->tenant, self::periodo());
    }

    #[TestDox('--dry-run não grava a última verificação')]
    public function testDryRunNaoGrava(): void
    {
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')->willReturn(['11111111111111111111']);
        $this->djenClient->method('consultarComunicacoes')->willReturn(new RespostaComunicacoes(0, []));
        $this->processoRepository->expects(self::never())->method('registrarVerificacaoDosNumeros');

        $this->sut->sincronizarProcessosCobrindoTodos($this->tenant, self::periodo(), dryRun: true);
    }

    #[TestDox('Buscar agora: só os números recebidos (sem máscara, inválido fora), sem OAB e sem a lista do escritório')]
    public function testSincronizarNumerosConsultaSoOsRecebidos(): void
    {
        $this->oabRepository->expects(self::never())->method('listarAtivasDoTenant');
        $this->processoRepository->expects(self::never())->method('listarNumerosAcompanhadosDoTenant');
        $consultados = [];
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $q) use (&$consultados): RespostaComunicacoes {
                $consultados[] = [$q->numeroProcesso, $q->dataDisponibilizacaoInicio->format('Y-m-d'), $q->dataDisponibilizacaoFim->format('Y-m-d')];

                return new RespostaComunicacoes(0, []);
            },
        );
        $this->processoRepository->expects(self::once())->method('registrarVerificacaoDosNumeros')
            ->with($this->tenant, ['07011345720258070007'], self::isInstanceOf(\DateTimeImmutable::class));

        $resultado = $this->sut->sincronizarNumeros($this->tenant, self::periodo(), ['0701134-57.2025.8.07.0007', 'SEM-NUMERO']);

        self::assertSame([['07011345720258070007', '2026-09-20', '2026-10-19']], $consultados);
        self::assertSame(1, $resultado->getProcessosConsultados());
    }

    #[TestDox('Buscar agora no limite do DJEN: para, não espera a janela e conta os adiados')]
    public function testSincronizarNumerosParaNoLimite(): void
    {
        $this->djenClient->method('consultarComunicacoes')->willThrowException(new ConsultaDjenException(MotivoFalhaDjen::LimiteExcedido));
        $this->processoRepository->expects(self::once())->method('registrarVerificacaoDosNumeros')
            ->with($this->tenant, [], self::anything());

        $resultado = $this->sut->sincronizarNumeros($this->tenant, self::periodo(), ['11111111111111111111', '22222222222222222222']);

        self::assertSame(2, $resultado->getProcessosAdiados());
        self::assertSame(0, $resultado->getProcessosConsultados());
        self::assertFalse($resultado->temFalhas());
    }

    #[TestDox('A verificação é gravada DEPOIS do flush das publicações novas')]
    public function testVerificacaoDepoisDoFlush(): void
    {
        $this->djenClient->method('consultarComunicacoes')->willReturn(new RespostaComunicacoes(1, [[
            'id' => 1, 'numero_processo' => '11111111111111111111', 'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação', 'data_disponibilizacao' => '2026-10-19',
        ]]));
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $ordem = [];
        $this->publicacaoRepository->method('flush')->willReturnCallback(function () use (&$ordem): void {
            $ordem[] = 'flush';
        });
        $this->processoRepository->method('registrarVerificacaoDosNumeros')->willReturnCallback(function () use (&$ordem): void {
            $ordem[] = 'verificacao';
        });

        $this->sut->sincronizarNumeros($this->tenant, self::periodo(), ['11111111111111111111']);

        self::assertSame(['flush', 'verificacao'], $ordem);
    }
}
