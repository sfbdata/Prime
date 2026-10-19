<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\Armazenamento\ArmazenamentoComCota;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Exception\EspacoEsgotado;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

#[CoversClass(ArmazenamentoComCota::class)]
#[CoversClass(EspacoAtingiuFaixa::class)]
#[CoversClass(UsoDoEspacoSubiu::class)]
#[CoversClass(EventosDoEspacoAposConfirmar::class)]
final class ArmazenamentoComCotaTest extends TestCase
{
    private const GB = RetratoDoEspaco::BYTES_POR_GB;

    private ArmazenamentoEmMemoria $interno;

    /** @var list<object> */
    private array $disparados = [];

    private EventosDoEspacoAposConfirmar $eventos;

    protected function setUp(): void
    {
        $this->interno = new ArmazenamentoEmMemoria();
        $this->disparados = [];
    }

    #[TestDox('Subir de faixa (89% → 90%) dispara EspacoAtingiuFaixa com a faixa Alerta')]
    public function testSubirDeFaixaDisparaEvento(): void
    {
        // Limite do Master de 1 GB: o arquivo que vira a faixa tem ~11 MB, não ~110 MB.
        $cota = $this->decorador(usados: (int) (0.894 * self::GB), bloqueioAtivo: false, cotaGb: 1);

        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto(str_repeat('x', (int) (0.01 * self::GB))));
        self::assertSame([], $this->disparados, 'nada sai antes do commit');
        $this->terminarComSucesso();

