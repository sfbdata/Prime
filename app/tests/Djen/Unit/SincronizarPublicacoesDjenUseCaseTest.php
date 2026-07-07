<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Exception\ConsultaDjenException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClientInterface;
use App\Djen\Service\DjenPublicacaoMapper;
use App\Djen\Service\NotificadorPublicacoesDjenInterface;
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
    private SincronizarPublicacoesDjenUseCase $sut;
    private Tenant&MockObject $tenant;

    protected function setUp(): void
    {
        $this->djenClient = $this->createMock(DjenClientInterface::class);
        $this->oabRepository = $this->createMock(OabMonitoradaRepository::class);
        $this->publicacaoRepository = $this->createMock(PublicacaoDjenRepository::class);
        $this->processoRepository = $this->createMock(ProcessoRepository::class);
        $this->notificador = $this->createMock(NotificadorPublicacoesDjenInterface::class);
        $this->tenant = $this->createMock(Tenant::class);

        $this->sut = new SincronizarPublicacoesDjenUseCase(
            $this->djenClient,
            new DjenPublicacaoMapper(),
            $this->oabRepository,
            $this->publicacaoRepository,
            $this->processoRepository,
            $this->notificador,
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
