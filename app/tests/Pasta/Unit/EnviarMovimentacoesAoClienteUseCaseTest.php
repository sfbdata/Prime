<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Cliente\Repository\EnvioAutomaticoDoClienteRepository;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Pasta\Service\EnvioAoCliente\MensagemAoCliente;
use App\Pasta\Service\EnvioAoCliente\ResultadoDoEnvioAoCliente;
use App\Pasta\Service\EnvioAoCliente\TipoDeEmailAoCliente;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\Service\GruposDoEnvioAutomatico;
use App\Pasta\UseCase\EnviarAnaliseAoClienteUseCase;
use App\Pasta\UseCase\EnviarMovimentacoesAoClienteUseCase;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Novas movimentações" e "Audiências e intimações" na captação (spec push-envio-automatico §3 e §4):
 * padrões desligados não enviam; o filtro das audiências e intimações; as duas chaves não repetem a
 * movimentação; o que já foi enviado ao cliente não sai de novo; um e-mail por e-mail ativo (não
 * pausado, não descadastrado) com os campos do Carteiro (Lista, `push_cliente`) e a cópia só na
 * primeira; segredo de justiça; sem Controladoria, o primário é avisado uma vez.
 */
#[CoversClass(EnviarMovimentacoesAoClienteUseCase::class)]
#[CoversClass(EnvioAutomaticoAoCliente::class)]
#[CoversClass(GruposDoEnvioAutomatico::class)]
final class EnviarMovimentacoesAoClienteUseCaseTest extends TestCase
{
    /** Relógio dos testes: as publicações de 02/10 estão na janela da D6 (hoje e os 2 dias anteriores). */
    private const AGORA = '2026-10-04 09:00:00';

    private Tenant $tenant;
    private Pasta $pasta;
    private Processo $processo;
    private ClientePF $cliente;
    /** @var list<PublicacaoDjen> */
    private array $publicacoes = [];
    /** @var list<ClienteEmail> */
    private array $linhas = [];
    private ?EnvioAutomaticoDoCliente $chaves = null;
    /** @var list<int> */
    private array $jaEnviadas = [];
    /** @var list<string> */
    private array $descadastrados = [];
    /** @var list<User> */
    private array $controladoria = [];
    /** @var list<MensagemAoCliente> */
    private array $mensagens = [];
    /** @var list<EnvioAoCliente> */
    private array $gravados = [];
    /** @var list<string> */
    private array $falharPara = [];
    private NotificacaoService&MockObject $notificacoes;
    /** @var list<list<list<string>>> o "para" de cada linha gravada, a cada flush */
    private array $flushes = [];
    private ?\Throwable $erroNoEnvio = null;
    /** @var list<UserTenant> */
    private array $vinculosDoEscritorio = [];

    protected function setUp(): void
    {
        $this->tenant = (new Tenant())->setName('Silva Advogados');
        DefineId::em($this->tenant, 1);
        $this->cliente = new ClientePF();
        $this->cliente->setNomeCompleto('Maria da Silva');
        $this->cliente->setTenant($this->tenant);
        DefineId::em($this->cliente, 50);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('2026/0042');
        $this->pasta->setResponsavel((new User())->setEmail('resp@escritorio.com.br')->setIsActive(true));
        $this->pasta->addCliente($this->cliente);
        DefineId::em($this->pasta, 30);

        $this->processo = new Processo();
        $this->processo->setTenant($this->tenant);
        $this->processo->setNumeroProcesso('07011345720258070007');
        DefineId::em($this->processo, 40);

        $agora = new \DateTimeImmutable('2026-10-19 10:00');
        $this->linhas = [
            new ClienteEmail($this->tenant, $this->cliente, 'maria@cliente.com.br', RotuloDoEmail::Pessoal, true, null, $agora),
            new ClienteEmail($this->tenant, $this->cliente, 'maria@trabalho.com.br', RotuloDoEmail::Trabalho, false, null, $agora),
            new ClienteEmail($this->tenant, $this->cliente, 'pausado@cliente.com.br', RotuloDoEmail::Outro, false, null, $agora),
            new ClienteEmail($this->tenant, $this->cliente, 'saiu@cliente.com.br', RotuloDoEmail::Outro, false, null, $agora),
        ];
        $this->linhas[2]->definirRecebimento(false);
        $this->descadastrados = ['saiu@cliente.com.br'];
        $this->publicacoes = [
            $this->publicacao(701, 'Intimação', 'Fica a parte intimada para réplica.'),
            $this->publicacao(702, 'Edital', 'Junte-se. Após, conclusos.'),
        ];
        $this->notificacoes = $this->createMock(NotificacaoService::class);
    }

