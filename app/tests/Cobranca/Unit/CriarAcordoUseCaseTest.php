<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\CriarAcordoInput;
use App\Cobranca\DTO\ParcelaAcordoInput;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoJaSubstituidaException;
use App\Cobranca\Exception\ParcelamentoInvalidoException;
use App\Cobranca\Repository\AcordoRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\UseCase\CriarAcordoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarAcordoUseCase::class)]
final class CriarAcordoUseCaseTest extends TestCase
{
    private AcordoRepository&MockObject $acordoRepository;
    private ObrigacaoRepository&MockObject $obrigacaoRepository;
    private CasoCobrancaRepository&MockObject $casoRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private CriarAcordoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->acordoRepository = $this->createMock(AcordoRepository::class);
        $this->obrigacaoRepository = $this->createMock(ObrigacaoRepository::class);
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        // O serviço é final (não-mockável): usa-se o REAL com o repositório de eventos mockado,
        // validando o flush único via a chamada salvar(EventoHistorico, true).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new CriarAcordoUseCase(
            $this->acordoRepository,
            $this->obrigacaoRepository,
            $this->casoRepository,
            $registrarEvento,
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function criaAcordoComSubstituicaoParcialEParcelas(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        // Substituição PARCIAL: só a obrigação 100 é substituída; a outra fica intacta.
        $obrigSubstituida = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $obrigMantida = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(70, $this->tenant)
            ->willReturn($caso);

        $this->obrigacaoRepository
            ->method('findOneByIdDoTenant')
            ->with(100, $this->tenant)
            ->willReturn($obrigSubstituida);

        // Captura tudo que é persistido em Obrigacao: a substituída (marcada) + as 2 parcelas novas.
        $persistidas = [];
        $this->obrigacaoRepository
            ->method('salvar')
            ->willReturnCallback(function (Obrigacao $obrigacao) use (&$persistidas): void {
                $persistidas[] = $obrigacao;
            });

        // Acordo persistido sem flush; o evento fecha a transação com flush: true.
        $this->acordoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(Acordo::class));
        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $acordo = $this->sut->executar(
            $this->novoInput(70, [100], [
                $this->parcela('  Parcela 1/2  ', 50000, '2026-05-10'),
                $this->parcela('Parcela 2/2', 50000, '2026-06-10'),
            ]),
            $this->tenant,
            $this->criadoPor,
        );

        // A obrigação substituída recebe o acordo como substituto e NÃO é removida (invariável 14).
        self::assertSame($acordo, $obrigSubstituida->getAcordoSubstituto());
        self::assertTrue($obrigSubstituida->foiSubstituida());
        // A obrigação mantida fica intacta (substituição parcial não a toca).
        self::assertNull($obrigMantida->getAcordoSubstituto());
        self::assertFalse($obrigMantida->foiSubstituida());

        // Persistidas: 1 substituída (marcada) + 2 parcelas novas.
        self::assertCount(3, $persistidas);

        $substituidasPersistidas = array_values(array_filter(
            $persistidas,
            static fn (Obrigacao $o): bool => !$o->ehParcela(),
        ));
        self::assertCount(1, $substituidasPersistidas);
        self::assertSame($obrigSubstituida, $substituidasPersistidas[0]);

        // As parcelas geradas apontam para o acordo de origem e são do mesmo caso/tenant.
        $parcelas = array_values(array_filter(
            $persistidas,
            static fn (Obrigacao $o): bool => $o->ehParcela(),
        ));
        self::assertCount(2, $parcelas);
        foreach ($parcelas as $parcela) {
            self::assertSame($acordo, $parcela->getAcordoOrigem());
            self::assertSame($caso, $parcela->getCaso());
            self::assertSame($this->tenant, $parcela->getTenant());
            self::assertSame($this->criadoPor, $parcela->getCriadoPor());
        }
        // Descrição normalizada (trim) e valor em centavos preservado.
        self::assertSame('Parcela 1/2', $parcelas[0]->getDescricao());
        self::assertSame(50000, $parcelas[0]->getValorOriginal());

