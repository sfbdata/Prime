<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClienteEmail;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Entity\EnvioAutomaticoDoCliente;
use App\Cliente\Enum\ChaveDoEnvioAutomatico;
use App\Cliente\Enum\RotuloDoEmail;
use App\Djen\DTO\ConsultaComunicacoesQuery;
use App\Djen\DTO\PeriodoConsulta;
use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\PublicacaoDjen;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Djen\UseCase\SincronizarPublicacoesDjenUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EnvioAoCliente;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\TipoDeEnvioAoCliente;
use App\Pasta\Service\AvisoDoPushNaCaptacao;
use App\Pasta\Service\ControladoriaDoEscritorioInterface;
use App\Pasta\Service\EnvioAutomaticoAoCliente;
use App\Pasta\UseCase\EnviarMovimentacoesAoClienteUseCase;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use App\Entity\Notificacao;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Mime\Email;

/**
 * O envio automático ao cliente pela captação real (`sincronizarNumeros`, a do "Buscar agora") com o
 * DJEN dublado (spec push-envio-automatico §3 e §4): só com a chave ligada o cliente recebe o e-mail
 * certo (Carteiro provisório: remetente do escritório, Responder para o responsável, descadastro);
 * descadastrado não recebe; a cópia da Controladoria vai só na primeira mensagem; o histórico guarda
 * a publicação e a captação repetida não manda de novo.
 */
