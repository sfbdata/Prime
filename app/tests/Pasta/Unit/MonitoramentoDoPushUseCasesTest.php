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
use App\Pasta\Exception\BuscaNoPushMuitoFrequenteException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\ProcessoForaDaPastaException;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
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

    private ?RateLimiterFactoryEspiao $limitador = null;

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

    private function definirId(Processo $processo, int $id): void
    {
        (new \ReflectionProperty(Processo::class, 'id'))->setValue($processo, $id);
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

        return new BuscarAgoraNoPushUseCase($sincronizar, new MockClock(new \DateTimeImmutable(self::AGORA)), $this->limitador ?? new RateLimiterFactoryEspiao(aceita: true));
    }

    #[TestDox('Alternar: pausa o processo principal (quem e quando) e retoma na segunda vez, com os textos do desenho')]
    public function testAlternaOProcessoPrincipal(): void
    {
        $principal = $this->processo('07011345720258070007');
        $outro = $this->processo('07099999999999999999');
        $this->definirId($principal, 1);
        $this->definirId($outro, 2);
        $pasta = $this->pasta($principal, $outro);
        $usuario = new User();

        $pausado = $this->alternar()->executar($pasta, (int) $principal->getId(), $usuario, $this->tenant);

        self::assertFalse($pausado->ativo);
        self::assertSame('Monitoramento pausado', $pausado->statusTxt);
        self::assertSame('A busca pelo número do processo fica parada; intimações pela OAB continuam chegando. · 2 processos', $pausado->statusSub);
        self::assertSame($usuario, $principal->getMonitoramentoAlteradoPor());
        self::assertEquals(new \DateTimeImmutable(self::AGORA), $principal->getMonitoramentoPausadoEm());
        self::assertSame('Pausado em 19/10', $pausado->processos[0]->dicaPausa, 'usuário sem nome: só a data');
        self::assertTrue($outro->monitoramentoAtivo(), 'só o principal: o desenho não tem switch por apensado');

        $quemRetomou = new User();
        $ativo = $this->alternar()->executar($pasta, (int) $principal->getId(), $quemRetomou, $this->tenant);

        self::assertTrue($ativo->ativo);
        self::assertSame('Monitoramento ativo', $ativo->statusTxt);
        self::assertSame($quemRetomou, $principal->getMonitoramentoAlteradoPor(), 'retomar não apaga quem mexeu: registra quem retomou');
    }

    #[TestDox('Alternar processo que não é desta pasta é recusado (o controller dá 404)')]
    public function testAlternarProcessoDeFora(): void
    {
        $this->expectException(ProcessoForaDaPastaException::class);

        $this->alternar()->executar($this->pasta($this->processo('07011345720258070007')), 999999, new User(), $this->tenant);
    }

    #[TestDox('Alternar o processo apensado não mexe no principal')]
    public function testAlternarApensado(): void
    {
        $principal = $this->processo('07011345720258070007');
        $apensado = $this->processo('07099999999999999999');
        $this->definirId($principal, 1);
        $this->definirId($apensado, 2);

        $painel = $this->alternar()->executar($this->pasta($principal, $apensado), 2, new User(), $this->tenant);

        self::assertTrue($principal->monitoramentoAtivo());
        self::assertFalse($apensado->monitoramentoAtivo());
        self::assertTrue($painel->ativo);
        self::assertFalse($painel->processo(2)?->ativo);
    }

    #[TestDox('Alternar pasta de outro escritório é recusado')]
    public function testAlternarOutroEscritorio(): void
    {
        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->alternar()->executar($this->pasta($this->processo('07011345720258070007')), 0, new User(), new Tenant());
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

    #[TestDox('Limite: recusa sem consultar o DJEN; com o monitoramento pausado a cota nem é gasta')]
    public function testLimiteDepoisDasRecusas(): void
    {
        $this->limitador = new RateLimiterFactoryEspiao(aceita: false);
        $principal = $this->processo('07011345720258070007');
        $consultados = [];

        try {
            $this->buscar($consultados)->executar($this->pasta($principal), $this->tenant);
            self::fail('deveria recusar pelo limite');
        } catch (BuscaNoPushMuitoFrequenteException) {
        }
        self::assertSame([], $consultados);

        $this->limitador = new RateLimiterFactoryEspiao(aceita: true);
        $principal->pausarMonitoramento(new User(), new \DateTimeImmutable());
        try {
            $this->buscar($consultados)->executar($this->pasta($principal), $this->tenant);
        } catch (\DomainException) {
        }
        self::assertSame([], $this->limitador->chavesConsumidas, 'pausado não gasta a cota');
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
