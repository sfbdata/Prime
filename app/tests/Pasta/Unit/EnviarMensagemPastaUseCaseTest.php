<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\NotificacaoRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Service\PermissionChecker;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[CoversClass(EnviarMensagemPastaUseCase::class)]
final class EnviarMensagemPastaUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    private EntityManagerInterface&MockObject $em;
    private NotificacaoService&MockObject $notificacaoService;
    private NotificacaoRepository&MockObject $notificacaoRepository;
    private UserTenantRepository&MockObject $userTenantRepository;
    private PermissionChecker&MockObject $permissionChecker;
    private EnviarMensagemPastaUseCase $useCase;
    private Pasta $pasta;
    private User $autor;
    private Tenant $tenant;
    /** A última mensagem passada ao `persist` — o dublê do flush dá id a ela, como o banco. */
    private ?PastaMensagem $persistida = null;
    private int $proximoId = 950;

    protected function setUp(): void
    {
        $this->em                    = $this->createMock(EntityManagerInterface::class);
        $this->notificacaoService    = $this->createMock(NotificacaoService::class);
        $this->notificacaoRepository = $this->createMock(NotificacaoRepository::class);
        $this->userTenantRepository  = $this->createMock(UserTenantRepository::class);
        $this->permissionChecker     = $this->createMock(PermissionChecker::class);

        // Como o real: roda o callback (o flush final e o commit não importam ao dublê).
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $fn): mixed => $fn($this->em));
        // O id da resposta só nasce no flush — é o que o link da notificação usa.
        $this->em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof PastaMensagem) {
                $this->persistida = $o;
            }
        });
        $this->em->method('flush')->willReturnCallback(function (): void {
            if ($this->persistida !== null && $this->persistida->getId() === null) {
                $this->definirId($this->persistida, $this->proximoId++);
            }
        });

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $rota, array $params = []): string => '/pasta/' . $params['id'],
        );

        $this->useCase = new EnviarMensagemPastaUseCase(
            $this->em,
            $this->criarSanitizadorTextoRico(),
            $this->notificacaoService,
            $this->notificacaoRepository,
            $this->userTenantRepository,
            $this->permissionChecker,
            $urlGenerator,
        );

        $this->tenant = new Tenant();
        $this->autor  = (new User())->setEmail('autor@test.com');
        $this->pasta  = new Pasta();
    }

    public function testEnviarMensagemCriaEntidade(): void
    {
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaMensagem::class));
        $this->em->expects($this->once())->method('flush');

        $mensagem = $this->useCase->executar($this->pasta, $this->autor, 'Mensagem de teste', $this->tenant);

        self::assertSame('Mensagem de teste', $mensagem->getConteudo());
        self::assertSame($this->pasta, $mensagem->getPasta());
        self::assertSame($this->autor, $mensagem->getAutor());
        self::assertSame($this->tenant, $mensagem->getTenant());
    }

    public function testConteudoVazioLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, '   ', $this->tenant);
    }

    public function testConteudoAcimaDe5000CaracteresLancaExcecao(): void
    {
        $this->em->expects($this->never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, str_repeat('a', 5001), $this->tenant);
    }

    // ── Responder ────────────────────────────────────────────────────────────

    private function mensagemDe(Pasta $pasta, Tenant $tenant, ?PastaMensagem $respostaA = null): PastaMensagem
    {
        return (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($this->autor)
            ->setTenant($tenant)
            ->setConteudo('Original')
            ->setRespostaA($respostaA);
    }

    #[TestDox('Sem resposta_a a mensagem nasce como registro comum (não é resposta)')]
    public function testSemRespostaNasceRegistroComum(): void
    {
        $mensagem = $this->useCase->executar($this->pasta, $this->autor, 'Comum', $this->tenant);

        self::assertNull($mensagem->getRespostaA());
        self::assertFalse($mensagem->isResposta());
    }

    #[TestDox('Responder a um registro da MESMA pasta e do MESMO escritório liga a resposta a ele')]
    public function testRespostaValidaLigaARaiz(): void
    {
        $original = $this->mensagemDe($this->pasta, $this->tenant);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $resposta = $this->useCase->executar($this->pasta, $this->autor, 'Respondendo', $this->tenant, $original);

        self::assertSame($original, $resposta->getRespostaA());
        self::assertTrue($resposta->isResposta());
        self::assertFalse($resposta->isRespostaOrfa());
    }

    #[TestDox('Responder a um registro de OUTRA pasta (mesmo escritório) é recusado e nada é gravado')]
    public function testRespostaAMensagemDeOutraPastaRecusa(): void
    {
        $deOutraPasta = $this->mensagemDe(new Pasta(), $this->tenant);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Invasão', $this->tenant, $deOutraPasta);
    }

    #[TestDox('Responder a um registro de OUTRO escritório é recusado e nada é gravado (IDOR)')]
    public function testRespostaAMensagemDeOutroTenantRecusa(): void
    {
        $deOutroTenant = $this->mensagemDe($this->pasta, new Tenant());

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Invasão', $this->tenant, $deOutroTenant);
    }

    #[TestDox('Uma mensagem sem pasta ou sem tenant não prova a posse: a resposta é recusada')]
    public function testRespostaAMensagemSemPastaOuTenantRecusa(): void
    {
        $semPasta = (new PastaMensagem())->setTenant($this->tenant)->setConteudo('x');

        $this->em->expects($this->never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $semPasta);
    }

    #[TestDox('Responder a uma RESPOSTA liga à raiz dela: a conversa tem um nível só')]
    public function testRespostaDeRespostaApontaParaARaiz(): void
    {
        $raiz     = $this->mensagemDe($this->pasta, $this->tenant);
        $resposta = $this->mensagemDe($this->pasta, $this->tenant, $raiz);

        $nova = $this->useCase->executar($this->pasta, $this->autor, 'Tréplica', $this->tenant, $resposta);

        self::assertSame($raiz, $nova->getRespostaA());
        self::assertNull($raiz->getRespostaA(), 'a raiz continua raiz');
    }

    #[TestDox('Proxy do ORM (outra instância, mesmo id) conta como a mesma pasta e o mesmo escritório')]
    public function testMesmaPastaPorIdAceita(): void
    {
        $this->definirId($this->pasta, 41);
        $this->definirId($this->tenant, 7);
        $pastaProxy  = $this->definirId(new Pasta(), 41);
        $tenantProxy = $this->definirId(new Tenant(), 7);
        $original    = $this->mensagemDe($pastaProxy, $tenantProxy);

        $nova = $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $original);

        self::assertSame($original, $nova->getRespostaA());
    }

    #[TestDox('Ids diferentes recusam mesmo com tudo o mais igual')]
    public function testOutraPastaPorIdRecusa(): void
    {
        $this->definirId($this->pasta, 41);
        $this->definirId($this->tenant, 7);
        $original = $this->mensagemDe($this->definirId(new Pasta(), 42), $this->definirId(new Tenant(), 7));

        $this->em->expects($this->never())->method('persist');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $original);
    }

    // ── Notificar o autor do comentário respondido ───────────────────────────

    /** Cenário com ids: pasta 41 (NUP 1232) no escritório 7; a raiz (id 900) é do $dono. */
    private function cenarioDeResposta(User $dono, string $conteudoRaiz = 'Ligar para o cliente amanhã'): PastaMensagem
    {
        $this->definirId($this->pasta, 41);
        $this->definirId($this->tenant, 7);
        $this->pasta->setNup('1232');
        $this->definirId($this->autor, 1);
        $this->autor->setFullName('Ana Paula Souza');

        $raiz = (new PastaMensagem())
            ->setPasta($this->pasta)
            ->setAutor($dono)
            ->setTenant($this->tenant)
            ->setConteudo($conteudoRaiz);

        return $this->definirId($raiz, 900);
    }

    private function dono(): User
    {
        return $this->definirId((new User())->setEmail('dono@test.com')->setFullName('Bruno Lima'), 2);
    }

    private function donoComAcesso(User $dono, bool $vinculo = true, bool $acesso = true): void
    {
        $this->userTenantRepository->method('existeVinculoAtivo')
            ->with($this->identicalTo($dono), $this->identicalTo($this->tenant))
            ->willReturn($vinculo);
        $this->permissionChecker->method('canAccessResource')
            ->with($this->identicalTo($dono), $this->identicalTo($this->tenant), AccessRequest::RESOURCE_PASTA, 41, AccessRequest::ACTION_VIEW)
            ->willReturn($acesso);
    }

    #[TestDox('Responder o comentário de outra pessoa notifica o autor dele, no escritório da pasta, com link para a própria resposta')]
    public function testRespostaNotificaOAutorDoComentario(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono, '<p>Ligar para o <strong>cliente</strong> amanhã</p>');
        $this->donoComAcesso($dono);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);

        $notificacao = new Notificacao();
        $this->notificacaoService->expects($this->once())
            ->method('criar')
            ->with(
                $this->identicalTo($dono),
                $this->identicalTo($this->tenant),
                Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
                'Ana respondeu seu comentário',
                $this->callback(static function (?string $texto): bool {
                    self::assertNotNull($texto);
                    self::assertStringStartsWith('"Já liguei, ele confirmou" · em Dados da pasta da Pasta 1232 · ', $texto);
                    self::assertMatchesRegularExpression('#· \d{2}/\d{2}/\d{4} \d{2}:\d{2}\. #u', $texto);
                    self::assertStringEndsWith('Seu comentário: "Ligar para o cliente amanhã"', $texto);

                    return true;
                }),
            )
            ->willReturn($notificacao);
        // Resposta e notificação na MESMA transação.
        $this->em->expects($this->once())->method('flush');

        $resposta = $this->useCase->executar($this->pasta, $this->autor, '<p>Já liguei, <em>ele</em> confirmou</p>', $this->tenant, $raiz);

        self::assertSame(950, $resposta->getId());
        self::assertSame('/pasta/41#pasta-msg-950', $notificacao->getUrl(), 'o link é da resposta, não da raiz (900)');
    }

    #[TestDox('Duas pessoas com o mesmo primeiro nome respondendo o mesmo comentário geram DUAS notificações, cada uma com o link da sua resposta')]
    public function testDuasMariasGeramDuasNotificacoes(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        $this->donoComAcesso($dono);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);

        $mariaA = $this->definirId((new User())->setEmail('ma@test.com')->setFullName('Maria Souza'), 5);
        $mariaB = $this->definirId((new User())->setEmail('mb@test.com')->setFullName('Maria Lima'), 6);

        $criadas = [];
        $this->notificacaoService->expects($this->exactly(2))
            ->method('criar')
            ->with($this->identicalTo($dono), $this->identicalTo($this->tenant), Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO, 'Maria respondeu seu comentário')
            ->willReturnCallback(static function () use (&$criadas): Notificacao {
                return $criadas[] = new Notificacao();
            });

        $this->useCase->executar($this->pasta, $mariaA, 'Da Maria Souza', $this->tenant, $raiz);
        $this->persistida = null;
        $this->useCase->executar($this->pasta, $mariaB, 'Da Maria Lima', $this->tenant, $raiz);

        self::assertCount(2, $criadas);
        self::assertSame('/pasta/41#pasta-msg-950', $criadas[0]->getUrl());
        self::assertSame('/pasta/41#pasta-msg-951', $criadas[1]->getUrl());
    }

    #[TestDox('A mesma pessoa respondendo duas vezes o mesmo comentário gera DUAS notificações')]
    public function testMesmaPessoaDuasRespostasGeraDuas(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        $this->donoComAcesso($dono);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);

        $this->notificacaoService->expects($this->exactly(2))
            ->method('criar')
            ->willReturnCallback(static fn (): Notificacao => new Notificacao());

        $this->useCase->executar($this->pasta, $this->autor, 'Primeira', $this->tenant, $raiz);
        $this->persistida = null;
        $this->useCase->executar($this->pasta, $this->autor, 'Segunda', $this->tenant, $raiz);
    }

    #[TestDox('Tudo-ou-nada: falha ao criar a notificação propaga de dentro da transação (a resposta não é confirmada sozinha)')]
    public function testFalhaNaNotificacaoPropagaDaTransacao(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        $this->donoComAcesso($dono);
        $this->notificacaoRepository->method('findOneBy')->willReturn(null);

        $this->em->expects($this->once())->method('wrapInTransaction');
        $this->notificacaoService->method('criar')->willThrowException(new \RuntimeException('falhou'));

        $this->expectException(\RuntimeException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $raiz);
    }

    #[TestDox('Responder uma RESPOSTA notifica o autor da raiz (é a ela que a conversa fica pendurada)')]
    public function testRespostaDeRespostaNotificaOAutorDaRaiz(): void
    {
        $dono     = $this->dono();
        $raiz     = $this->cenarioDeResposta($dono);
        $terceiro = $this->definirId((new User())->setEmail('c@test.com')->setFullName('Carla'), 3);
        $resposta = $this->definirId(
            (new PastaMensagem())->setPasta($this->pasta)->setAutor($terceiro)->setTenant($this->tenant)->setConteudo('x')->setRespostaA($raiz),
            901,
        );
        $this->donoComAcesso($dono);

        $this->notificacaoService->expects($this->once())
            ->method('criar')
            ->with($this->identicalTo($dono))
            ->willReturn(new Notificacao());

        $this->useCase->executar($this->pasta, $this->autor, 'Tréplica', $this->tenant, $resposta);
    }

    #[TestDox('Responder o PRÓPRIO comentário não notifica ninguém (nem por proxy com o mesmo id)')]
    public function testResponderASiMesmoNaoNotifica(): void
    {
        $euDeNovo = $this->definirId((new User())->setEmail('autor@test.com'), 1);
        $raiz     = $this->cenarioDeResposta($euDeNovo);

        $this->notificacaoService->expects($this->never())->method('criar');
        $this->em->expects($this->once())->method('flush');

        $resposta = $this->useCase->executar($this->pasta, $this->autor, 'Anotando de novo', $this->tenant, $raiz);

        self::assertSame($raiz, $resposta->getRespostaA(), 'a resposta continua gravada');
    }

    #[TestDox('Autor do comentário sem acesso à pasta (ResourceAccess/permissão) não é notificado; a resposta é gravada')]
    public function testAutorSemAcessoAPastaNaoENotificado(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        $this->donoComAcesso($dono, vinculo: true, acesso: false);

        $this->notificacaoService->expects($this->never())->method('criar');
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(PastaMensagem::class));
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $raiz);
    }

    #[TestDox('Ex-colaborador (sem vínculo ativo no escritório) não é notificado, mesmo que o checker deixasse')]
    public function testExColaboradorNaoENotificado(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        // Super-admin passa no canAccessResource sem vínculo: o vínculo ativo é a trava própria.
        $this->donoComAcesso($dono, vinculo: false, acesso: true);

        $this->notificacaoService->expects($this->never())->method('criar');

        $this->useCase->executar($this->pasta, $this->autor, 'Resposta', $this->tenant, $raiz);
    }

    #[TestDox('A MESMA resposta (mesmo destinatário, escritório, tipo e link) já notificada não duplica — idempotência')]
    public function testMesmaRespostaJaNotificadaNaoDuplica(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono);
        $this->donoComAcesso($dono);

        $this->notificacaoRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'usuario' => $dono,
                'tenant'  => $this->tenant,
                'tipo'    => Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO,
                'url'     => '/pasta/41#pasta-msg-950',
            ])
            ->willReturn(new Notificacao());
        $this->notificacaoService->expects($this->never())->method('criar');
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->pasta, $this->autor, 'Mais uma', $this->tenant, $raiz);
    }

    #[TestDox('Registro comum (sem resposta) não consulta nem cria notificação')]
    public function testRegistroComumNaoNotifica(): void
    {
        $this->notificacaoService->expects($this->never())->method('criar');
        $this->permissionChecker->expects($this->never())->method('canAccessResource');

        $this->useCase->executar($this->pasta, $this->autor, 'Comum', $this->tenant);
    }

    #[TestDox('Resposta recusada por ser de outro escritório não notifica ninguém')]
    public function testRespostaCrossTenantNaoNotifica(): void
    {
        $dono       = $this->dono();
        $deOutroEsc = (new PastaMensagem())->setPasta($this->pasta)->setAutor($dono)->setTenant(new Tenant())->setConteudo('x');

        $this->notificacaoService->expects($this->never())->method('criar');
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->pasta, $this->autor, 'Invasão', $this->tenant, $deOutroEsc);
    }

    #[TestDox('A resposta vai cortada em 140 caracteres de texto (sem HTML) e o original em 80')]
    public function testTextoCortadoNosLimitesDoDesenho(): void
    {
        $dono = $this->dono();
        $raiz = $this->cenarioDeResposta($dono, str_repeat('o', 200));
        $this->donoComAcesso($dono);

        $this->notificacaoService->expects($this->once())
            ->method('criar')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->callback(static function (?string $texto): bool {
                    self::assertStringStartsWith('"' . str_repeat('r', 140) . '" · ', (string) $texto);
                    self::assertStringEndsWith('"' . str_repeat('o', 80) . '"', (string) $texto);

                    return true;
                }),
            )
            ->willReturn(new Notificacao());

        $this->useCase->executar($this->pasta, $this->autor, '<p>' . str_repeat('r', 300) . '</p>', $this->tenant, $raiz);
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