    private function publicacao(int $id, string $tipo, string $texto): PublicacaoDjen
    {
        $publicacao = new PublicacaoDjen();
        DefineId::em($publicacao, $id);
        $publicacao->setTenant($this->tenant);
        $publicacao->setTipoComunicacao($tipo);
        $publicacao->setNomeOrgao('1ª Vara Cível de Brasília');
        $publicacao->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-02'));
        $publicacao->setTexto($texto);
        $publicacao->setProcesso($this->processo);

        return $publicacao;
    }

    private function ligar(ChaveDoEnvioAutomatico ...$chaves): void
    {
        $this->chaves = new EnvioAutomaticoDoCliente($this->tenant, $this->cliente, null, new \DateTimeImmutable());
        foreach ($chaves as $chave) {
            $this->chaves->definir($chave, true, new User(), new \DateTimeImmutable());
        }
    }

    private function useCase(): EnviarMovimentacoesAoClienteUseCase
    {
        $publicacoes = $this->createStub(PublicacaoDjenRepository::class);
        $publicacoes->method('listarVinculadasPorDjenIdsDoTenant')->willReturnCallback(fn (): array => $this->publicacoes);
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('listarVinculosDePastasVivasDosProcessos')->willReturn([new PastaProcesso($this->pasta, $this->processo)]);
        $emails = $this->createStub(ClienteEmailRepository::class);
        $emails->method('listarDoCliente')->willReturnCallback(fn (): array => $this->linhas);
        $automaticos = $this->createStub(EnvioAutomaticoDoClienteRepository::class);
        $automaticos->method('porClientesDoTenant')->willReturnCallback(fn (): array => $this->chaves !== null ? [50 => $this->chaves] : []);
        $envios = $this->createStub(EnvioAoClienteRepository::class);
        $envios->method('publicacoesJaEnviadasAoCliente')->willReturnCallback(fn (): array => $this->jaEnviadas);
        $envios->method('salvar')->willReturnCallback(function (EnvioAoCliente $e, bool $flush = false): void {
            if (!\in_array($e, $this->gravados, true)) {
                $this->gravados[] = $e;
            }
            if ($flush) {
                $this->flushes[] = array_map(static fn (EnvioAoCliente $g): array => $g->getPara(), $this->gravados);
            }
        });

        $daPasta = new EmailsDoClienteDaPasta($emails);

        return new EnviarMovimentacoesAoClienteUseCase($publicacoes, new GruposDoEnvioAutomatico($pastas, $daPasta, $automaticos), $envios, $this->envio($daPasta, $envios), new MockClock(self::AGORA), new NullLogger());
    }

