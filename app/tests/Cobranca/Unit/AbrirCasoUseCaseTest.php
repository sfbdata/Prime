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
    public function abreCasoAtivoComSnapshotDeHonorariosERegistraEvento(): void
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
        // Snapshot da regra de honorários copiado da carteira (SPEC §18.2).
        self::assertSame(FormaHonorarios::AcrescidoDivida, $caso->getFormaHonorarios());
        self::assertSame('10.00', $caso->getPercentualHonorarios());
        // Caso nasce ativo (status default).
        self::assertTrue($caso->estaAtivo());
    }

    #[Test]
    public function copiaOsNoveCamposDeEncargosDaCarteiraParaOCaso(): void
    {
        $carteira = $this->carteiraComEncargos();
        $caso = $this->abrirCasoPara($carteira);

        // Snapshot dos encargos (nível 2 da cascata): o caso nasce com a config vigente na carteira.
        // Todos os valores são não-default no CasoCobranca (que nasce com os 9 em null): se um
        // setter faltar, a asserção acusa null.
        self::assertSame(100, $caso->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $caso->getRegimeJuros());
        self::assertSame(200, $caso->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $caso->getBaseMulta());
        self::assertSame(50, $caso->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $caso->getBaseCorrecao());
        self::assertSame(BaseEncargo::Principal, $caso->getBaseHonorarios());
        self::assertSame(30, $caso->getCarenciaHonorariosDias());
        self::assertSame(5, $caso->getToleranciaJurosMultaDias());
    }

    #[Test]
    public function encargosSaoCopiaNoNascimentoEMudarACarteiraDepoisNaoAfetaOCasoAberto(): void
    {
        $carteira = $this->carteiraComEncargos();
        $caso = $this->abrirCasoPara($carteira);

        // O gestor reconfigura a carteira DEPOIS de o caso já estar aberto (spec §5).
        $carteira
            ->setTaxaJurosMensalBp(900)
            ->setRegimeJuros(RegimeJuros::Simples)
            ->setTaxaMultaBp(1000)
            ->setBaseMulta(BaseEncargo::Principal)
            ->setTaxaCorrecaoBp(700)
            ->setBaseCorrecao(BaseEncargo::Principal)
            ->setBaseHonorarios(BaseEncargo::Composta)
            ->setCarenciaHonorariosDias(90)
            ->setToleranciaJurosMultaDias(60);

        // O caso já aberto continua com o que copiou: a nova config só vale para os PRÓXIMOS casos.
        self::assertSame(100, $caso->getTaxaJurosMensalBp());
        self::assertSame(RegimeJuros::Composto, $caso->getRegimeJuros());
        self::assertSame(200, $caso->getTaxaMultaBp());
        self::assertSame(BaseEncargo::Composta, $caso->getBaseMulta());
        self::assertSame(50, $caso->getTaxaCorrecaoBp());
        self::assertSame(BaseEncargo::Composta, $caso->getBaseCorrecao());
        self::assertSame(BaseEncargo::Principal, $caso->getBaseHonorarios());
        self::assertSame(30, $caso->getCarenciaHonorariosDias());
        self::assertSame(5, $caso->getToleranciaJurosMultaDias());
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