        // O acordo criado é do caso/tenant/usuário e nasce ativo (status default).
        self::assertSame($caso, $acordo->getCaso());
        self::assertSame($this->tenant, $acordo->getTenant());
        self::assertSame($this->criadoPor, $acordo->getCriadoPor());
        self::assertTrue($acordo->estaAtivo());
    }

    #[Test]
    public function gravaSnapshotComTotalNegociadoEEntradaComoPrimeiraObrigacao(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigSubstituida = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigSubstituida);

        $persistidas = [];
        $this->obrigacaoRepository
            ->method('salvar')
            ->willReturnCallback(function (Obrigacao $obrigacao) use (&$persistidas): void {
                $persistidas[] = $obrigacao;
            });

        // Total 1.000,00 = entrada 400,00 + 2 parcelas de 300,00.
        $input = $this->novoInput(70, [100], [
            $this->parcela('Parcela 1/2', 30000, '2026-05-10'),
            $this->parcela('Parcela 2/2', 30000, '2026-06-10'),
        ]);
        $input->valorEntrada = 40000;
        $input->valorTotalNegociado = 100000;
        $input->dataEntrada = new \DateTimeImmutable('2026-04-20');

        $acordo = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame(100000, $acordo->getValorTotalNegociado());
        self::assertSame(40000, $acordo->getValorEntrada());

        // A entrada foi criada como obrigação-parcela do acordo (descrição "Entrada").
        $parcelas = array_values(array_filter($persistidas, static fn (Obrigacao $o): bool => $o->ehParcela()));
        self::assertCount(3, $parcelas); // entrada + 2 parcelas
        $entrada = array_values(array_filter($parcelas, static fn (Obrigacao $o): bool => $o->getDescricao() === 'Entrada'));
        self::assertCount(1, $entrada);
        self::assertSame(40000, $entrada[0]->getValorOriginal());
        self::assertSame($acordo, $entrada[0]->getAcordoOrigem());
        self::assertSame('2026-04-20', $entrada[0]->getVencimentoOriginal()->format('Y-m-d'));
    }

    #[Test]
    public function derivaTotalNegociadoQuandoNaoInformado(): void
    {
        // Modal antigo (sem total nem entrada): o snapshot é derivado da soma das parcelas.
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrig = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrig);

        $acordo = $this->sut->executar(
            $this->novoInput(70, [100], [
                $this->parcela('Parcela 1/2', 50000, '2026-05-10'),
                $this->parcela('Parcela 2/2', 50000, '2026-06-10'),
            ]),
            $this->tenant,
            $this->criadoPor,
        );

        self::assertSame(100000, $acordo->getValorTotalNegociado());
        self::assertSame(0, $acordo->getValorEntrada());
    }

    #[Test]
    public function rejeitaTotalNegociadoQueNaoFechaComEntradaMaisParcelas(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $obrig = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrig);
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $input = $this->novoInput(70, [100], [$this->parcela('Parcela 1/1', 50000, '2026-05-10')]);
        $input->valorEntrada = 10000;
        $input->valorTotalNegociado = 100000; // ≠ 10000 + 50000

        $this->expectException(ParcelamentoInvalidoException::class);

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaSubstituicaoDeObrigacaoDeOutroCaso(): void
    {
        // Invariável 13: a obrigação a substituir é de OUTRO caso (outra instância).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $outroCaso = (new CasoCobranca())->setTenant($this->tenant);
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($outroCaso);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoDeOutroCasoException::class);

        $this->sut->executar(
            $this->novoInput(70, [100], [$this->parcela('Parcela', 1000, '2026-05-10')]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function rejeitaSubstituicaoDeObrigacaoJaSubstituidaPorAcordoVigente(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        // Acordo anterior VIGENTE (ativo): a obrigação segue fora do exigível, não pode ser re-substituída.
        $acordoAnterior = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        self::assertTrue($acordoAnterior->getStatus()->ehVigente());
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $obrigacao->setAcordoSubstituto($acordoAnterior);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObrigacaoJaSubstituidaException::class);

        $this->sut->executar(
            $this->novoInput(70, [100], [$this->parcela('Parcela', 1000, '2026-05-10')]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function permiteResubstituirObrigacaoDeAcordoRompido(): void
    {
        // Obrigação substituída por um acordo ROMPIDO voltou ao exigível → pode ser renegociada
        // num novo acordo (a marcação `acordoSubstituto` antiga aponta para um acordo não vigente).
        $caso = (new CasoCobranca())->setTenant($this->tenant);
        $acordoRompido = (new Acordo())->setTenant($this->tenant)->setCaso($caso);
        $acordoRompido->romper('Devedor parou de pagar');
        self::assertFalse($acordoRompido->getStatus()->ehVigente());
        $obrigacao = (new Obrigacao())->setTenant($this->tenant)->setCaso($caso);
        $obrigacao->setAcordoSubstituto($acordoRompido);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->method('findOneByIdDoTenant')->willReturn($obrigacao);

        $acordo = $this->sut->executar(
            $this->novoInput(70, [100], [$this->parcela('Nova parcela', 1000, '2026-06-10')]),
            $this->tenant,
            $this->criadoPor,
        );

        // Re-substituição aceita: a obrigação passa a apontar para o NOVO acordo (o rompido fica no histórico).
        self::assertSame($acordo, $obrigacao->getAcordoSubstituto());
    }

    #[Test]
    public function rejeitaAcordoEmCasoEncerrado(): void
    {
        $caso = (new CasoCobranca())->setTenant($this->tenant)->setStatus(StatusCaso::Encerrado);

        $this->casoRepository->method('findOneByIdDoTenant')->willReturn($caso);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoEncerradoException::class);

        $this->sut->executar(
            $this->novoInput(70, [100], [$this->parcela('Parcela', 1000, '2026-05-10')]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    #[Test]
    public function rejeitaCasoDeOutroTenant(): void
    {
        // Caso inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->casoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->obrigacaoRepository->expects($this->never())->method('salvar');
        $this->acordoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoNaoEncontradoException::class);

        $this->sut->executar(
            $this->novoInput(999, [100], [$this->parcela('Parcela', 1000, '2026-05-10')]),
            $this->tenant,
            $this->criadoPor,
        );
    }

    /**
     * @param int[]                 $obrigacoesIds
     * @param ParcelaAcordoInput[]  $parcelas
     */
    private function novoInput(int $casoId, array $obrigacoesIds, array $parcelas): CriarAcordoInput
    {
        $input = new CriarAcordoInput();
        $input->casoId = $casoId;
        $input->dataAcordo = new \DateTimeImmutable('2026-04-20');
        $input->obrigacoesSubstituidasIds = $obrigacoesIds;
        $input->parcelas = $parcelas;

        return $input;
    }

    private function parcela(string $descricao, int $valor, string $vencimento): ParcelaAcordoInput
    {
        $item = new ParcelaAcordoInput();
        $item->descricao = $descricao;
        $item->valor = $valor;
        $item->vencimento = new \DateTimeImmutable($vencimento);

        return $item;
    }
}
