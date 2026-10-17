<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
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
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SincronizarPublicacoesDjenUseCase::class)]
final class SincronizarPublicacoesDjenUseCaseTest extends TestCase
{
    private DjenClientInterface&MockObject $djenClient;
    private OabMonitoradaRepository&MockObject $oabRepository;
    private PublicacaoDjenRepository&MockObject $publicacaoRepository;
    private ProcessoRepository&MockObject $processoRepository;
    private NotificadorPublicacoesDjenInterface&MockObject $notificador;
    private EsperaEntreConsultasInterface&MockObject $espera;
    private SincronizarPublicacoesDjenUseCase $sut;
    private Tenant&MockObject $tenant;

    protected function setUp(): void
    {
        $this->djenClient = $this->createMock(DjenClientInterface::class);
        $this->oabRepository = $this->createMock(OabMonitoradaRepository::class);
        $this->publicacaoRepository = $this->createMock(PublicacaoDjenRepository::class);
        $this->processoRepository = $this->createMock(ProcessoRepository::class);
        $this->notificador = $this->createMock(NotificadorPublicacoesDjenInterface::class);
        $this->espera = $this->createMock(EsperaEntreConsultasInterface::class);
        $this->tenant = $this->createMock(Tenant::class);

        $this->sut = new SincronizarPublicacoesDjenUseCase(
            $this->djenClient,
            new DjenPublicacaoMapper(),
            $this->oabRepository,
            $this->publicacaoRepository,
            $this->processoRepository,
            $this->notificador,
            // Real, não dublê: o UseCase é final (padrão da casa) e, com os mesmos repositórios
            // mockados, `listarAvulsasDoTenant` devolve [] e a reconciliação vira no-op aqui.
            new ReconciliarPublicacoesComProcessosUseCase($this->publicacaoRepository, $this->processoRepository),
            $this->espera,
        );
    }

