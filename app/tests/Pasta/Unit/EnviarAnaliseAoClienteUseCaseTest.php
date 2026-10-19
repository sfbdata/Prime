<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Enum\RotuloDoEmail;
use App\Cliente\Repository\ClienteEmailRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Pasta\DTO\EnviarAnaliseAoClienteInput;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Exception\EnvioAoClienteRecusadoException;
use App\Pasta\Exception\FalhaNoEnvioAoClienteException;
use App\Pasta\Repository\EnvioAoClienteRepository;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EmailsDoClienteDaPasta;
use App\Pasta\Service\EnvioAoCliente\EnvioDeEmailAoClienteInterface;
use App\Pasta\Service\EnvioAoCliente\MensagemAoCliente;
use App\Pasta\Service\EnvioAoCliente\ResultadoDoEnvioAoCliente;
use App\Pasta\Service\EnvioAoCliente\TipoDeEmailAoCliente;
use App\Pasta\UseCase\EnviarAnaliseAoClienteUseCase;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * O envio manual da Análise IA ao cliente (spec push-avisar-cliente §3 e §5): a porta de envio recebe
 * os campos do contrato do Carteiro, um destinatário por chamada e o CC só na primeira; o histórico é
 * gravado; validações na ordem do desenho; análise interna ou de outra pasta não sai; sem Controladoria
 * o primário é avisado.
 */