#[CoversClass(EnviarMovimentacoesAoClienteUseCase::class)]
#[CoversClass(EnvioAutomaticoAoCliente::class)]
#[CoversClass(AvisoDoPushNaCaptacao::class)]
#[Group('pasta')]
final class EnvioAutomaticoAoClienteNaCaptacaoTest extends KernelTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const NUMERO = '07011345720258070007';
    private const EMAIL_DO_CLIENTE = 'maria.cliente@exemplo.com.br';

    protected function setUp(): void
    {
        // D6: o envio só leva o que foi disponibilizado hoje e nos 2 dias anteriores; a dublagem do
        // DJEN publica em 02/10, então o relógio fica em 03/10.
        Clock::set(new MockClock('2026-10-03 10:00:00'));
        self::bootKernel();
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /** @param list<int> $ids djenId das intimações que o DJEN devolve para o número */
    private function dublarDjen(array $ids, string $data = '2026-10-02'): void
    {
        $itens = array_map(static fn (int $id): array => [
            'id' => $id,
            'numero_processo' => self::NUMERO,
            'siglaTribunal' => 'TJDFT',
            'tipoComunicacao' => 'Intimação',
            'nomeOrgao' => '1ª Vara Cível de Brasília',
            'texto' => '<p>Fica a parte autora intimada para apresentar réplica no prazo de 15 dias.</p>',
            'data_disponibilizacao' => $data,
        ], $ids);
        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturnCallback(
            static fn (ConsultaComunicacoesQuery $q): RespostaComunicacoes => $q->numeroProcesso === self::NUMERO
                ? new RespostaComunicacoes(\count($itens), $itens)
                : new RespostaComunicacoes(0, []),
        );
        static::getContainer()->set(DjenClient::class, $mock);
    }

    private function captar(Tenant $tenant): void
    {
        static::getContainer()->get(SincronizarPublicacoesDjenUseCase::class)
            ->sincronizarNumeros($tenant, PeriodoConsulta::ultimosDias(30, new \DateTimeImmutable('2026-10-19')), [self::NUMERO]);
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: ClientePF} */
    private function cenario(): array
    {
        [$responsavel, $tenant] = $this->criarAdmin();
        $tenant->setName('Silva & Souza Advogados');
        $pasta = $this->criarPasta($tenant);
        $pasta->setResponsavel($responsavel);
        $this->vincular($pasta, $this->criarProcesso($tenant, self::NUMERO));
        $cliente = $this->criarClientePF($tenant, $pasta, 'Maria da Silva');
        $cliente->setEmail(self::EMAIL_DO_CLIENTE);
        $this->em()->flush();

        return [$responsavel, $tenant, $pasta, $cliente];
    }

    private function ligar(Tenant $tenant, ClientePF $cliente, User $quem, ChaveDoEnvioAutomatico $chave): void
    {
        $linha = new EnvioAutomaticoDoCliente($tenant, $cliente, null, new \DateTimeImmutable());
        $linha->definir($chave, true, $quem, new \DateTimeImmutable());
        $this->em()->persist($linha);
        $this->em()->flush();
    }

    /** @return list<Email> os e-mails que foram para fora do escritório (para o cliente) */
    private function emailsAoCliente(): array
    {
        return array_values(array_filter(
            self::getMailerMessages(),
            static fn ($m): bool => $m instanceof Email && str_ends_with($m->getTo()[0]->getAddress(), '@exemplo.com.br'),
        ));
    }

    /** @return list<EnvioAoCliente> */
    private function historico(Pasta $pasta): array
    {
        return $this->em()->getRepository(EnvioAoCliente::class)->findBy(['pasta' => $pasta->getId(), 'tipo' => TipoDeEnvioAoCliente::Auto], ['id' => 'ASC']);
    }

    #[TestDox('Padrão (D1): com as chaves desligadas, a captação não manda nada ao cliente (o aviso da equipe segue)')]
    public function testChaveDesligadaNaoEnvia(): void
    {
        [, $tenant, $pasta] = $this->cenario();
        $this->dublarDjen([890001]);

        $this->captar($tenant);

        self::assertSame([], $this->emailsAoCliente());
        self::assertSame([], $this->historico($pasta));
        self::assertEmailCount(1, null, 'só o e-mail do "Avisar quando chegar" ao responsável');
    }

    #[TestDox('"Novas movimentações" ligada: o cliente recebe o texto oficial com o remetente do escritório, Responder para o responsável e o descadastro; o histórico guarda a publicação; a captação repetida não manda de novo')]
    public function testChaveLigadaEnvia(): void
    {
        [$responsavel, $tenant, $pasta, $cliente] = $this->cenario();
        $this->ligar($tenant, $cliente, $responsavel, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890011]);

        $this->captar($tenant);
        $this->captar($tenant);

        $emails = $this->emailsAoCliente();
        self::assertCount(1, $emails);
        $email = $emails[0];
        self::assertSame(self::EMAIL_DO_CLIENTE, $email->getTo()[0]->getAddress());
        self::assertSame('Nova movimentação no seu processo 0701134-57.2025.8.07.0007', $email->getSubject());
        self::assertSame('Silva & Souza Advogados via BlueJus', $email->getFrom()[0]->getName());
        self::assertSame($responsavel->getEmail(), $email->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('/email/descadastro', (string) $email->getHeaders()->getHeaderBody('List-Unsubscribe'));
        self::assertEmailHtmlBodyContains($email, 'Fica a parte autora intimada para apresentar réplica no prazo de 15 dias.');
        self::assertEmailHtmlBodyContains($email, 'Olá, Maria.');
        self::assertEmailHtmlBodyContains($email, 'Não quero mais receber estes avisos');
        self::assertEmailHtmlBodyNotContains($email, '—');

        $historico = $this->historico($pasta);
        self::assertCount(1, $historico);
        $publicacao = $this->em()->getRepository(PublicacaoDjen::class)->findOneBy(['tenant' => $tenant, 'djenId' => '890011']);
        self::assertNotNull($publicacao);
        self::assertSame($publicacao->getId(), $historico[0]->getReferencia());
        self::assertSame('intimação de 02/10', $historico[0]->getDescricao());
        self::assertSame([self::EMAIL_DO_CLIENTE], $historico[0]->getPara());
    }

    #[TestDox('E-mail descadastrado pelo cliente não recebe; o outro e-mail ativo recebe')]
    public function testDescadastradoNaoRecebe(): void
    {
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $this->em()->persist(new ClienteEmail($tenant, $cliente, 'outro@exemplo.com.br', RotuloDoEmail::Trabalho, false, null, new \DateTimeImmutable()));
        $this->em()->persist(new ClienteEmail($tenant, $cliente, self::EMAIL_DO_CLIENTE, RotuloDoEmail::Pessoal, true, null, new \DateTimeImmutable(), true));
        $this->em()->flush();
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($tenant, self::EMAIL_DO_CLIENTE, 'push_cliente', 'link_do_email');
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::AudienciasIntimacoes);
        $this->dublarDjen([890021]);

        $this->captar($tenant);

        $emails = $this->emailsAoCliente();
        self::assertCount(1, $emails);
        self::assertSame('outro@exemplo.com.br', $emails[0]->getTo()[0]->getAddress());
        self::assertSame(['outro@exemplo.com.br'], $this->historico($pasta)[0]->getPara());
    }

    #[TestDox('Com Controladoria: a cópia vai só na primeira mensagem do envio e fica no histórico')]
    public function testCopiaNaPrimeira(): void
    {
        $controle = (new User())->setEmail('controle@escritorio.com.br')->setFullName('Ana Controle');
        static::getContainer()->set(ControladoriaDoEscritorioInterface::class, new class ($controle) implements ControladoriaDoEscritorioInterface {
            public function __construct(private readonly User $controle)
            {
            }

            public function pessoas(Tenant $tenant): array
            {
                return [$this->controle];
            }
        });
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $this->em()->persist(new ClienteEmail($tenant, $cliente, self::EMAIL_DO_CLIENTE, RotuloDoEmail::Pessoal, true, null, new \DateTimeImmutable(), true));
        $this->em()->persist(new ClienteEmail($tenant, $cliente, 'outro@exemplo.com.br', RotuloDoEmail::Trabalho, false, null, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890031]);

        $this->captar($tenant);

        $emails = $this->emailsAoCliente();
        self::assertCount(2, $emails);
        self::assertSame('controle@escritorio.com.br', $emails[0]->getCc()[0]->getAddress());
        self::assertSame([], $emails[1]->getCc());
        self::assertSame(['controle@escritorio.com.br'], $this->historico($pasta)[0]->getCc());
    }
    #[TestDox('D6: "Buscar agora" numa pasta nova que acha publicações de 20 dias atrás não manda nada ao cliente')]
    public function testPublicacaoAntigaNaoVai(): void
    {
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890041, 890042], '2026-09-13');

        $this->captar($tenant);

        self::assertSame([], $this->emailsAoCliente());
        self::assertSame([], $this->historico($pasta));
    }

    #[TestDox('Captação repetida: a trava do histórico (não o unique do DJEN) impede o segundo envio das mesmas publicações')]
    public function testTravaDoHistorico(): void
    {
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890051]);
        $this->captar($tenant);
        self::assertCount(1, $this->emailsAoCliente());

        // As mesmas publicações entregues de novo como "novas": só o histórico segura.
        $enviadas = static::getContainer()->get(EnviarMovimentacoesAoClienteUseCase::class)->executar($tenant, ['890051']);

        self::assertSame(0, $enviadas);
        self::assertCount(1, $this->emailsAoCliente());
        self::assertCount(1, $this->historico($pasta));
    }

    #[TestDox('Cliente em duas pastas com o mesmo processo: cada movimentação sai uma vez só (um e-mail, uma linha no histórico)')]
    public function testClienteEmDuasPastasDoMesmoProcesso(): void
    {
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $outra = $this->criarPasta($tenant);
        $outra->addCliente($cliente);
        $this->vincular($outra, $pasta->getProcessoPrincipal() ?? self::fail('sem processo'));
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890061]);

        $this->captar($tenant);

        self::assertCount(1, $this->emailsAoCliente());
        self::assertCount(1, array_merge($this->historico($pasta), $this->historico($outra)));
    }

    #[TestDox('Descadastro em "todos" (o cliente saiu de tudo do escritório): não recebe')]
    public function testDescadastroEmTodos(): void
    {
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar($tenant, self::EMAIL_DO_CLIENTE, DescadastroDeEmailInterface::ASSUNTO_TODOS, 'link_do_email');
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $this->dublarDjen([890071]);

        $this->captar($tenant);

        self::assertSame([], $this->emailsAoCliente());
        self::assertSame([], $this->historico($pasta));
    }

    #[TestDox('Falha no aviso à equipe que fecha o EntityManager: o cliente já recebeu, com o histórico gravado (o envio ao cliente vem antes)')]
    public function testFalhaDaEquipeNaoDeixaOClienteSemNada(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->set(NotificacaoService::class, new class ($em, static::getContainer()->get(NotificacaoRepository::class), static::getContainer()->get(UserRepository::class), static::getContainer()->get(PermissionChecker::class)) extends NotificacaoService {
            public function __construct(private readonly EntityManagerInterface $em, NotificacaoRepository $notificacoes, UserRepository $usuarios, PermissionChecker $permissoes)
            {
                parent::__construct($em, $notificacoes, $usuarios, $permissoes);
            }

            public function criarNotificacao(User $usuario, Tenant $tenant, string $tipo, string $mensagem, ?string $url = null, ?string $texto = null): Notificacao
            {
                if ($tipo === Notificacao::TIPO_PUSH_PUBLICACAO_PASTA) {
                    $this->em->close();

                    throw new \RuntimeException('banco caiu no aviso à equipe');
                }

                return parent::criarNotificacao($usuario, $tenant, $tipo, $mensagem, $url, $texto);
            }
        });
        [$quem, $tenant, $pasta, $cliente] = $this->cenario();
        $this->ligar($tenant, $cliente, $quem, ChaveDoEnvioAutomatico::NovasMovimentacoes);
        $pastaId = (int) $pasta->getId();
        $this->dublarDjen([890081]);

        $this->captar($tenant);

        self::assertFalse($em->isOpen(), 'o aviso à equipe fechou o EntityManager');
        self::assertCount(1, $this->emailsAoCliente());
        $total = (int) static::getContainer()->get('doctrine')->getConnection()->fetchOne("SELECT COUNT(*) FROM envio_ao_cliente WHERE pasta_id = ? AND tipo = 'auto'", [$pastaId]);
        self::assertSame(1, $total);
    }
}