    #[Test]
    public function capturaPublicacaoNovaVinculaAoProcessoENotifica(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(659192456, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')
            ->with('50636766220224047000', $this->tenant)
            ->willReturn($this->createMock(Processo::class));

        $this->publicacaoRepository->expects($this->once())->method('salvar');
        $this->publicacaoRepository->expects($this->once())->method('flush');
        $this->notificador->expects($this->once())->method('notificar')->with($this->tenant, 1)->willReturn(3);

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(1, $resultado->getVinculadas());
        self::assertSame(0, $resultado->getAvulsas());
        self::assertSame(3, $resultado->getNotificados());
        self::assertSame(1, $resultado->getRecebidas());
        self::assertFalse($resultado->temFalhas());
    }

    #[Test]
    public function publicacaoSemProcessoCadastradoFicaAvulsa(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(1, '99999999999999999999')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn(null);
        $this->notificador->method('notificar')->willReturn(1);

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(0, $resultado->getVinculadas());
        self::assertSame(1, $resultado->getAvulsas());
    }

    #[Test]
    public function naoDuplicaPublicacaoJaExistenteEnaoNotifica(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(659192456, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn(['659192456']);

        $this->publicacaoRepository->expects($this->never())->method('salvar');
        $this->publicacaoRepository->expects($this->never())->method('flush');
        $this->notificador->expects($this->never())->method('notificar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(0, $resultado->getNovas());
        self::assertSame(1, $resultado->getRecebidas());
    }

    #[Test]
    public function dryRunNaoPersisteNemFlushNemNotifica(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(659192456, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn($this->createMock(Processo::class));

        $this->publicacaoRepository->expects($this->never())->method('salvar');
        $this->publicacaoRepository->expects($this->never())->method('flush');
        $this->notificador->expects($this->never())->method('notificar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')), true);

        // Ainda conta o que SERIA capturado (simulação), mas nada é gravado.
        self::assertSame(1, $resultado->getNovas());
        self::assertSame(1, $resultado->getVinculadas());
        self::assertSame(0, $resultado->getNotificados());
    }

    #[Test]
    public function falhaDeUmaOabNaoDerrubaAsDemais(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([
            $this->oab('111', 'SP'),
            $this->oab('222', 'SP'),
        ]);
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            fn ($query): RespostaComunicacoes => $query->numeroOab === '111'
                ? throw new ConsultaDjenException(MotivoFalhaDjen::SistemaOcupado)
                : new RespostaComunicacoes(1, [$this->item(777, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn(null);
        $this->notificador->method('notificar')->willReturn(1);

        $this->publicacaoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertTrue($resultado->temFalhas());
        self::assertArrayHasKey('111/SP', $resultado->getFalhas());
        self::assertSame(MotivoFalhaDjen::SistemaOcupado->value, $resultado->getFalhas()['111/SP']);
    }

    #[Test]
    public function paginaMultiplasPaginasAteAUltima(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        // Página 1 cheia (100) → continua; página 2 parcial (50) → para. Total 150 itens distintos.
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function ($query): RespostaComunicacoes {
                $de = $query->pagina === 1 ? 1 : 101;
                $ate = $query->pagina === 1 ? 100 : 150;
                $itens = [];
                for ($id = $de; $id <= $ate; $id++) {
                    $itens[] = $this->item($id, str_pad((string) $id, 20, '0', STR_PAD_LEFT));
                }

                return new RespostaComunicacoes(150, $itens);
            }
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn(null);
        $this->notificador->method('notificar')->willReturn(1);

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(150, $resultado->getRecebidas());
        self::assertSame(150, $resultado->getNovas());
    }

    #[Test]
    public function naoDuplicaMesmoDjenIdVindoDeDuasOabs(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([
            $this->oab('111', 'SP'),
            $this->oab('222', 'SP'),
        ]);
        // A MESMA publicação (djenId 555) casa as duas OABs — não pode virar 2 linhas (violaria o unique).
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(555, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn(null);
        $this->notificador->method('notificar')->willReturn(1);

        $this->publicacaoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(2, $resultado->getRecebidas());
    }

    #[Test]
    public function processoCadastradoEConsultadoPeloNumeroEVinculadoSemOab(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->with($this->tenant)
            ->willReturn(['50636766220224047000']);

        $consultas = [];
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $query) use (&$consultas): RespostaComunicacoes {
                $consultas[] = $query;

                return new RespostaComunicacoes(1, [$this->item(900001, '50636766220224047000')]);
            }
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')
            ->with('50636766220224047000', $this->tenant)
            ->willReturn($this->createMock(Processo::class));
        $this->notificador->method('notificar')->willReturn(2);

        $salvas = [];
        $this->publicacaoRepository->expects($this->once())->method('salvar')
            ->willReturnCallback(function (PublicacaoDjen $p) use (&$salvas): void {
                $salvas[] = $p;
            });

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertCount(1, $consultas);
        self::assertSame('50636766220224047000', $consultas[0]->toQueryParams()['numeroProcesso']);
        self::assertArrayNotHasKey('numeroOab', $consultas[0]->toQueryParams(), 'consulta por número não leva OAB');
        self::assertSame('2026-07-06', $consultas[0]->toQueryParams()['dataDisponibilizacaoInicio']);
        self::assertNull($salvas[0]->getOabMonitorada(), 'captada pelo número, não por OAB monitorada');
        self::assertNotNull($salvas[0]->getProcesso());
        self::assertSame(1, $resultado->getNovas());
        self::assertSame(1, $resultado->getVinculadas());
        self::assertSame(1, $resultado->getProcessosConsultados());
        self::assertStringContainsString('1 processo(s) cadastrado(s) consultado(s)', $resultado->resumo());
    }

    #[Test]
    public function mesmaPublicacaoPelaOabEPeloNumeroViraUmaSoLinha(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')->willReturn(['50636766220224047000']);
        // As duas consultas devolvem o MESMO djenId: o unique (tenant, djen_id) não aceitaria duas linhas.
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(555, '50636766220224047000')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn($this->createMock(Processo::class));
        $this->notificador->method('notificar')->willReturn(1);

        $this->publicacaoRepository->expects($this->once())->method('salvar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(2, $resultado->getRecebidas());
    }

    #[Test]
    public function numeroComMascaraEConsultadoSemMascaraENumeroInvalidoEIgnorado(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['5063676-62.2022.4.04.7000', 'SEM-NUMERO', '123']);

        $numerosConsultados = [];
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $query) use (&$numerosConsultados): RespostaComunicacoes {
                $numerosConsultados[] = $query->numeroProcesso;

                return new RespostaComunicacoes(0, []);
            }
        );

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(['50636766220224047000'], $numerosConsultados);
        self::assertSame(1, $resultado->getProcessosConsultados());
        self::assertFalse($resultado->temFalhas());
    }

    #[Test]
    public function noRequestOLimiteDoDjenParaOLacoSemFalhaEContaOsAdiados(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222', '33333333333333333333']);

        $chamadas = 0;
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function () use (&$chamadas): RespostaComunicacoes {
                ++$chamadas;

                return $chamadas === 1
                    ? new RespostaComunicacoes(0, [])
                    : throw new ConsultaDjenException(MotivoFalhaDjen::LimiteExcedido);
            }
        );
        $this->espera->expects($this->never())->method('aguardar');

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(2, $chamadas, 'parou no primeiro 429, não martelou o DJEN');
        self::assertSame(1, $resultado->getProcessosConsultados());
        self::assertSame(2, $resultado->getProcessosAdiados());
        self::assertFalse($resultado->temFalhas(), 'bater no limite no botão é o desenho, não falha');
        self::assertStringContainsString('2 processo(s) não foram consultados agora', $resultado->resumo());
    }

    #[Test]
    public function noRequestFalhaQualquerNoNumeroParaOLacoComUmaFalhaSo(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([]);
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222']);

        // Timeout de 30s por chamada: insistir derrubaria o request no limite do nginx.
        $this->djenClient->expects($this->once())->method('consultarComunicacoes')
            ->willThrowException(new ConsultaDjenException(MotivoFalhaDjen::Timeout));

        $resultado = $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(['Processos cadastrados' => MotivoFalhaDjen::Timeout->value], $resultado->getFalhas());
        self::assertSame(2, $resultado->getProcessosAdiados());
    }

    #[Test]
    public function semIncluirProcessosSoAsOabsSaoConsultadas(): void
    {
        $this->oabRepository->method('listarAtivasDoTenant')->willReturn([$this->oab('67228', 'PR')]);
        $this->processoRepository->expects($this->never())->method('listarNumerosAcompanhadosDoTenant');
        $this->djenClient->expects($this->once())->method('consultarComunicacoes')->willReturn(new RespostaComunicacoes(0, []));

        $this->sut->executar($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')), false, false);
    }

    #[Test]
    public function noComandoOLimiteEsperaAJanelaERepeteOMesmoNumero(): void
    {
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222']);

        $consultados = [];
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $q) use (&$consultados): RespostaComunicacoes {
                $consultados[] = $q->numeroProcesso;

                // A 2ª chamada (primeira do número 2) bate no limite; a repetição passa.
                return \count($consultados) === 2
                    ? throw new ConsultaDjenException(MotivoFalhaDjen::LimiteExcedido)
                    : new RespostaComunicacoes(0, []);
            }
        );
        $this->espera->expects($this->once())->method('aguardar')->with(61);

        $resultado = $this->sut->sincronizarProcessosCobrindoTodos($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(['11111111111111111111', '22222222222222222222', '22222222222222222222'], $consultados);
        self::assertSame(2, $resultado->getProcessosConsultados(), 'todos cobertos, nenhum ficou para trás');
        self::assertSame(0, $resultado->getProcessosAdiados());
        self::assertFalse($resultado->temFalhas());
    }

    #[Test]
    public function noComandoDesisteDepoisDeTresEsperasSeguidas(): void
    {
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222']);
        $this->djenClient->method('consultarComunicacoes')
            ->willThrowException(new ConsultaDjenException(MotivoFalhaDjen::LimiteExcedido));
        $this->espera->expects($this->exactly(3))->method('aguardar');

        $resultado = $this->sut->sincronizarProcessosCobrindoTodos($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(['Processos cadastrados' => MotivoFalhaDjen::LimiteExcedido->value], $resultado->getFalhas());
        self::assertSame(2, $resultado->getProcessosAdiados());
    }

    #[Test]
    public function dryRunDoComandoNaoContaEmDobroOQueAFaseDeOabsJaCaptou(): void
    {
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')->willReturn(['50636766220224047000']);
        $this->djenClient->method('consultarComunicacoes')->willReturn(
            new RespostaComunicacoes(1, [$this->item(555, '50636766220224047000')])
        );
        // Em dry-run a fase de OABs não gravou nada: o banco não sabe da 555.
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);

        $resultado = $this->sut->sincronizarProcessosCobrindoTodos(
            $this->tenant,
            PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')),
            true,
            ['555'],
        );

        self::assertSame(0, $resultado->getNovas());
        self::assertSame(1, $resultado->getRecebidas());
    }

    #[Test]
    public function noComandoFalhaTransitoriaDeUmNumeroNaoDerrubaOsDemais(): void
    {
        $this->processoRepository->method('listarNumerosAcompanhadosDoTenant')
            ->willReturn(['11111111111111111111', '22222222222222222222']);
        $this->djenClient->method('consultarComunicacoes')->willReturnCallback(
            fn (ConsultaComunicacoesQuery $query): RespostaComunicacoes => $query->numeroProcesso === '11111111111111111111'
                ? throw new ConsultaDjenException(MotivoFalhaDjen::SistemaOcupado)
                : new RespostaComunicacoes(1, [$this->item(42, '22222222222222222222')])
        );
        $this->publicacaoRepository->method('filtrarDjenIdsExistentesDoTenant')->willReturn([]);
        $this->processoRepository->method('findByNumeroProcessoDoTenant')->willReturn($this->createMock(Processo::class));
        $this->notificador->expects($this->once())->method('notificar')->with($this->tenant, 1)->willReturn(1);

        $this->publicacaoRepository->expects($this->once())->method('salvar');
        $this->publicacaoRepository->expects($this->once())->method('flush');

        $resultado = $this->sut->sincronizarProcessosCobrindoTodos($this->tenant, PeriodoConsulta::dia(new \DateTimeImmutable('2026-07-06')));

        self::assertSame(1, $resultado->getNovas());
        self::assertSame(1, $resultado->getNotificados());
        self::assertSame(1, $resultado->getProcessosConsultados());
        self::assertArrayHasKey('Processo 11111111111111111111', $resultado->getFalhas());
    }

    private function oab(string $numero, string $uf): OabMonitorada
    {
        $oab = new OabMonitorada();
        $oab->setTenant($this->tenant);
        $oab->setNumero($numero);
        $oab->setUf($uf);

        return $oab;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $id, string $numeroProcesso): array
    {
        return [
            'id' => $id,
            'numero_processo' => $numeroProcesso,
            'siglaTribunal' => 'CJF',
            'tipoComunicacao' => 'Intimação',
            'data_disponibilizacao' => '2026-07-06',
        ];
    }
}
