<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\DTO\ResultadoSincronizacao;
use App\Djen\Enum\MotivoFalhaDjen;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Djen\Service\DjenClientInterface;
use App\Djen\Service\DjenPublicacaoMapper;
use App\Djen\Service\EsperaEntreConsultasInterface;
use App\Djen\Service\NotificadorPublicacoesDjenInterface;
use App\Djen\UseCase\ReconciliarPublicacoesComProcessosUseCase;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\BuscaNoPushOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\UseCase\AlternarMonitoramentoDaPastaUseCase;
use App\Pasta\UseCase\BuscarAgoraNoPushUseCase;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * O switch do monitoramento e o "Buscar agora" do painel do Push (spec push-painel-monitoramento
 * §1 e §2), com o DJEN e os repositórios dublados.
 */
#[CoversClass(AlternarMonitoramentoDaPastaUseCase::class)]
#[CoversClass(BuscarAgoraNoPushUseCase::class)]
#[CoversClass(BuscaNoPushOutput::class)]
final class MonitoramentoDoPushUseCasesTest extends TestCase
{
    private const AGORA = '2026-10-19 10:00:00';

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    private function processo(string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($this->tenant);
        $processo->setNumeroProcesso($numero);

        return $processo;
    }

    private function pasta(Processo ...$processos): Pasta
    {
        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);
        foreach ($processos as $processo) {
            $pasta->vincularProcesso($processo);
        }

        return $pasta;
    }

    private function alternar(): AlternarMonitoramentoDaPastaUseCase
    {
        return new AlternarMonitoramentoDaPastaUseCase(
            $this->createMock(ProcessoRepository::class),
            new MockClock(new \DateTimeImmutable(self::AGORA)),
        );
    }

    /** @param list<string> $consultados recebe os números consultados no DJEN */
    private function buscar(array &$consultados, ?\Closure $resposta = null): BuscarAgoraNoPushUseCase
    {
        $djen = $this->createMock(DjenClientInterface::class);
        $djen->method('consultarComunicacoes')->willReturnCallback(
            function (ConsultaComunicacoesQuery $q) use (&$consultados, $resposta): RespostaComunicacoes {
                $consultados[] = $q->numeroProcesso;

                return $resposta !== null ? $resposta($q) : new RespostaComunicacoes(0, []);
            },
        );
        $publicacoes = $this->createMock(PublicacaoDjenRepository::class);
        $processos = $this->createMock(ProcessoRepository::class);
        $sincronizar = new SincronizarPublicacoesDjenUseCase(
            $djen,
            new DjenPublicacaoMapper(),
            $this->createMock(OabMonitoradaRepository::class),
            $publicacoes,
            $processos,
            $this->createMock(NotificadorPublicacoesDjenInterface::class),
            new ReconciliarPublicacoesComProcessosUseCase($publicacoes, $processos),
            $this->createMock(EsperaEntreConsultasInterface::class),
        );

        return new BuscarAgoraNoPushUseCase($sincronizar, new MockClock(new \DateTimeImmutable(self::AGORA)));
    }

    #[TestDox('Alternar: pausa o processo principal (quem e quando) e retoma na segunda vez, com os textos do desenho')]
    public function testAlternaOProcessoPrincipal(): void
    {
        $principal = $this->processo('07011345720258070007');
        $outro = $this->processo('07099999999999999999');
        $pasta = $this->pasta($principal, $outro);
        $usuario = new User();

        $pausado = $this->alternar()->executar($pasta, $usuario, $this->tenant);

        self::assertFalse($pausado->ativo);
        self::assertSame('Monitoramento pausado', $pausado->statusTxt);
        self::assertSame('Novas movimentações não serão capturadas', $pausado->statusSub);
        self::assertSame($usuario, $principal->getMonitoramentoPausadoPor());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $principal->getMonitoramentoPausadoEm());
        self::assertTrue($outro->monitoramentoAtivo(), 'só o principal: o desenho não tem switch por apensado');

        $ativo = $this->alternar()->executar($pasta, $usuario, $this->tenant);

        self::assertTrue($ativo->ativo);
        self::assertSame('Monitoramento ativo', $ativo->statusTxt);
        self::assertNull($principal->getMonitoramentoPausadoPor());
    }

    #[TestDox('Alternar sem processo vinculado é recusado')]
    public function testAlternarSemProcesso(): void
    {
        $this->expectException(\DomainException::class);

        $this->alternar()->executar($this->pasta(), new User(), $this->tenant);
    }

    #[TestDox('Alternar pasta de outro escritório é recusado')]
    public function testAlternarOutroEscritorio(): void
    {
        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->alternar()->executar($this->pasta($this->processo('07011345720258070007')), new User(), new Tenant());
    }

    #[TestDox('Buscar agora: consulta só os processos da pasta com monitoramento ativo')]
    public function testBuscarSoOsAtivos(): void
    {
        $principal = $this->processo('0701134-57.2025.8.07.0007');
        $pausado = $this->processo('07099999999999999999');
        $pausado->pausarMonitoramento(new User(), new \DateTimeImmutable());
        $consultados = [];

        $busca = $this->buscar($consultados)->executar($this->pasta($principal, $pausado), $this->tenant);

        self::assertSame(['07011345720258070007'], $consultados);
        self::assertSame('Nenhuma movimentação nova', $busca->mensagem);
        self::assertSame(BuscaNoPushOutput::TOM_SUCESSO, $busca->tom);
    }

    #[TestDox('Buscar agora com o principal pausado é recusado (o botão fica desabilitado)')]
    public function testBuscarPausado(): void
    {
        $principal = $this->processo('07011345720258070007');
        $principal->pausarMonitoramento(new User(), new \DateTimeImmutable());
        $consultados = [];

        $this->expectException(\DomainException::class);
        $this->buscar($consultados)->executar($this->pasta($principal), $this->tenant);
    }

    #[TestDox('Mensagens: "N movimentação(ões) nova(s)", limite do DJEN e falha')]
    public function testMensagens(): void
    {
        $novas = new ResultadoSincronizacao();
        $novas->registrarProcessoConsultado();
        $novas->registrarNova(true, '1');
        $novas->registrarNova(true, '2');
        self::assertSame('2 movimentação(ões) nova(s)', BuscaNoPushOutput::doResultado($novas)->mensagem);

        $limite = new ResultadoSincronizacao();
        $limite->registrarProcessoConsultado();
        $limite->registrarProcessosAdiados(1);
        $aviso = BuscaNoPushOutput::doResultado($limite);
        self::assertSame('Nenhuma movimentação nova. 1 processo(s) não foram consultados agora (limite de consultas do DJEN); a captação automática diária consulta todos.', $aviso->mensagem);
        self::assertSame(BuscaNoPushOutput::TOM_AVISO, $aviso->tom);

        $falha = new ResultadoSincronizacao();
        $falha->registrarFalha('Processos cadastrados', MotivoFalhaDjen::Indisponivel);
        $falha->registrarProcessosAdiados(1);
        self::assertSame('Não foi possível consultar o DJEN agora. Tente novamente em instantes.', BuscaNoPushOutput::doResultado($falha)->mensagem);
    }
}
