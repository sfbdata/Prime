<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AbrirCasoInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\FormaHonorarios;
use App\Cobranca\Enum\ModoCarteira;
use App\Cobranca\Enum\RegimeJuros;
use App\Cobranca\Exception\CasoAtivoJaExisteException;
use App\Cobranca\Exception\ObjetoNaoEncontradoException;
use App\Cobranca\Exception\PessoaNaoEncontradaException;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\ObjetoCobrancaRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\AbrirCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbrirCasoUseCase::class)]
final class AbrirCasoUseCaseTest extends TestCase
{
    private CasoCobrancaRepository&MockObject $casoRepository;
    private ObjetoCobrancaRepository&MockObject $objetoRepository;
    private PessoaRepository&MockObject $pessoaRepository;
    private EventoHistoricoRepository&MockObject $eventoRepository;
    private AbrirCasoUseCase $sut;
    private Tenant $tenant;
    private User $criadoPor;

    protected function setUp(): void
    {
        $this->casoRepository = $this->createMock(CasoCobrancaRepository::class);
        $this->objetoRepository = $this->createMock(ObjetoCobrancaRepository::class);
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        // O serviço é final (não-mockável): usa-se o REAL com o repositório de eventos mockado,
        // validando o flush único via a chamada salvar(EventoHistorico, true).
        $this->eventoRepository = $this->createMock(EventoHistoricoRepository::class);
        $registrarEvento = new RegistrarEventoHistorico($this->eventoRepository);
        $this->sut = new AbrirCasoUseCase(
            $this->casoRepository,
            $this->objetoRepository,
            $this->pessoaRepository,
            $registrarEvento,
        );
        // Tenant/User não são abstrações do domínio: instâncias reais, não mocks.
        $this->tenant = new Tenant();
        $this->criadoPor = new User();
    }

    #[Test]
    public function abreCasoAtivoSemSnapshotDeHonorariosERegistraEvento(): void
    {
        $carteira = (new Carteira())
            ->setModo(ModoCarteira::Multiplo)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('10.00');
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);
        $pessoa = new Pessoa();

        $this->objetoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(50, $this->tenant)
            ->willReturn($objeto);

        $this->pessoaRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(70, $this->tenant)
            ->willReturn($pessoa);

        // Modo B: a guarda de caso único não se aplica.
        $this->casoRepository->method('existeCasoAtivoParaObjeto')->willReturn(false);

