<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\DTO\EnviarMensagemSuporteInput;
use App\Auth\Entity\MensagemSuporte;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Auth\UseCase\EnviarMensagemSuporteUseCase;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Service\RegraDosMasters;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tests\Auth\Cenario\CenarioDaEntrada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Integração, e não unitário: o UseCase depende do PaginaDeEntrada, que é `final` (não se mocka), e a regra de quem
 * recebe o aviso passa pelas consultas de Masters e de vínculos ativos.
 */
#[CoversClass(EnviarMensagemSuporteUseCase::class)]
final class EnviarMensagemSuporteUseCaseTest extends KernelTestCase
{
    use CenarioDaEntrada;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->limparAjustesDaEntrada();
    }

    private function input(string $nome = 'Ana Souza', ?string $assunto = 'Esqueci a senha'): EnviarMensagemSuporteInput
    {
        return new EnviarMensagemSuporteInput(
            nome: $nome,
            email: uniqid('suporte_') . '@adv.com',
            assunto: $assunto,
            mensagem: 'Não consigo entrar desde ontem.',
            ip: '10.0.0.9',
            userAgent: 'Navegador/1.0',
        );
    }

    private function gravada(EnviarMensagemSuporteInput $input): ?MensagemSuporte
    {
        $this->em()->clear();

        return static::getContainer()->get(MensagemSuporteRepository::class)->findOneBy(['email' => $input->email]);
    }

    #[TestDox('Sem destinatário escolhido: grava a mensagem e avisa no sino todos os Masters ativos com escritório')]
    public function testSemDestinatarioAvisaOsMasters(): void
    {
        $master = $this->master('Marta Master');
        $masterSemEscritorio = $this->master('Mário Sem Escritório', comEscritorio: false);
        $masterInativo = $this->master('Mônica Inativa', ativa: false);
        $comum = $this->pessoa('Carla Comum');

        $input = $this->input();
        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($input);

        $mensagem = $this->gravada($input);
        self::assertNotNull($mensagem);
        self::assertNull($mensagem->getDestinatario());
        self::assertSame('Esqueci a senha', $mensagem->getAssunto());
        self::assertSame('10.0.0.9', $mensagem->getIp());
        self::assertSame('login', $mensagem->getOrigem());

        $avisos = $this->avisosDoSuporte($master);
        self::assertCount(1, $avisos);
        self::assertSame('Suporte · Ana Souza', $avisos[0]['titulo']);
        self::assertStringContainsString('pela página de login:', $avisos[0]['mensagem']);
        self::assertSame('/suporte/mensagens', $avisos[0]['url']);
        self::assertSame($this->escritorioDe($master), (int) $avisos[0]['tenant_id']);
        self::assertSame([], $this->avisosDoSuporte($masterSemEscritorio), 'sem escritório não há sino');
        self::assertSame([], $this->avisosDoSuporte($masterInativo));
        self::assertSame([], $this->avisosDoSuporte($comum));
    }

    #[TestDox('O aviso cai na conta do Master, nunca no escritório de um cliente (a Notificacao é auditada)')]
    public function testAvisoNuncaCaiNoEscritorioDoCliente(): void
    {
        $titular = $this->pessoa('Tito Titular');
        $cliente = $this->em()->find(Tenant::class, $this->escritorioDe($titular));
        $cliente->setCriadoPor($titular);
        $master = $this->pessoa('Marta Master', ['ROLE_SUPER_ADMIN'], comEscritorio: false);
        $this->em()->persist(new UserTenant($master, $cliente));
        $this->em()->flush();
        $propria = new Tenant();
        $propria->setName('BlueJus ' . uniqid());
        $propria->setCriadoPor($master);
        $this->em()->persist($propria);
        $this->em()->persist(new UserTenant($master, $propria));
        $this->em()->flush();

        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($this->input());

        $avisos = $this->avisosDoSuporte($master);
        self::assertCount(1, $avisos);
        self::assertSame($propria->getId(), (int) $avisos[0]['tenant_id']);
    }

    #[TestDox('Com destinatário escolhido: só ele é avisado e a mensagem guarda quem era')]
    public function testComDestinatarioAvisaSoEle(): void
    {
        $master = $this->master();
        $escolhida = $this->colega($master, 'Carla Escolhida');
        $this->escolherDestinatario($escolhida);

        $input = $this->input();
        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($input);

        self::assertSame($escolhida->getId(), $this->gravada($input)?->getDestinatario()?->getId());
        self::assertCount(1, $this->avisosDoSuporte($escolhida));
        self::assertSame([], $this->avisosDoSuporte($master));
    }

    #[TestDox('Destinatário escolhido que foi desativado: volta a avisar os Masters')]
    public function testDestinatarioInativoVoltaParaOsMasters(): void
    {
        $master = $this->master();
        $inativa = $this->pessoa('Ida Inativa', ativa: false);
        $this->escolherDestinatario($inativa);

        $input = $this->input();
        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($input);

        self::assertNull($this->gravada($input)?->getDestinatario());
        self::assertCount(1, $this->avisosDoSuporte($master));
        self::assertSame([], $this->avisosDoSuporte($inativa));
    }

    #[TestDox('Sem nome e sem assunto: o aviso usa o e-mail e "mensagem"')]
    public function testAvisoSemNomeESemAssunto(): void
    {
        $master = $this->master();
        $input = $this->input(nome: '', assunto: null);

        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($input);

        self::assertSame('Suporte · ' . $input->email, $this->avisosDoSuporte($master)[0]['titulo']);
        self::assertStringStartsWith('💬 Visitante (' . $input->email . ')', $this->avisosDoSuporte($master)[0]['mensagem']);
    }

    #[TestDox('Aviso cortado em 255 caracteres')]
    public function testAvisoCortadoEm255(): void
    {
        $master = $this->master();

        static::getContainer()->get(EnviarMensagemSuporteUseCase::class)->executar($this->input(nome: str_repeat('N', 120)));

        self::assertLessThanOrEqual(255, mb_strlen($this->avisosDoSuporte($master)[0]['mensagem']));
    }

    #[TestDox('Falha no sino vai para o log e não perde a mensagem gravada')]
    public function testFalhaNoSinoNaoPerdeAMensagem(): void
    {
        $master = $this->master();
        $sino = $this->createMock(NotificacaoService::class);
        $sino->method('criarNotificacao')->willThrowException(new \RuntimeException('sino fora do ar'));
        $log = $this->logEmMemoria();

        $input = $this->input();
        $this->useCaseCom($sino, $log)->executar($input);

        self::assertNotNull($this->gravada($input));
        // O banco de teste pode ter outros Masters: procura o registro do Master deste teste.
        $doMaster = array_values(array_filter(
            $log->registros,
            static fn (array $r): bool => ($r[1]['usuario'] ?? null) === $master->getId(),
        ));
        self::assertCount(1, $doMaster);
        self::assertSame('error', $doMaster[0][0]);
    }

    private function useCaseCom(NotificacaoService $sino, AbstractLogger $log): EnviarMensagemSuporteUseCase
    {
        $c = static::getContainer();

        return new EnviarMensagemSuporteUseCase(
            $c->get(MensagemSuporteRepository::class),
            $c->get(PaginaDeEntrada::class),
            $c->get(UserRepository::class),
            $c->get(UserTenantRepository::class),
            $sino,
            $c->get(UrlGeneratorInterface::class),
            $log,
            $c->get(RegraDosMasters::class),
        );
    }

    private function logEmMemoria(): AbstractLogger
    {
        return new class () extends AbstractLogger {
            /** @var list<array{0: mixed, 1: array<mixed>}> */
            public array $registros = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->registros[] = [$level, $context];
            }
        };
    }
}