#[CoversClass(EnviarAnaliseAoClienteUseCase::class)]
#[CoversClass(MensagemAoCliente::class)]
final class EnviarAnaliseAoClienteUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;
    private AnaliseDeInteligencia $analise;
    /** @var list<ClienteEmail> */
    private array $linhas = [];
    /** @var list<User> */
    private array $controladoria = [];
    /** @var list<MensagemAoCliente> */
    private array $mensagens = [];
    /** @var list<EnvioAoCliente> */
    private array $gravados = [];
    private ?\RuntimeException $falharNa = null;
    private int $falharNoIndice = -1;
    private DescadastroDeEmailInterface&MockObject $descadastro;
    private NotificacaoService&MockObject $notificacoes;
    private UserTenantRepository&MockObject $vinculos;

    protected function setUp(): void
    {
        $this->tenant = (new Tenant())->setName('Silva Advogados');
        $this->usuario = new User();
        $responsavel = (new User())->setEmail('resp@escritorio.com.br')->setIsActive(true);

        $cliente = new ClientePF();
        $cliente->setNomeCompleto('Maria da Silva');
        $cliente->setEmail('maria@ficha.com.br');
        $cliente->setTenant($this->tenant);

        $this->pasta = new Pasta();
        $this->pasta->setTenant($this->tenant);
        $this->pasta->setNup('2026/0042');
        $this->pasta->setResponsavel($responsavel);
        $this->pasta->addCliente($cliente);

        $agora = new \DateTimeImmutable('2026-10-19 10:00');
        $this->linhas = [
            new ClienteEmail($this->tenant, $cliente, 'maria@cliente.com.br', RotuloDoEmail::Pessoal, true, null, $agora),
            new ClienteEmail($this->tenant, $cliente, 'maria@trabalho.com.br', RotuloDoEmail::Trabalho, false, null, $agora),
        ];

        $this->analise = new AnaliseDeInteligencia($this->tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 0, 'v1', 'hash', []);
        (new \ReflectionProperty(AnaliseDeInteligencia::class, 'id'))->setValue($this->analise, 9);
        $this->analise->iniciarProcessamento();
        $this->analise->concluir('Resumo', [], null, 'bruto', 'falso', 'modelo', null, null, null);
        $this->analise->alternarInterna(); // nasce interna; liberada para o envio

        $this->descadastro = $this->createMock(DescadastroDeEmailInterface::class);
        $this->notificacoes = $this->createMock(NotificacaoService::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
    }

    private function useCase(): EnviarAnaliseAoClienteUseCase
    {
        $analises = $this->createStub(AnaliseDeInteligenciaRepository::class);
        $analises->method('findOneDoTenantEAlvo')->willReturnCallback(fn (int $id): ?AnaliseDeInteligencia => $id === 9 ? $this->analise : null);

        $emails = $this->createStub(ClienteEmailRepository::class);
        $emails->method('listarDoCliente')->willReturnCallback(fn (): array => $this->linhas);

        $controladoria = $this->createStub(ControladoriaDoEscritorioInterface::class);
        $controladoria->method('pessoas')->willReturnCallback(fn (): array => $this->controladoria);

        $envio = $this->createStub(EnvioDeEmailAoClienteInterface::class);
        $envio->method('enviar')->willReturnCallback(function (MensagemAoCliente $m): ResultadoDoEnvioAoCliente {
            if (\count($this->mensagens) === $this->falharNoIndice) {
                throw $this->falharNa ?? new \RuntimeException('smtp');
            }
            $this->mensagens[] = $m;

            return new ResultadoDoEnvioAoCliente(\count($this->mensagens) + 100, ResultadoDoEnvioAoCliente::ENFILEIRADO);
        });

        $envios = $this->createStub(EnvioAoClienteRepository::class);
        $envios->method('salvar')->willReturnCallback(function (EnvioAoCliente $e): void {
            $this->gravados[] = $e;
        });
        $envios->method('enviosDeAnalisesDaPasta')->willReturnCallback(fn (): array => $this->gravados);

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/pasta/1');

        return new EnviarAnaliseAoClienteUseCase(
            $analises,
            new EmailsDoClienteDaPasta($emails),
            $this->descadastro,
            $controladoria,
            $envio,
            $envios,
            $this->vinculos,
            $this->notificacoes,
            $urls,
            new MockClock('2026-10-19 14:30:00'),
        );
    }

    /** @param list<string> $para */
    private function input(array $para = ['maria@cliente.com.br', 'maria@trabalho.com.br'], string $assunto = 'Atualização do seu processo', string $corpo = "Olá, Maria.\n\n<b>Resumo</b>", bool $revisado = true, int $analiseId = 9): EnviarAnaliseAoClienteInput
    {
        return new EnviarAnaliseAoClienteInput($analiseId, $para, $assunto, $corpo, $revisado);
    }

    private function controladora(): User
    {
        return (new User())->setEmail('controle@escritorio.com.br')->setFullName('Ana Controle');
    }

    #[TestDox('Uma mensagem por destinatário com os campos do contrato do Carteiro; o CC da Controladoria só na primeira; o histórico guarda para, cc, assunto, ids e a análise')]
    public function testEnviaComCcSoNaPrimeira(): void
    {
        $this->controladoria = [$this->controladora()];
        $this->notificacoes->expects(self::never())->method('criarNotificacao');

        $saida = $this->useCase()->executar($this->pasta, $this->input(), $this->usuario, $this->tenant);

        self::assertCount(2, $this->mensagens);
        [$primeira, $segunda] = $this->mensagens;
        self::assertSame('maria@cliente.com.br', $primeira->para);
        self::assertSame(['controle@escritorio.com.br'], $primeira->cc);
        self::assertSame([], $segunda->cc, 'a Controladoria recebe uma cópia por envio');
        self::assertSame(TipoDeEmailAoCliente::Transacional, $primeira->tipo);
        self::assertNull($primeira->lista);
        self::assertSame('pasta:push_cliente_ia', $primeira->origem);
        self::assertSame($this->tenant, $primeira->escopo);
        self::assertSame('resp@escritorio.com.br', $primeira->replyTo);
        self::assertSame('Silva Advogados via BlueJus', $primeira->nomeDoRemetente);
        self::assertSame('Atualização do seu processo', $primeira->assunto);
        self::assertStringContainsString('&lt;b&gt;Resumo&lt;/b&gt;', $primeira->corpoHtml, 'o texto vai escapado');

        self::assertCount(1, $this->gravados);
        $envio = $this->gravados[0];
        self::assertSame(TipoDeEnvioAoCliente::Ia, $envio->getTipo());
        self::assertSame(['maria@cliente.com.br', 'maria@trabalho.com.br'], $envio->getPara());
        self::assertSame(['controle@escritorio.com.br'], $envio->getCc());
        self::assertSame(9, $envio->getReferencia());
        self::assertSame([101, 102], $envio->getEnvioIds());
        self::assertSame($this->usuario, $envio->getPor());

        self::assertSame('Análise enviada para 2 e-mails do cliente, com cópia para a Controladoria', $saida->mensagem());
        self::assertSame('Enviado 19/10 às 14:30', $saida->envio->quando);
    }

    #[TestDox('Sem Controladoria: envia sem cópia, a mensagem não fala dela e cada primário recebe o aviso no sino')]
    public function testSemControladoriaAvisaPrimario(): void
    {
        $primario = new User();
        $this->vinculos->method('findUsuariosAdminsAtivos')->willReturn([$primario]);
        $this->notificacoes->expects(self::once())->method('criarNotificacao')
            ->with($primario, $this->tenant, EnviarAnaliseAoClienteUseCase::TIPO_NOTIFICACAO_SEM_CONTROLADORIA, self::stringContains('sem cópia para a Controladoria'), '/pasta/1#push');

        $saida = $this->useCase()->executar($this->pasta, $this->input(['maria@cliente.com.br']), $this->usuario, $this->tenant);

        self::assertSame([], $this->mensagens[0]->cc);
        self::assertSame('Análise enviada para 1 e-mail do cliente', $saida->mensagem());
    }

    #[TestDox('Validações na ordem do desenho: destinatário, assunto e mensagem, checkbox; nada sai')]
    public function testValidacoesNaOrdem(): void
    {
        $casos = [
            'Escolha ao menos um destinatário.' => $this->input([], '', '', false),
            'Assunto e mensagem não podem ficar vazios.' => $this->input(['maria@cliente.com.br'], '  ', 'x', false),
            'Confirme que revisou o conteúdo antes de enviar.' => $this->input(['maria@cliente.com.br'], 'a', 'b', false),
        ];
        foreach ($casos as $mensagem => $input) {
            try {
                $this->useCase()->executar($this->pasta, $input, $this->usuario, $this->tenant);
                self::fail('deveria recusar: ' . $mensagem);
            } catch (EnvioAoClienteRecusadoException $e) {
                self::assertSame($mensagem, $e->getMessage());
            }
        }
        self::assertSame([], $this->mensagens);
        self::assertSame([], $this->gravados);
    }

    #[TestDox('Destinatário que não é e-mail cadastrado do cliente, ou descadastrado, é recusado')]
    public function testDestinatarioForaDaListaOuDescadastrado(): void
    {
        try {
            $this->useCase()->executar($this->pasta, $this->input(['estranho@x.com.br']), $this->usuario, $this->tenant);
            self::fail('fora da lista');
        } catch (EnvioAoClienteRecusadoException $e) {
            self::assertSame('Escolha os destinatários entre os e-mails cadastrados do cliente.', $e->getMessage());
        }

        $this->descadastro->method('estaDescadastrado')->willReturnCallback(static fn ($t, string $email, string $assunto): bool => $email === 'maria@trabalho.com.br' && $assunto === 'push_cliente');
        $this->expectExceptionMessage('O cliente pediu para não receber mais estes avisos em maria@trabalho.com.br.');
        $this->useCase()->executar($this->pasta, $this->input(), $this->usuario, $this->tenant);
    }

    #[TestDox('Análise interna do escritório não sai (422); de outra pasta ou excluída, 404')]
    public function testAnaliseInternaOuAlheia(): void
    {
        $this->analise->alternarInterna();
        try {
            $this->useCase()->executar($this->pasta, $this->input(), $this->usuario, $this->tenant);
            self::fail('interna');
        } catch (EnvioAoClienteRecusadoException $e) {
            self::assertSame('Análise interna do escritório. Libere o cadeado para enviar ao cliente.', $e->getMessage());
        }

        $this->expectException(AnaliseNaoEncontradaException::class);
        $this->useCase()->executar($this->pasta, $this->input(analiseId: 10), $this->usuario, $this->tenant);
    }

    #[TestDox('Falha do transporte no segundo destinatário: grava o que saiu e devolve a falha com os enviados')]
    public function testFalhaNoMeio(): void
    {
        $this->falharNoIndice = 1;

        try {
            $this->useCase()->executar($this->pasta, $this->input(), $this->usuario, $this->tenant);
            self::fail('deveria falhar');
        } catch (FalhaNoEnvioAoClienteException $e) {
            self::assertSame('Falha no envio. O texto foi mantido; tente de novo.', $e->getMessage());
            self::assertSame(['maria@cliente.com.br'], $e->enviados);
        }
        self::assertCount(1, $this->gravados);
        self::assertSame(['maria@cliente.com.br'], $this->gravados[0]->getPara());
    }

    #[TestDox('Falha logo no primeiro: nada gravado')]
    public function testFalhaNoPrimeiro(): void
    {
        $this->falharNoIndice = 0;

        $this->expectException(FalhaNoEnvioAoClienteException::class);
        try {
            $this->useCase()->executar($this->pasta, $this->input(), $this->usuario, $this->tenant);
        } finally {
            self::assertSame([], $this->gravados);
        }
    }
}
