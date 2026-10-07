<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Exception\MensagemPastaNaoEditavelException;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Service\JanelaDeEdicaoDeComentario;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Repository\NotificacaoRepository;
use App\Repository\UserRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(EditarMensagemPastaUseCase::class)]
#[CoversClass(ResolucaoDeMencoesDoRegistro::class)]
final class EditarMensagemPastaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private EntityManagerInterface&MockObject $em;
    private UserRepository&MockObject $userRepository;
    private PermissionChecker&MockObject $permissionChecker;
    private NotificacaoRepository&MockObject $notificacaoRepository;
    private NotificacaoService&MockObject $notificacaoService;
    private MencoesDoRegistro $mencoes;
    private EditarMensagemPastaUseCase $useCase;
    private Tenant $tenant;
    private User $autor;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->userRepository        = $this->createMock(UserRepository::class);
        $this->permissionChecker     = $this->createMock(PermissionChecker::class);
        $this->notificacaoRepository = $this->createMock(NotificacaoRepository::class);
        $this->notificacaoService    = $this->createMock(NotificacaoService::class);
        $this->mencoes               = new MencoesDoRegistro($this->criarSanitizadorTextoRico());

        // Como o real: roda o callback.
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn): mixed => $fn($this->em));

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $rota, array $params = []): string => '/pasta/' . $params['id'],
        );

        $this->useCase = new EditarMensagemPastaUseCase(
            $this->em,
            $this->criarSanitizadorTextoRico(),
            new JanelaDeEdicaoDeComentario(),
            new ResolucaoDeMencoesDoRegistro(
                $this->mencoes,
                $this->userRepository,
                $this->permissionChecker,
                $this->notificacaoRepository,
                $this->notificacaoService,
                $urlGenerator,
            ),
        );
        $this->tenant  = new Tenant();
        $this->autor   = (new User())->setEmail('autor@test.com');
    }

    private function novaMensagem(string $conteudo = 'Original'): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta(new Pasta())
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo($conteudo);
    }

    public function testAutorDentroDaJanelaEdita(): void
    {
        $mensagem = $this->novaMensagem();
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Corrigido');

        self::assertSame('Corrigido', $mensagem->getConteudo());
        self::assertNotNull($mensagem->getEditadaEm());
    }

    public function testNaoAutorLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();
        $outro    = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoEditavelException::class);

        $this->useCase->executar($mensagem, $outro, $this->tenant, 'Corrigido');
    }

    public function testTenantDiferenteLancaExcecao(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(MensagemPastaNaoEditavelException::class);

        $this->useCase->executar($mensagem, $this->autor, new Tenant(), 'Corrigido');
    }

    public function testConteudoVazioLancaInvalidArgument(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, '   ');
    }

    public function testConteudoAcimaDe5000LancaInvalidArgument(): void
    {
        $mensagem = $this->novaMensagem();

        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, str_repeat('a', 5001));
    }

    public function testPodeEditarRetornaFalseForaDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $expirado = $mensagem->getCriadaEm()->add(new \DateInterval('PT15M1S'));

        self::assertFalse($this->useCase->podeEditar($mensagem, $this->autor, $this->tenant, $expirado));
    }

    public function testPodeEditarRetornaTrueDentroDaJanela(): void
    {
        $mensagem = $this->novaMensagem();
        $dentro   = $mensagem->getCriadaEm()->add(new \DateInterval('PT15M'));

        self::assertTrue($this->useCase->podeEditar($mensagem, $this->autor, $this->tenant, $dentro));
    }

    // ── @menção na edição (item 20b) ─────────────────────────────────────────

    /** Mensagem 950 na pasta 41 (NUP 1232) do escritório 7; o autor (id 1) é "Ana Paula Souza". */
    private function mensagemComIds(string $conteudo = 'Original'): PastaMensagem
    {
        $this->definirId($this->tenant, 7);
        $this->definirId($this->autor, 1);
        $this->autor->setFullName('Ana Paula Souza');
        $pasta = $this->definirId(new Pasta(), 41);
        $pasta->setNup('1232');

        $mensagem = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($this->autor)
            ->setTenant($this->tenant)
            ->setConteudo($conteudo);

        return $this->definirId($mensagem, 950);
    }

    private function pessoa(int $id, string $nome): User
    {
        return $this->definirId((new User())->setEmail('p' . $id . '@test.com')->setFullName($nome), $id);
    }

    /** @param list<User> $colegas colegas ATIVOS do escritório (a consulta é presa ao tenant) */
    private function colegas(array $colegas): void
    {
        $this->userRepository->method('findColaboradoresAtivosPorTenant')
            ->with($this->identicalTo($this->tenant))
            ->willReturn($colegas);
    }

    #[TestDox('Editar com token forjado (id inexistente) ou de OUTRO escritório grava texto comum, sem destaque nem notificação')]
    public function testEdicaoComTokenForjadoGravaTextoComum(): void
    {
        $mensagem = $this->mensagemComIds();
        // Só os colegas deste escritório: o de outro escritório (id 77) não está na lista.
        $this->colegas([$this->autor, $this->pessoa(2, 'Bruno Lima')]);
        $this->notificacaoService->expects($this->never())->method('criar');
        $this->permissionChecker->expects($this->never())->method('canAccessResource');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, '<p>Falar com @[Diretor Geral](user:9999999999) e @[Carla de Fora](user:77)</p>');

        self::assertSame('<p>Falar com @Diretor Geral e @Carla de Fora</p>', $mensagem->getConteudo());
        self::assertSame([], $this->mencoes->extrairIds($mensagem->getConteudo()));
        self::assertStringNotContainsString('ps-mencao', $this->mencoes->exibir($mensagem->getConteudo()));
    }

    #[TestDox('Editar com id válido grava o nome do BANCO (o rótulo digitado não vale)')]
    public function testEdicaoReescreveONomeDoBanco(): void
    {
        $mensagem = $this->mensagemComIds();
        $this->colegas([$this->pessoa(2, 'Bruno Lima')]);
        $this->permissionChecker->method('canAccessResource')->willReturn(false);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Oi @[Diretor Geral](user:2)');

        self::assertSame('Oi @[Bruno Lima](user:2)', $mensagem->getConteudo());
    }

    #[TestDox('Menção NOVA na edição notifica o mencionado (com acesso), com o link da mensagem, na transação da edição')]
    public function testMencaoNovaNaEdicaoNotifica(): void
    {
        $mensagem = $this->mensagemComIds('Original');
        $bruno    = $this->pessoa(2, 'Bruno Lima');
        $this->colegas([$this->autor, $bruno]);
        $this->permissionChecker->method('canAccessResource')
            ->with($this->identicalTo($bruno), $this->identicalTo($this->tenant), AccessRequest::RESOURCE_PASTA, 41, AccessRequest::ACTION_VIEW)
            ->willReturn(true);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);
        // Só conta: o stub do setUp já roda o callback. Repetir o callback aqui o executaria DUAS
        // vezes (o PHPUnit invoca todos os matchers que casam) e duplicaria a notificação.
        $this->em->expects($this->once())->method('wrapInTransaction');

        $notificacao = new Notificacao();
        $this->notificacaoService->expects($this->once())
            ->method('criar')
            ->with(
                $this->identicalTo($bruno),
                $this->identicalTo($this->tenant),
                Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
                'Ana mencionou você na Pasta 1232 · Dados da pasta',
                'Corrigindo: @Bruno Lima confirma',
            )
            ->willReturn($notificacao);

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Corrigindo: @[Bruno](user:2) confirma');

        self::assertSame('/pasta/41#pasta-msg-950', $notificacao->getUrl());
    }

    #[TestDox('Mencionado que JÁ foi avisado por esta mensagem não recebe de novo ao editar (anti-duplicata pelo link)')]
    public function testMencionadoJaNotificadoNaoRecebeDeNovo(): void
    {
        $mensagem = $this->mensagemComIds('Oi @[Bruno Lima](user:2)');
        $bruno    = $this->pessoa(2, 'Bruno Lima');
        $this->colegas([$bruno]);
        $this->permissionChecker->method('canAccessResource')->willReturn(true);
        $this->notificacaoRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'usuario' => $bruno,
                'tenant'  => $this->tenant,
                'tipo'    => Notificacao::TIPO_PASTA_MENCAO_REGISTRO,
                'url'     => '/pasta/41#pasta-msg-950',
            ])
            ->willReturn(new Notificacao());
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Oi @[Bruno](user:2), corrigido');
    }

    #[TestDox('Na edição, mencionado sem acesso à pasta e o próprio autor não são notificados')]
    public function testEdicaoSemAcessoEASiMesmoNaoNotificam(): void
    {
        $mensagem = $this->mensagemComIds();
        $this->colegas([$this->autor, $this->pessoa(3, 'Carla Dias')]);
        $this->permissionChecker->method('canAccessResource')->willReturn(false);
        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, '@[Ana](user:1) e @[Carla](user:3)');

        self::assertSame('@[Ana Paula Souza](user:1) e @[Carla Dias](user:3)', $mensagem->getConteudo());
    }

    #[TestDox('Edição sem menção válida não abre transação nem notifica (fluxo de antes)')]
    public function testEdicaoSemMencaoFluxoSimples(): void
    {
        $mensagem = $this->novaMensagem();
        $this->em->expects($this->never())->method('wrapInTransaction');
        $this->em->expects($this->once())->method('flush');
        $this->userRepository->expects($this->never())->method('findColaboradoresAtivosPorTenant');

        $this->useCase->executar($mensagem, $this->autor, $this->tenant, 'Sem ninguém, ana@x.com');
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private function definirId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
