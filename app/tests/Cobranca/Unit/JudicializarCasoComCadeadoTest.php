<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cliente\Repository\ClientePFRepository;
use App\Cobranca\DTO\JudicializarCasoInput;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\ComporNomeDaPastaJudicial;
use App\Cobranca\Service\NormalizadorDePastaJudicial;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Cobranca\Service\ResolvedorClienteDoResponsavel;
use App\Cobranca\UseCase\JudicializarCasoUseCase;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\CriarPastaUseCase;
use App\Pasta\UseCase\GerarNumeroDePasta;
use App\Tests\Pasta\Unit\Cadeado\CriaCenarioDoCadeadoTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Cadeado (spec pasta-cadeado): vincular um caso a uma pasta normaliza a pasta (é escrita nela). Quem não
 * edita a pasta pelo cadeado é recusado antes de qualquer gravação.
 */
#[CoversClass(JudicializarCasoUseCase::class)]
final class JudicializarCasoComCadeadoTest extends TestCase
{
    use CriaCenarioDoCadeadoTrait;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    /** @return iterable<string, array{0: ?NivelDeAcesso}> */
    public static function niveisQueNaoEditam(): iterable
    {
        yield 'oculto (cadeado Todos)' => [null];
        yield 'só visualizar' => [NivelDeAcesso::Visualizar];
    }

    #[DataProvider('niveisQueNaoEditam')]
    #[TestDox('vincular pasta com cadeado em que a pessoa não edita é recusado, sem gravar nada')]
    public function testVincularRecusado(?NivelDeAcesso $nivel): void
    {
        $gestor = $this->pessoa('Gestor da Cobrança');
        $pasta  = $this->pasta('3000');
        $cadeado = new CadeadoDaPasta($this->tenant, $pasta, $nivel === null ? ModoDoCadeado::Todos : ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        if ($nivel !== null) {
            $cadeado->definirPorPessoa([[$gestor, $nivel]], null, new \DateTimeImmutable());
        }
        $this->gravarCadeado($cadeado);

        $casos = $this->createMock(CasoCobrancaRepository::class);
        $casos->method('findOneByIdDoTenant')->willReturn((new CasoCobranca())->setTenant($this->tenant));
        $casos->expects(self::never())->method('salvar');
        $pastas = $this->createMock(PastaRepository::class);
        $pastas->method('findOneBy')->willReturn($pasta);

        $input = new JudicializarCasoInput();
        $input->casoId = 1;
        $input->modo = JudicializarCasoInput::MODO_VINCULAR;
        $input->pastaId = (int) $pasta->getId();

        $this->expectException(SemPermissaoParaEditarPastaException::class);
        $this->useCase($casos, $pastas)->executar($input, $this->tenant, $gestor);
    }

    private function useCase(CasoCobrancaRepository $casos, PastaRepository $pastas): JudicializarCasoUseCase
    {
        $comporNome = new ComporNomeDaPastaJudicial();

        return new JudicializarCasoUseCase(
            $casos,
            $pastas,
            new RegistrarEventoHistorico($this->createMock(EventoHistoricoRepository::class)),
            new CriarPastaUseCase($this->createMock(EntityManagerInterface::class), new GerarNumeroDePasta($this->createMock(NumeracaoDePastaInterface::class)), $this->createStub(PastaRepository::class)),
            $comporNome,
            new NormalizadorDePastaJudicial($comporNome, new ResolvedorClienteDoResponsavel($this->createMock(ClientePFRepository::class), new \App\Cliente\Service\HistoricoDoCliente($this->createMock(\App\Cliente\Repository\ClienteHistoricoRepository::class)))),
            $this->acessoAPasta(),
        );
    }
}