        $faixas = array_values(array_filter($this->disparados, static fn (object $e): bool => $e instanceof EspacoAtingiuFaixa));
        $usos = array_values(array_filter($this->disparados, static fn (object $e): bool => $e instanceof UsoDoEspacoSubiu));
        self::assertCount(1, $faixas);
        self::assertCount(1, $usos);
        self::assertSame([89, 90], [$usos[0]->percentualAnterior, $usos[0]->percentualAtual]);
        self::assertTrue($usos[0]->cruzou(90));
        self::assertFalse($usos[0]->cruzou(85));
        $evento = $faixas[0];
        self::assertInstanceOf(EspacoAtingiuFaixa::class, $evento);
        self::assertSame(7, $evento->tenantId);
        self::assertSame(FaixaDoEspaco::Alerta, $evento->faixa);
        self::assertSame(FaixaDoEspaco::Atencao, $evento->faixaAnterior);
    }

    #[TestDox('Gravar sem mudar de percentual nem de faixa não dispara nada')]
    public function testMesmaFaixaNaoDispara(): void
    {
        $cota = $this->decorador(usados: 0, bloqueioAtivo: true);

        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('pequeno'));
        $this->terminarComSucesso();

        self::assertSame([], $this->disparados);
        self::assertCount(1, $this->interno->gravadas);
    }

    #[TestDox('Dez gravações seguidas fazem uma soma no banco, não dez')]
    public function testUsoMedidoUmaVezPorRequisicao(): void
    {
        $consumo = $this->createMock(ConsumoDeArmazenamentoRepository::class);
        $consumo->expects(self::once())->method('bytesDoEscritorio')->willReturn(0);
        $cota = $this->decorador(usados: 0, bloqueioAtivo: true, consumo: $consumo);

        for ($i = 0; $i < 10; ++$i) {
            $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('arquivo ' . $i));
        }

        self::assertCount(10, $this->interno->gravadas);
    }

    #[TestDox('As gravações da requisição contam: a que passa do limite é recusada e removida')]
    public function testGravacoesSeguidasSomam(): void
    {
        $cota = $this->decorador(usados: 10 * self::GB - 10, bloqueioAtivo: true);
        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('123456'));

        try {
            $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('123456'));
            self::fail('a segunda gravação passa do limite e deveria ser recusada');
        } catch (EspacoEsgotado) {
        }

        self::assertCount(1, $this->interno->excluidas, 'o arquivo recusado sai do armazenamento');
    }

    #[TestDox('Escopo global (foto de perfil) nem consulta o espaço')]
    public function testEscopoGlobalPassaDireto(): void
    {
        $consumo = $this->createMock(ConsumoDeArmazenamentoRepository::class);
        $consumo->expects(self::never())->method('bytesDoEscritorio');
        $cota = $this->decorador(usados: 0, bloqueioAtivo: true, consumo: $consumo);

        $cota->gravar(new NovoArquivo(EscopoDeArquivo::global(), CategoriaDeArquivo::FOTO_PERFIL, 'png'), FonteDeConteudo::deTexto('foto'));

        self::assertCount(1, $this->interno->gravadas);
    }

    #[TestDox('Regravar uma chave existente (recompressão) passa mesmo com o escritório cheio')]
    public function testRegravacaoPassaComEscritorioCheio(): void
    {
        $armazenado = $this->interno->gravar(self::novo(7), FonteDeConteudo::deTexto('original grande'));
        $cota = $this->decorador(usados: 50 * self::GB, bloqueioAtivo: true);

        $cota->gravar($armazenado->chave, FonteDeConteudo::deTexto('menor'));

        self::assertSame('menor', $this->interno->ler($armazenado->chave));
    }

    #[TestDox('Justificativa de ponto (não cobrada) passa com o escritório cheio e nem mede o uso')]
    public function testCategoriaNaoCobradaPassa(): void
    {
        $consumo = $this->createMock(ConsumoDeArmazenamentoRepository::class);
        $consumo->expects(self::never())->method('bytesDoEscritorio');
        $cota = $this->decorador(usados: 0, bloqueioAtivo: true, consumo: $consumo);

        $cota->gravar(new NovoArquivo(EscopoDeArquivo::deTenant(7), CategoriaDeArquivo::JUSTIFICATIVA_ANEXO, 'pdf'), FonteDeConteudo::deTexto('atestado'));

        self::assertCount(1, $this->interno->gravadas);
    }

    #[TestDox('reset() esvazia o uso memorizado: a próxima requisição mede de novo')]
    public function testResetMedeDeNovo(): void
    {
        $consumo = $this->createMock(ConsumoDeArmazenamentoRepository::class);
        $consumo->expects(self::exactly(2))->method('bytesDoEscritorio')->willReturn(0);
        $cota = $this->decorador(usados: 0, bloqueioAtivo: true, consumo: $consumo);

        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('um'));
        $cota->reset();
        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto('dois'));
    }

    #[TestDox('Trabalho que falha (comando com erro) descarta os eventos: nada de aviso por engano')]
    public function testFalhaDescartaEventos(): void
    {
        $cota = $this->decorador(usados: (int) (0.894 * self::GB), bloqueioAtivo: false, cotaGb: 1);
        $cota->gravar(self::novo(7), FonteDeConteudo::deTexto(str_repeat('x', (int) (0.01 * self::GB))));

        $this->eventos->aoTerminarComando(new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput(), 1));
        $this->terminarComSucesso();

        self::assertSame([], $this->disparados);
    }

    private function terminarComSucesso(): void
    {
        $this->eventos->aoTerminarComando(new ConsoleTerminateEvent(new Command('x'), new ArrayInput([]), new NullOutput(), 0));
    }

    private static function novo(int $tenantId): NovoArquivo
    {
        return new NovoArquivo(EscopoDeArquivo::deTenant($tenantId), CategoriaDeArquivo::KANBAN_ANEXO, 'pdf');
    }

    private function decorador(int $usados, bool $bloqueioAtivo, ?ConsumoDeArmazenamentoRepository $consumo = null, ?int $cotaGb = null): ArmazenamentoComCota
    {
        if ($consumo === null) {
            $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
            $consumo->method('bytesDoEscritorio')->willReturn($usados);
        }
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn(new Plano('free', 'Free', 10, 0, 'Free', 1));
        $configuracao = new ConfiguracaoDoEspaco();
        $configuracao->ligarBloqueio($bloqueioAtivo);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn($configuracao);
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        if ($cotaGb !== null) {
            $espaco = new EspacoDoEscritorio(new Tenant(), new Plano('free', 'Free', 10, 0, 'Free', 1));
            $espaco->definirLimite($cotaGb, false, 'teste', null);
            $espacos->method('doTenant')->willReturn($espaco);
        }
        $situacao = new SituacaoDoEspaco($consumo, $espacos, $planos, $configuracoes);

        $despachante = $this->createStub(EventDispatcherInterface::class);
        $despachante->method('dispatch')->willReturnCallback(function (object $evento): object {
            $this->disparados[] = $evento;

            return $evento;
        });
        $this->eventos = new EventosDoEspacoAposConfirmar($despachante);

        return new ArmazenamentoComCota($this->interno, $consumo, $situacao, $this->eventos, new NullLogger());
    }
}