        // Caso persistido sem flush; o evento fecha a transação com flush: true.
        $this->casoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(CasoCobranca::class));

        $this->eventoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(EventoHistorico::class), true);

        $input = new AbrirCasoInput();
        $input->objetoId = 50;
        $input->pessoaCobradaId = 70;

        $caso = $this->sut->executar($input, $this->tenant, $this->criadoPor);

        self::assertSame($objeto, $caso->getObjeto());
        self::assertSame($pessoa, $caso->getPessoaCobradaAtual());
        self::assertSame($this->tenant, $caso->getTenant());
        self::assertSame($this->criadoPor, $caso->getCriadoPor());
        // #9-T2: SEM snapshot — o caso nasce com as colunas de honorários no default MORTO, mesmo a
        // carteira tendo forma/percentual configurados. A alíquota efetiva cascateia ao vivo por fora
        // (ResolvedorConfigEncargos/CalculadoraHonorarios), não por cópia aqui.
        self::assertSame(FormaHonorarios::SemPercentual, $caso->getFormaHonorarios());
        self::assertNull($caso->getPercentualHonorarios());
        // Caso nasce ativo (status default).
        self::assertTrue($caso->estaAtivo());
    }

    #[Test]
    public function naoCopiaMaisOsNoveCamposDeEncargosDaCarteiraParaOCaso(): void
    {
        $carteira = $this->carteiraComEncargos();
        $caso = $this->abrirCasoPara($carteira);

        // #9-T2 (reverte a decisão §18.2/§18.3 da feature de encargos, spec §3.2): o caso NÃO copia
        // mais os 9 campos da carteira — nascem no default MORTO do CasoCobranca (todos null), mesmo
        // a carteira estando totalmente configurada. A config efetiva cascateia ao vivo via objeto.
        self::assertNull($caso->getTaxaJurosMensalBp());
        self::assertNull($caso->getRegimeJuros());
        self::assertNull($caso->getTaxaMultaBp());
        self::assertNull($caso->getBaseMulta());
        self::assertNull($caso->getTaxaCorrecaoBp());
        self::assertNull($caso->getBaseCorrecao());
        self::assertNull($caso->getBaseHonorarios());
        self::assertNull($caso->getCarenciaHonorariosDias());
        self::assertNull($caso->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function semSnapshotACascataAoVivoContinuaResolvendoAConfigDaCarteiraViaObjeto(): void
    {
        // A ausência do snapshot (T2) não é perda de comportamento: `ResolvedorConfigEncargos` (T1)
        // resolve a MESMA config, ao vivo, navegando caso→objeto→carteira — é o que substitui a cópia.
        $carteira = $this->carteiraComEncargos();
        $caso = $this->abrirCasoPara($carteira);

        $config = (new ResolvedorConfigEncargos())->resolverDoCaso($caso);

        self::assertSame(100, $config->taxaJurosMensalBp);
        self::assertSame(RegimeJuros::Composto, $config->regimeJuros);
        self::assertSame(200, $config->taxaMultaBp);
        self::assertSame(BaseEncargo::Composta, $config->baseMulta);
        self::assertSame(50, $config->taxaCorrecaoBp);
        self::assertSame(BaseEncargo::Composta, $config->baseCorrecao);
        self::assertSame(2000, $config->taxaHonorariosBp, 'honorários (20% na carteira) herdados ao vivo, não copiados');
        self::assertSame(BaseEncargo::Principal, $config->baseHonorarios);
        self::assertSame(30, $config->carenciaHonorariosDias);
        self::assertSame(5, $config->toleranciaJurosMultaDias);

        // E, sem UPDATE nenhum no caso: reconfigurar a carteira DEPOIS reflete imediatamente — é o
        // ganho da T1/T2 sobre o snapshot antigo (que travava o caso na config do nascimento).
        $carteira->setTaxaJurosMensalBp(900);
        self::assertSame(900, (new ResolvedorConfigEncargos())->resolverDoCaso($caso)->taxaJurosMensalBp);
    }

    #[Test]
    public function rejeitaSegundoCasoAtivoNoModoUnico(): void
    {
        $carteira = (new Carteira())->setModo(ModoCarteira::Unico);
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn($objeto);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(new Pessoa());
        $this->casoRepository->method('existeCasoAtivoParaObjeto')->willReturn(true);

        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(CasoAtivoJaExisteException::class);

        $input = new AbrirCasoInput();
        $input->objetoId = 50;
        $input->pessoaCobradaId = 70;

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaObjetoDeOutroTenant(): void
    {
        // Objeto inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(ObjetoNaoEncontradoException::class);

        $input = new AbrirCasoInput();
        $input->objetoId = 999;
        $input->pessoaCobradaId = 70;

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    #[Test]
    public function rejeitaPessoaDeOutroTenant(): void
    {
        // Objeto ok, mas a pessoa não é do escritório: findOneByIdDoTenant devolve null.
        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn(new ObjetoCobranca());
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->casoRepository->expects($this->never())->method('salvar');
        $this->eventoRepository->expects($this->never())->method('salvar');

        $this->expectException(PessoaNaoEncontradaException::class);

        $input = new AbrirCasoInput();
        $input->objetoId = 50;
        $input->pessoaCobradaId = 999;

        $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }

    /** Carteira com os 9 encargos configurados, todos fora do default do CasoCobranca (null). */
    private function carteiraComEncargos(): Carteira
    {
        return (new Carteira())
            ->setModo(ModoCarteira::Multiplo)
            ->setFormaHonorarios(FormaHonorarios::AcrescidoDivida)
            ->setPercentualHonorarios('20.00')
            ->setTaxaJurosMensalBp(100)
            ->setRegimeJuros(RegimeJuros::Composto)
            ->setTaxaMultaBp(200)
            ->setBaseMulta(BaseEncargo::Composta)
            ->setTaxaCorrecaoBp(50)
            ->setBaseCorrecao(BaseEncargo::Composta)
            ->setBaseHonorarios(BaseEncargo::Principal)
            ->setCarenciaHonorariosDias(30)
            ->setToleranciaJurosMultaDias(5);
    }

    /** Abre um caso no caminho feliz para a carteira dada (modo B, sem caso ativo concorrente). */
    private function abrirCasoPara(Carteira $carteira): CasoCobranca
    {
        $objeto = (new ObjetoCobranca())->setCarteira($carteira);

        $this->objetoRepository->method('findOneByIdDoTenant')->willReturn($objeto);
        $this->pessoaRepository->method('findOneByIdDoTenant')->willReturn(new Pessoa());
        $this->casoRepository->method('existeCasoAtivoParaObjeto')->willReturn(false);

        $input = new AbrirCasoInput();
        $input->objetoId = 50;
        $input->pessoaCobradaId = 70;

        return $this->sut->executar($input, $this->tenant, $this->criadoPor);
    }
}