    private function envio(EmailsDoClienteDaPasta $daPasta, EnvioAoClienteRepository $envios): EnvioAutomaticoAoCliente
    {
        $descadastro = $this->createStub(DescadastroDeEmailInterface::class);
        $descadastro->method('estaDescadastrado')->willReturnCallback(fn (?Tenant $t, string $email, string $assunto): bool => $assunto === 'push_cliente' && \in_array($email, $this->descadastrados, true));
        $controladoria = $this->createStub(ControladoriaDoEscritorioInterface::class);
        $controladoria->method('pessoas')->willReturnCallback(fn (): array => $this->controladoria);
        $porta = $this->createStub(EnvioDeEmailAoClienteInterface::class);
        $porta->method('enviar')->willReturnCallback(function (MensagemAoCliente $m): ResultadoDoEnvioAoCliente {
            if (\in_array($m->para, $this->falharPara, true)) {
                throw $this->erroNoEnvio ?? new \RuntimeException('smtp');
            }
            $this->mensagens[] = $m;

            return new ResultadoDoEnvioAoCliente(null, ResultadoDoEnvioAoCliente::ENVIADO);
        });
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivosComPerfilPorTenant')->willReturnCallback(fn (): array => $this->vinculosDoEscritorio);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/pasta/30');

        return new EnvioAutomaticoAoCliente($daPasta, $descadastro, $controladoria, $porta, $envios, $vinculos, new RegraDosPrimarios($vinculos), $this->notificacoes, $urls, new MockClock(self::AGORA), new NullLogger());
    }

    /** @return list<string> */
    private function para(): array
    {
        return array_map(static fn (MensagemAoCliente $m): string => $m->para, $this->mensagens);
    }

    #[TestDox('Padrão (D1): sem linha de chaves, nada sai nem é gravado')]
    public function testPadraoDesligadoNaoEnvia(): void
    {
        self::assertSame(0, $this->useCase()->executar($this->tenant, ['701', '702']));
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('Novas movimentações: um e-mail por e-mail ativo (pausado e descadastrado ficam de fora) com as duas movimentações; campos do Carteiro; uma linha no histórico por movimentação')]
    public function testNovasMovimentacoes(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->controladoria = [(new User())->setEmail('controle@escritorio.com.br')];

        self::assertSame(1, $this->useCase()->executar($this->tenant, ['701', '702']));

        self::assertSame(['maria@cliente.com.br', 'maria@trabalho.com.br'], $this->para());
        $primeira = $this->mensagens[0];
        self::assertSame(TipoDeEmailAoCliente::Lista, $primeira->tipo);
        self::assertSame('push_cliente', $primeira->lista);
        self::assertSame('pasta:push_cliente_auto', $primeira->origem);
        self::assertSame($this->tenant, $primeira->escopo);
        self::assertSame('resp@escritorio.com.br', $primeira->replyTo);
        self::assertSame('Silva Advogados via BlueJus', $primeira->nomeDoRemetente);
        self::assertSame('2 novas movimentações no seu processo 0701134-57.2025.8.07.0007', $primeira->assunto);
        self::assertStringContainsString('Fica a parte intimada para réplica.', $primeira->corpoHtml);
        self::assertStringContainsString('Junte-se. Após, conclusos.', $primeira->corpoHtml);
        self::assertSame(['controle@escritorio.com.br'], $primeira->cc, 'cópia na primeira');
        self::assertSame([], $this->mensagens[1]->cc, 'e só na primeira');

        self::assertCount(2, $this->gravados);
        self::assertSame([701, 702], array_map(static fn (EnvioAoCliente $e): ?int => $e->getReferencia(), $this->gravados));
        self::assertSame(TipoDeEnvioAoCliente::Auto, $this->gravados[0]->getTipo());
        self::assertSame('intimação de 02/10', $this->gravados[0]->getDescricao());
        self::assertSame('movimentação de 02/10', $this->gravados[1]->getDescricao());
        self::assertSame(['maria@cliente.com.br', 'maria@trabalho.com.br'], $this->gravados[0]->getPara());
        self::assertSame(['controle@escritorio.com.br'], $this->gravados[0]->getCc());
        self::assertNull($this->gravados[0]->getPor());
    }

    #[TestDox('Só "Audiências e intimações": sai a intimação, o despacho comum fica de fora')]
    public function testFiltroDeAudienciasEIntimacoes(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::AudienciasIntimacoes);

        $this->useCase()->executar($this->tenant, ['701', '702']);

        self::assertCount(2, $this->mensagens);
        self::assertStringContainsString('Fica a parte intimada', $this->mensagens[0]->corpoHtml);
        self::assertStringNotContainsString('Junte-se', $this->mensagens[0]->corpoHtml);
        self::assertSame([701], array_map(static fn (EnvioAoCliente $e): ?int => $e->getReferencia(), $this->gravados));
    }

    #[TestDox('As duas chaves ligadas: a intimação sai uma vez só (um e-mail por destinatário, uma linha por movimentação)')]
    public function testDuasChavesNaoRepetem(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes, ChaveDoEnvioAutomatico::AudienciasIntimacoes);

        $this->useCase()->executar($this->tenant, ['701', '702']);

        self::assertCount(2, $this->mensagens);
        self::assertSame(1, substr_count($this->mensagens[0]->corpoHtml, 'Fica a parte intimada'));
        self::assertSame([701, 702], array_map(static fn (EnvioAoCliente $e): ?int => $e->getReferencia(), $this->gravados));
    }

    #[TestDox('Não repetir: a publicação já enviada a este cliente não sai de novo; tudo já enviado, nada sai')]
    public function testNaoRepete(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->jaEnviadas = [701];

        $this->useCase()->executar($this->tenant, ['701', '702']);
        self::assertStringNotContainsString('Fica a parte intimada', $this->mensagens[0]->corpoHtml);
        self::assertSame([702], array_map(static fn (EnvioAoCliente $e): ?int => $e->getReferencia(), $this->gravados));

        $this->mensagens = [];
        $this->gravados = [];
        $this->jaEnviadas = [701, 702];
        self::assertSame(0, $this->useCase()->executar($this->tenant, ['701', '702']));
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('Segredo de justiça: o e-mail só diz a frase da D4, sem o texto, e o assunto vai sem número')]
    public function testSegredoDeJustica(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->processo->setNivelSigilo(1);

        $this->useCase()->executar($this->tenant, ['701', '702']);

        $corpo = $this->mensagens[0]->corpoHtml;
        self::assertStringContainsString('Há movimentação nova no seu processo sob segredo de justiça. Fale com o escritório.', $corpo);
        self::assertStringNotContainsString('Fica a parte intimada', $corpo);
        self::assertStringNotContainsString('0701134', $corpo . $this->mensagens[0]->assunto);
    }

    #[TestDox('Sem Controladoria: envia sem cópia e avisa o primário uma vez (não por e-mail); com Controladoria, ninguém é avisado')]
    public function testSemControladoriaAvisaPrimario(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $primario = (new User())->setEmail('primario@escritorio.com.br')->setIsActive(true);
        $vinculo = new UserTenant($primario, $this->tenant);
        $vinculo->setTenantRole((new TenantRole())->setIsSystem(true));
        $this->vinculosDoEscritorio = [$vinculo];
        $this->notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($primario, $this->tenant, EnviarAnaliseAoClienteUseCase::TIPO_NOTIFICACAO_SEM_CONTROLADORIA, self::stringContains('sem cópia para a Controladoria'), '/pasta/30#push');

        $this->useCase()->executar($this->tenant, ['701', '702']);

        self::assertCount(2, $this->mensagens);
        self::assertSame([], $this->mensagens[0]->cc);
    }

    #[TestDox('Falha do transporte para um endereço: os outros recebem, a cópia vai na primeira que sair e só eles ficam no histórico')]
    public function testFalhaDeUmDestinatario(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->controladoria = [(new User())->setEmail('controle@escritorio.com.br')];
        $this->falharPara = ['maria@cliente.com.br'];

        $this->useCase()->executar($this->tenant, ['701']);

        self::assertSame(['maria@trabalho.com.br'], $this->para());
        self::assertSame(['controle@escritorio.com.br'], $this->mensagens[0]->cc);
        self::assertSame(['maria@trabalho.com.br'], $this->gravados[0]->getPara());
    }

    #[TestDox('Sem responsável ativo na pasta: Responder para é um primário do escritório, nunca o endereço da plataforma')]
    public function testResponderParaSemResponsavel(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->pasta->setResponsavel(null);
        $primario = (new User())->setEmail('primario@escritorio.com.br')->setIsActive(true);
        $vinculo = new UserTenant($primario, $this->tenant);
        $vinculo->setTenantRole((new TenantRole())->setIsSystem(true));
        $this->vinculosDoEscritorio = [$vinculo];

        $this->useCase()->executar($this->tenant, ['701']);

        self::assertSame('primario@escritorio.com.br', $this->mensagens[0]->replyTo);
    }
    #[TestDox('D6: publicação disponibilizada antes de anteontem (achada agora pela captação) não vai como novidade')]
    public function testPublicacaoAntigaNaoVai(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->publicacoes[0]->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-01'));
        $this->publicacoes[1]->setDataDisponibilizacao(new \DateTimeImmutable('2026-09-14'));

        self::assertSame(0, $this->useCase()->executar($this->tenant, ['701', '702']));
        self::assertSame([], $this->mensagens);

        $this->publicacoes[0]->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-02'));
        $this->useCase()->executar($this->tenant, ['701', '702']);
        self::assertSame([701], array_map(static fn (EnvioAoCliente $e): ?int => $e->getReferencia(), $this->gravados), 'anteontem ainda entra');
    }

    /** @return iterable<string, array{callable(Pasta, Processo): void}> */
    public static function bloqueios(): iterable
    {
        yield 'processo com o monitoramento pausado' => [static fn (Pasta $p, Processo $pr) => $pr->pausarMonitoramento(new User(), new \DateTimeImmutable())];
        yield 'processo baixado' => [static fn (Pasta $p, Processo $pr) => $pr->setDataBaixa(new \DateTimeImmutable('2026-09-01'))];
        yield 'pasta arquivada' => [static fn (Pasta $p, Processo $pr) => $p->setSituacao(Pasta::SITUACAO_ARQUIVADA)];
        yield 'pasta cancelada' => [static fn (Pasta $p, Processo $pr) => $p->setSituacao(Pasta::SITUACAO_CANCELADA)];
        yield 'pasta suspensa' => [static fn (Pasta $p, Processo $pr) => $p->setSituacao(Pasta::SITUACAO_SUSPENSA)];
        yield 'pasta trancada' => [static fn (Pasta $p, Processo $pr) => $p->trancar(new User(), new \DateTimeImmutable())];
    }

    /** @param callable(Pasta, Processo): void $bloquear */
    #[DataProvider('bloqueios')]
    #[TestDox('D7: $_dataName não envia nada')]
    public function testBloqueios(callable $bloquear): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes, ChaveDoEnvioAutomatico::AudienciasIntimacoes);
        $bloquear($this->pasta, $this->processo);

        self::assertSame(0, $this->useCase()->executar($this->tenant, ['701', '702']));
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('Histórico por destinatário: o primeiro fica gravado (flush) antes de o segundo sair; um \Error no envio também só vai ao log')]
    public function testGravaCadaDestinatarioAntesDoProximo(): void
    {
        $this->ligar(ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->falharPara = ['maria@trabalho.com.br'];
        $this->erroNoEnvio = new \Error('quebrou');
        $this->publicacoes = [$this->publicacoes[0]];
        $this->linhas[] = new ClienteEmail($this->tenant, $this->cliente, 'terceiro@cliente.com.br', RotuloDoEmail::Outro, false, null, new \DateTimeImmutable());

        $this->useCase()->executar($this->tenant, ['701']);

        self::assertSame(['maria@cliente.com.br', 'terceiro@cliente.com.br'], $this->para());
        self::assertSame([[['maria@cliente.com.br']], [['maria@cliente.com.br', 'terceiro@cliente.com.br']]], $this->flushes);
        self::assertSame(['maria@cliente.com.br', 'terceiro@cliente.com.br'], $this->gravados[0]->getPara());
    }
}
