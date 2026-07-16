<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\LiquidacaoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\Service\AlertasCobranca;
use App\Cobranca\Service\CalculadoraSaldo;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Ajuste 10: o mapa `obrigacaoId => alocado` do caso inteiro tem que sair de UMA query, nunca de
 * um loop por obrigação — mesmo padrão do `MontarDetalheAcordoUseCase` (ajuste 7). Este teste trava
 * exatamente esse ponto (contagem de chamadas ao repositório), sem exercitar as demais regras do
 * UseCase (já cobertas indiretamente pelos testes Functional existentes).
 */
#[CoversClass(MontarDetalheCasoUseCase::class)]
final class MontarDetalheCasoUseCaseTest extends TestCase
{
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private PagamentoRepository&MockObject $pagamentoRepository;
    private LiquidacaoRepository&MockObject $liquidacaoRepository;
    private AcordoRepository&MockObject $acordoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private ProximaAcaoRepository&MockObject $proximaAcaoRepository;
    private CalculadoraSaldo&MockObject $calculadoraSaldo;
    private AlertasCobranca $alertasCobranca;
    private AlocacaoPagamentoRepository&MockObject $alocacaoRepository;
    private MontarDetalheCasoUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->pagamentoRepository = $this->createMock(PagamentoRepository::class);
        $this->liquidacaoRepository = $this->createMock(LiquidacaoRepository::class);
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $this->proximaAcaoRepository = $this->createMock(ProximaAcaoRepository::class);
        $this->calculadoraSaldo = $this->createMock(CalculadoraSaldo::class);
        $this->alocacaoRepository = $this->createMock(AlocacaoPagamentoRepository::class);

        // Defaults neutros: só o comportamento sob teste (as alocações) importa aqui.
        $this->obrigacaoRepository->method('doCaso')->willReturn([]);
        $this->obrigacaoRepository->method('doCasoExigiveis')->willReturn([]);
        $this->pagamentoRepository->method('doCaso')->willReturn([]);
        $this->liquidacaoRepository->method('doCaso')->willReturn([]);
        $this->acordoRepository->method('doCaso')->willReturn([]);
        $this->eventoRepository->method('doCaso')->willReturn([]);
        $this->proximaAcaoRepository->method('findAtivaDoCaso')->willReturn(null);
        $this->calculadoraSaldo->method('saldoExigivel')->willReturn(0);
        $this->calculadoraSaldo->method('saldoVencido')->willReturn(0);

        // AlertasCobranca é `final` (não pode ser mockada) — instancia REAL sobre os mesmos mocks
        // de repositório/serviço já usados acima (nenhuma query nova além das já stubadas).
        $this->alertasCobranca = new AlertasCobranca(
            $this->obrigacaoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
        );

        $this->useCase = new MontarDetalheCasoUseCase(
            $this->obrigacaoRepository,
            $this->pagamentoRepository,
            $this->liquidacaoRepository,
            $this->acordoRepository,
            $this->eventoRepository,
            $this->proximaAcaoRepository,
            $this->calculadoraSaldo,
            $this->alertasCobranca,
            $this->alocacaoRepository,
        );

        $this->tenant = new Tenant();
    }

    #[Test]
    public function carrega_as_alocacoes_em_uma_unica_query(): void
    {
        $caso = $this->casoPersistido();

        $this->alocacaoRepository
            ->expects(self::once())            // ← o ponto do teste: UMA vez, não uma por obrigação
            ->method('somasPorObrigacaoDosCasos')
            ->with([$caso->getId()], $caso->getTenant())
            ->willReturn([]);

        $this->useCase->executar($caso);
    }

    private function casoPersistido(): CasoCobranca
    {
        $objeto = new ObjetoCobranca();
        (new \ReflectionProperty(ObjetoCobranca::class, 'id'))->setValue($objeto, 77);

        $caso = (new CasoCobranca())->setTenant($this->tenant)->setObjeto($objeto);
        (new \ReflectionProperty(CasoCobranca::class, 'id'))->setValue($caso, 50);

        return $caso;
    }
}
