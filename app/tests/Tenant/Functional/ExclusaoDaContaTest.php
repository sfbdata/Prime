<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\EventListener\TenantContextValidatorListener;
use App\Master\DTO\ExcluirContaInput;
use App\Master\DTO\SituacaoDaContaInput;
use App\Master\UseCase\ExcluirContaPeloMasterUseCase;
use App\Master\UseCase\ReativarContaUseCase;
use App\Tenant\Controller\ExclusaoDaContaController;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tenant\Enum\EstadoDoPedidoDeExclusao;
use App\Tenant\Exception\PedidoDeExclusaoRecusado;
use App\Tenant\Repository\PedidoDeExclusaoDeContaRepository;
use App\Tenant\UseCase\ExcluirEscritorioUseCase;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\UseCase\PedirExclusaoDaContaUseCase;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * PR 5 (docs/specs/conta-pedido-exclusao.md): o primário pede a exclusão da conta em 2 etapas no Meu
 * Perfil; a conta fica "suspensa a pedido" (equipe fora, primário só no Meu Perfil, sem laço na seleção);
 * desistir devolve; o Master reativa ou exclui (quarentena) e o pedido é decidido junto.
 */
#[CoversClass(ExclusaoDaContaController::class)]
#[CoversClass(PedirExclusaoDaContaUseCase::class)]
#[CoversClass(BloqueioPorPedidoDeExclusao::class)]
#[CoversClass(TenantContextValidatorListener::class)]
#[CoversClass(ExcluirEscritorioUseCase::class)]
#[CoversClass(PedidoDeExclusaoDeContaRepository::class)]
final class ExclusaoDaContaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    // ------------------------------------------------------------------------------------------------
    // Cenário
    // ------------------------------------------------------------------------------------------------

    private function conta(): Tenant
    {
        $conta = (new Tenant())->setName('Escritório Exclusão ' . uniqid())->setIsActive(true);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    /** @param list<string> $papeis */
    private function pessoa(Tenant $conta, bool $primario, array $papeis = ['ROLE_USER']): User
    {
        $user = (new User())
            ->setEmail('ex_' . uniqid() . '@test.com')
            ->setFullName($primario ? 'Ana Primária' : 'Bruno Equipe')
            ->setRoles($papeis)
            ->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $papel = (new TenantRole())->setTenant($conta)->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid())->setIsSystem($primario);
        $vinculo = (new UserTenant($user, $conta))->setTenantRole($papel);
        $this->em->persist($user);
        $this->em->persist($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    /** @param array<string, string> $campos */
    private function postar(string $acao, array $campos, bool $comToken = true): void
    {
        if ($comToken) {
            $campos['_token'] = 'TOKEN_exclusao_conta';
        }
        $this->client->request('POST', '/perfil/conta/exclusao/' . $acao, $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** Etapa 1 e o código que chegou no e-mail. */
    private function pedirCodigo(string $motivo = 'Achei caro'): string
    {
        $this->postar('codigo', ['motivo' => $motivo, 'observacao' => 'muito caro para nós']);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        self::assertSame(1, preg_match('/>(\d{6})</', (string) self::getMailerMessage()?->getHtmlBody(), $m));

        return $m[1];
    }

    private function pedidoDe(Tenant $conta): ?PedidoDeExclusaoDeConta
    {
        $this->em->clear();
        // A requisição anterior pode ter ligado o filtro de escritório com outra conta (a do Master).
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }

        return $this->em->getRepository(PedidoDeExclusaoDeConta::class)->findOneBy(['tenant' => $conta->getId()], ['id' => 'DESC']);
    }

    /** Pedido pendente direto no banco (para os testes da barreira e do Master). */
    private function suspenderAPedido(Tenant $conta, User $primario): void
    {
        $conta = $this->em->find(Tenant::class, $conta->getId());
        $primario = $this->em->find(User::class, $primario->getId());
        self::assertNotNull($conta);
        $this->em->persist(new PedidoDeExclusaoDeConta($conta, $primario, 'Não uso mais', new \DateTimeImmutable()));
        $this->em->flush();
    }

    // ------------------------------------------------------------------------------------------------
    // Card e etapas
    // ------------------------------------------------------------------------------------------------

    #[TestDox('o card "Excluir conta do escritório" aparece só para o primário, com o texto do desenho e o modal de 2 etapas')]
    public function testCardSoParaOPrimario(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $equipe = $this->pessoa($conta, false);

        $this->logarComTenant($this->client, $primario, $conta);
        $pagina = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $card = $pagina->filter('section.mp-card.mp-ex[data-screen-label="Excluir conta"]');
        self::assertCount(1, $card);
        self::assertSame('Excluir conta do escritório', trim($card->filter('.mp-ex-texto > b')->text()));
        self::assertSame('Suspende a conta para toda a equipe e envia o pedido ao Master do BlueJus, que decide a exclusão. Exclusivo do usuário primário.', trim($card->filter('.mp-ex-sub')->text()));
        self::assertSame('Excluir conta', trim($card->filter('button.mp-ex-abrir')->text()));
        $modal = $pagina->filter('#modalExclusaoConta');
        self::assertSame(['Não uso mais', 'Achei caro', 'Faltou um recurso', 'Mudei de sistema', 'Encerrando o escritório', 'Outro motivo'], $modal->filter('[data-mp-ex-motivo]')->each(static fn ($b): string => trim($b->text())));
        self::assertStringNotContainsString('irreversível', $pagina->text());
        self::assertStringNotContainsString('Protótipo', $modal->text());

        $this->logarComTenant($this->client, $equipe, $conta);
        $pagina = $this->client->request('GET', '/perfil');
        self::assertCount(0, $pagina->filter('.mp-ex'));
        self::assertCount(0, $pagina->filter('#modalExclusaoConta'));
    }

    #[TestDox('etapa 1: sem motivo "Escolha um motivo."; com motivo, o código vai ao e-mail do primário (só o hash fica no banco)')]
    public function testEtapa1(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar('codigo', ['motivo' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha um motivo.', $this->json()['erro']);

        $codigo = $this->pedirCodigo();
        self::assertSame($primario->getEmail(), $this->json()['email']);
        self::assertSame('[BlueJus] Código para excluir a conta', self::getMailerMessage()?->getSubject());
        $gravado = (string) $this->em->getConnection()->fetchOne('SELECT token_hash FROM codigo_exclusao_conta WHERE tenant_id = :t', ['t' => $conta->getId()]);
        self::assertSame(64, strlen($gravado));
        self::assertStringNotContainsString($codigo, $gravado);
    }

    #[TestDox('etapa 2: código errado "Código inválido.", palavra errada "Digite EXCLUIR para confirmar."; certo cria o pedido pendente e avisa o Master no sino')]
    public function testEtapa2(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $master = $this->pessoa($this->conta(), true, ['ROLE_SUPER_ADMIN']);
        $this->logarComTenant($this->client, $primario, $conta);
        $codigo = $this->pedirCodigo();
        $errado = $codigo === '000000' ? '111111' : '000000';

        $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $errado, 'palavra' => 'EXCLUIR']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Código inválido.', $this->json()['erro']);
        $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $codigo, 'palavra' => 'excluí']);
        self::assertSame('Digite EXCLUIR para confirmar.', $this->json()['erro']);
        self::assertNull($this->pedidoDe($conta));

        $this->postar('pedir', ['motivo' => 'Achei caro', 'observacao' => 'muito caro para nós', 'codigo' => $codigo, 'palavra' => ' excluir ']);
        self::assertResponseIsSuccessful();
        self::assertSame('Conta suspensa. O pedido de exclusão foi enviado ao Master.', $this->json()['aviso']);
        $pedido = $this->pedidoDe($conta);
        self::assertNotNull($pedido);
        self::assertSame(EstadoDoPedidoDeExclusao::Pendente, $pedido->getEstado());
        self::assertSame('Achei caro · muito caro para nós', $pedido->getMotivo());
        self::assertSame($primario->getId(), $pedido->getPedidoPor()?->getId());

        // SQL direto: a notificação mora no escritório do Master, que o filtro de escritório da sessão esconde.
        $aviso = $this->em->getConnection()->fetchAssociative(
            'SELECT titulo, mensagem, tenant_id, url FROM notificacao WHERE usuario_id = :u AND tipo = :t',
            ['u' => $master->getId(), 't' => Notificacao::TIPO_CONTA_PEDIDO_EXCLUSAO],
        );
        self::assertNotFalse($aviso, 'o Master recebe no sino');
        self::assertSame('Pedido de exclusão de conta', $aviso['titulo']);
        self::assertSame('/master#sec=contas', $aviso['url'], 'abre o painel na seção Contas');
        self::assertSame($conta->getName() . ' pediu exclusão. Motivo: Achei caro · muito caro para nós', $aviso['mensagem']);
        self::assertNotSame($conta->getId(), (int) $aviso['tenant_id'], 'gravado no escritório do Master, nunca no do cliente');

        // O mesmo código não vale de novo, e um segundo pedido é recusado.
        $this->postar('codigo', ['motivo' => 'Achei caro']);
        self::assertResponseStatusCodeSame(409);
    }

    #[TestDox('5 códigos errados queimam o código: o certo depois disso também é "Código inválido."')]
    public function testCincoErrosQueimam(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);
        $codigo = $this->pedirCodigo();
        $errado = $codigo === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; ++$i) {
            $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $errado, 'palavra' => 'EXCLUIR']);
        }
        $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $codigo, 'palavra' => 'EXCLUIR']);

        self::assertSame('Código inválido.', $this->json()['erro']);
        self::assertNull($this->pedidoDe($conta));
    }

    #[TestDox('guardas: quem não é primário 403; sem CSRF 403')]
    public function testGuardas(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $equipe = $this->pessoa($conta, false);

        $this->logarComTenant($this->client, $equipe, $conta);
        $this->postar('codigo', ['motivo' => 'Achei caro']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Só o usuário primário pode pedir a exclusão da conta.', $this->json()['erro']);

        $this->logarComTenant($this->client, $primario, $conta);
        $this->postar('codigo', ['motivo' => 'Achei caro'], comToken: false);
        self::assertResponseStatusCodeSame(403);

    }

    #[TestDox('limite de envios do código estourado: 429 com a mensagem, sem e-mail nem código gravado')]
    public function testLimiteDeEnvios(): void
    {
        // O 429 real não é observável por HTTP em teste (ArrayAdapter zerado a cada request): o dublê
        // simula a cota gasta, como nos outros limitadores do repo. Trocado antes da 1ª requisição.
        static::getContainer()->set('limiter.exclusao_conta_codigo', new RateLimiterFactoryEspiao(aceita: false));
        $conta = $this->conta();
        $this->logarComTenant($this->client, $this->pessoa($conta, true), $conta);

        $this->postar('codigo', ['motivo' => 'Achei caro']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Muitos envios em sequência. Aguarde alguns minutos e tente de novo.', $this->json()['erro']);
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM codigo_exclusao_conta WHERE tenant_id = :t', ['t' => $conta->getId()]));
    }

    // ------------------------------------------------------------------------------------------------
    // "Suspensa a pedido": barreira (b), sem laço
    // ------------------------------------------------------------------------------------------------

    #[TestDox('equipe: sai do escritório com a mensagem da C13; a seleção mostra o aviso e NÃO entra de novo (sem laço); o POST da seleção é recusado')]
    public function testEquipeBarradaSemLaco(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $equipe = $this->pessoa($conta, false);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $equipe, $conta);

        $this->client->request('GET', '/expediente');
        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($this->client->getRequest()->getSession()->get('current_tenant_id'));

        $pagina = $this->client->followRedirect();
        self::assertResponseIsSuccessful('a seleção não escolhe o escritório barrado: fica na página');
        self::assertStringContainsString(BloqueioPorPedidoDeExclusao::MENSAGEM, $pagina->filter('[data-conta-suspensa-a-pedido]')->text());

        $this->client->request('POST', '/escritorio/selecionar', ['_csrf_token' => 'TOKEN_tenant_selecionar', 'tenant_id' => $conta->getId()]);
        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($this->client->getRequest()->getSession()->get('current_tenant_id'));
    }

    #[TestDox('primário: fica no escritório, só no Meu Perfil (outras rotas voltam ao Meu Perfil; chamada de fundo recebe 403); o card mostra o pedido e "Desistir e reativar"')]
    public function testPrimarioSoNoMeuPerfil(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->client->request('GET', '/expediente', [], [], ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseRedirects('/perfil');
        $this->client->request('GET', '/notificacoes/count', [], [], ['HTTP_ACCEPT' => '*/*']);
        self::assertResponseStatusCodeSame(403, 'polling sem o cabeçalho XHR também recebe a recusa curta');
        $this->client->request('GET', '/expediente', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(403);

        $pagina = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        $card = $pagina->filter('section.mp-ex');
        self::assertStringContainsString('Suspensa · aguardando o Master', $card->text());
        self::assertStringStartsWith('Pedido feito em ', trim($card->filter('.mp-ex-sub')->text()));
        self::assertStringEndsWith('· motivo: Não uso mais. A equipe está sem acesso até o Master concluir.', trim($card->filter('.mp-ex-sub')->text()));
        self::assertSame('Desistir e reativar', trim($card->filter('[data-mp-ex-desistir]')->text()));
        self::assertCount(0, $pagina->filter('#modalExclusaoConta'));
    }

    #[TestDox('desistir: outro primário desiste, o pedido vira "desistida" e a equipe volta a entrar')]
    public function testDesistir(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $socio = $this->pessoa($conta, true);
        $equipe = $this->pessoa($conta, false);
        $this->suspenderAPedido($conta, $primario);

        $this->logarComTenant($this->client, $socio, $conta);
        $this->postar('desistir', []);
        self::assertResponseIsSuccessful();
        self::assertSame('Conta reativada. Que bom que você ficou!', $this->json()['aviso']);
        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Desistida, $pedido?->getEstado());
        self::assertSame($socio->getId(), $pedido?->getDecididoPor()?->getId());

        $this->logarComTenant($this->client, $equipe, $conta);
        $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful('a equipe passa pela barreira de novo');
        self::assertSame($conta->getId(), $this->client->getRequest()->getSession()->get('current_tenant_id'));
    }

    // ------------------------------------------------------------------------------------------------
    // Decisão do Master (contrato com a b5)
    // ------------------------------------------------------------------------------------------------

    #[TestDox('Master reativa a conta "suspensa a pedido" (ativa): só decide o pedido ("reativada"); sem pedido, conta ativa continua recusada')]
    public function testMasterReativa(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $master = $this->pessoa($this->conta(), true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);

        static::getContainer()->get(ReativarContaUseCase::class)->executar(new SituacaoDaContaInput($master, (int) $conta->getId()));

        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Reativada, $pedido?->getEstado());
        self::assertTrue($pedido?->getTenant()->isActive());

        $this->expectExceptionMessage('Esta conta já está ativa.');
        static::getContainer()->get(ReativarContaUseCase::class)->executar(new SituacaoDaContaInput($this->em->find(User::class, $master->getId()), (int) $conta->getId()));
    }

    #[TestDox('Master exclui: a conta entra em quarentena e o pedido vira "excluida"; recuperar depois não toca o pedido')]
    public function testMasterExclui(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $master = $this->pessoa($this->conta(), true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);

        static::getContainer()->get(ExcluirContaPeloMasterUseCase::class)->executar(new ExcluirContaInput($master, (int) $conta->getId(), 'EXCLUIR'));
        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Excluida, $pedido?->getEstado());
        self::assertNotNull($pedido?->getTenant()->getExcluidoEm());

        static::getContainer()->get(ReativarContaUseCase::class)->executar(new SituacaoDaContaInput($this->em->find(User::class, $master->getId()), (int) $conta->getId()));
        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Excluida, $pedido?->getEstado(), 'recuperada = "excluida" com o escritório ativo');
        self::assertTrue($pedido?->getTenant()->isActive());
    }

    #[TestDox('Master pelo PAINEL (HTTP), com o escritório dele na sessão: reativar decide o pedido do cliente (o filtro de escritório não esconde o pedido)')]
    public function testMasterReativaPeloPainel(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $contaDoMaster = $this->conta();
        $master = $this->pessoa($contaDoMaster, true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $master, $contaDoMaster);

        $this->client->request('POST', '/master/contas/' . $conta->getId() . '/reativar', ['_token' => 'TOKEN_master_conta_' . $conta->getId()], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        self::assertSame(EstadoDoPedidoDeExclusao::Reativada, $this->pedidoDe($conta)?->getEstado());
    }

    #[TestDox('Master pelo PAINEL (HTTP), com o escritório dele na sessão: excluir põe em quarentena e decide o pedido ("excluida")')]
    public function testMasterExcluiPeloPainel(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $contaDoMaster = $this->conta();
        $master = $this->pessoa($contaDoMaster, true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $master, $contaDoMaster);

        $this->client->request('POST', '/master/contas/' . $conta->getId() . '/excluir', ['_token' => 'TOKEN_master_conta_' . $conta->getId(), 'confirmacao' => 'EXCLUIR'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Excluida, $pedido?->getEstado());
        self::assertNotNull($pedido?->getTenant()->getExcluidoEm());
    }

    #[TestDox('conta do Master (criada por um Master): sem card e o servidor recusa (403)')]
    public function testContaDoMaster(): void
    {
        $conta = $this->conta();
        $master = $this->pessoa($conta, true, ['ROLE_SUPER_ADMIN']);
        $conta = $this->em->find(Tenant::class, $conta->getId());
        $conta?->setCriadoPor($this->em->find(User::class, $master->getId()));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $conta);

        $pagina = $this->client->request('GET', '/perfil');
        self::assertCount(0, $pagina->filter('.mp-ex'));
        $this->postar('codigo', ['motivo' => 'Achei caro']);
        self::assertResponseStatusCodeSame(403);
        self::assertEmailCount(0);
    }

    #[TestDox('código vencido (mais de 15 minutos): "Código inválido." e nenhum pedido')]
    public function testCodigoVencido(): void
    {
        $conta = $this->conta();
        $this->logarComTenant($this->client, $this->pessoa($conta, true), $conta);
        $codigo = $this->pedirCodigo();
        $this->em->getConnection()->executeStatement("UPDATE codigo_exclusao_conta SET expira_em = criado_em - interval '1 minute' WHERE tenant_id = :t", ['t' => $conta->getId()]);

        $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $codigo, 'palavra' => 'EXCLUIR']);

        self::assertSame('Código inválido.', $this->json()['erro']);
        self::assertNull($this->pedidoDe($conta));
    }

    #[TestDox('e-mail que não sai: 503 com a mensagem e nenhum código fica gravado')]
    public function testFalhaDeEmail(): void
    {
        static::getContainer()->set(\App\Tenant\Service\CodigoDeExclusaoMailerInterface::class, new class () implements \App\Tenant\Service\CodigoDeExclusaoMailerInterface {
            public function enviar(User $usuario, string $escritorio, string $codigo): void
            {
                throw new \RuntimeException('transporte fora');
            }
        });
        $conta = $this->conta();
        $this->logarComTenant($this->client, $this->pessoa($conta, true), $conta);

        $this->postar('codigo', ['motivo' => 'Achei caro']);

        self::assertResponseStatusCodeSame(503);
        self::assertSame('Não foi possível enviar o código. Tente de novo em instantes.', $this->json()['erro']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM codigo_exclusao_conta WHERE tenant_id = :t', ['t' => $conta->getId()]));
    }

    #[TestDox('desistir com a conta fora do ar (suspensa pelo Master depois do pedido): recusado, só o Master decide')]
    public function testDesistirComContaForaDoAr(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->suspenderAPedido($conta, $primario);
        $suspensa = $this->em->find(Tenant::class, $conta->getId());
        $suspensa?->setIsActive(false);
        $this->em->flush();

        $usuario = $this->em->find(User::class, $primario->getId());
        self::assertNotNull($usuario);
        self::assertNotNull($suspensa);
        try {
            static::getContainer()->get(\App\Tenant\UseCase\DesistirDaExclusaoUseCase::class)->executar($usuario, $suspensa);
            self::fail('conta fora do ar não aceita desistir');
        } catch (\App\Tenant\Exception\PedidoDeExclusaoRecusado $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(EstadoDoPedidoDeExclusao::Pendente, $this->pedidoDe($conta)?->getEstado());
    }

    #[TestDox('rota antiga do Master (app_tenant_delete), com o escritório dele na sessão: a exclusão decide o pedido do cliente ("excluida")')]
    public function testRotaAntigaDoMasterDecideOPedido(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $contaDoMaster = $this->conta();
        $master = $this->pessoa($contaDoMaster, true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $master, $contaDoMaster);

        $this->client->request('POST', '/tenant/' . $conta->getId(), ['_token' => 'TOKEN_delete' . $conta->getId()]);

        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Excluida, $pedido?->getEstado());
        self::assertSame($master->getId(), $pedido?->getDecididoPor()?->getId());
        self::assertNotNull($pedido?->getTenant()->getExcluidoEm());
    }

    #[TestDox('o aviso ao Master não deixa rastro na trilha do cliente: o audit_log da notificação fica na conta do Master')]
    public function testAuditoriaDoAvisoNaContaDoMaster(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $contaDoMaster = $this->conta();
        $master = $this->pessoa($contaDoMaster, true, ['ROLE_SUPER_ADMIN']);
        $this->logarComTenant($this->client, $primario, $conta);
        $codigo = $this->pedirCodigo();

        $this->postar('pedir', ['motivo' => 'Achei caro', 'codigo' => $codigo, 'palavra' => 'EXCLUIR']);
        self::assertResponseIsSuccessful();

        // Na inserção o id ainda não existe (entity_id nulo): a prova é pelo escritório da linha de auditoria.
        $contar = fn (Tenant $t): int => (int) $this->em->getConnection()->fetchOne(
            "SELECT count(*) FROM audit_log WHERE entity_class = :c AND action = 'create' AND tenant_id = :t",
            ['c' => Notificacao::class, 't' => $t->getId()],
        );
        self::assertSame(0, $contar($conta), 'nada da notificação do Master na trilha do cliente');
        self::assertGreaterThanOrEqual(1, $contar($contaDoMaster), 'a criação da notificação fica na conta do Master');
    }

    // ------------------------------------------------------------------------------------------------
    // Revisão cruzada da b5 (itens 2, 4 e 5)
    // ------------------------------------------------------------------------------------------------

    #[TestDox('rota antiga escritorio_excluir com pedido pendente: quem não é Master é recusado, a conta segue ativa e o pedido pendente')]
    public function testRotaAntigaRecusaQuemNaoEMaster(): void
    {
        $conta = $this->conta();
        $outra = $this->conta();
        $primario = $this->pessoa($conta, true);
        $socio = $this->pessoa($conta, true);
        $this->em->persist(new UserTenant($this->em->find(User::class, $socio->getId()), $this->em->find(Tenant::class, $outra->getId())));
        $this->em->flush();
        $this->suspenderAPedido($conta, $primario);

        // Com o escritório B na sessão, a barreira do pedido (que olha a sessão) não segura a rota de A.
        $this->logarComTenant($this->client, $socio, $outra);
        $this->client->request('POST', '/escritorio/' . $conta->getId() . '/excluir', ['_token' => 'TOKEN_excluir_' . $conta->getId(), 'confirmar_nome' => $conta->getName()]);

        self::assertResponseRedirects('/tenant/' . $conta->getId());
        self::assertContains(
            'Esta conta tem um pedido de exclusão aguardando a decisão do Master. Só o Master pode excluí-la agora.',
            $this->client->getRequest()->getSession()->getFlashBag()->peek('danger'),
        );
        $pedido = $this->pedidoDe($conta);
        self::assertSame(EstadoDoPedidoDeExclusao::Pendente, $pedido?->getEstado());
        self::assertTrue($pedido?->getTenant()->isActive());
        self::assertNull($pedido?->getTenant()->getExcluidoEm());
    }

    #[TestDox('decisão concorrente: o pedido lido como pendente e decidido por outro antes do COMMIT devolve 409 e não grava a conta')]
    public function testDecisaoConcorrenteDevolve409(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $master = $this->pessoa($this->conta(), true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);
        $pedidos = static::getContainer()->get(PedidoDeExclusaoDeContaRepository::class);
        $this->em->clear();
        $pedido = $pedidos->pendenteDoEscritorio($this->em->find(Tenant::class, $conta->getId()));
        self::assertNotNull($pedido);

        // O primário desistiu entre a leitura do Master e a gravação dele.
        $this->em->getConnection()->executeStatement("UPDATE pedido_exclusao_conta SET estado = 'desistida' WHERE id = :id", ['id' => $pedido->getId()]);

        $pedido->marcarExcluida($this->em->find(User::class, $master->getId()));
        $gravou = false;
        try {
            $pedidos->gravarDecisaoSePendente($pedido, function () use (&$gravou): void {
                $gravou = true;
            });
            self::fail('a segunda decisão deveria ser recusada');
        } catch (PedidoDeExclusaoRecusado $e) {
            self::assertSame(409, $e->status);
        }

        self::assertFalse($gravou, 'quem perde não grava a conta');
        self::assertSame(EstadoDoPedidoDeExclusao::Desistida, $this->pedidoDe($conta)?->getEstado(), 'vale a primeira decisão');
    }

    #[TestDox('desistir depois que o Master já decidiu: 409, e a decisão do Master fica')]
    public function testDesistirDepoisDoMasterDevolve409(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->suspenderAPedido($conta, $primario);
        $pedido = $this->pedidoDe($conta);
        self::assertNotNull($pedido);
        $this->logarComTenant($this->client, $primario, $conta);

        // A decisão do Master chega por outro processo entre a leitura e a gravação do primário: simulada
        // trocando o estado no banco, sem o pedido sair de "pendente" para a leitura (a mesma linha lida antes).
        $pedidos = static::getContainer()->get(PedidoDeExclusaoDeContaRepository::class);
        $this->em->clear();
        $lido = $pedidos->pendenteDoEscritorio($this->em->find(Tenant::class, $conta->getId()));
        self::assertNotNull($lido);
        $this->em->getConnection()->executeStatement("UPDATE pedido_exclusao_conta SET estado = 'reativada' WHERE id = :id", ['id' => $lido->getId()]);
        $lido->desistir($this->em->find(User::class, $primario->getId()));

        try {
            $pedidos->gravarDecisaoSePendente($lido, fn () => $pedidos->salvar($lido, flush: true));
            self::fail('a desistência depois da decisão do Master deveria ser recusada');
        } catch (PedidoDeExclusaoRecusado $e) {
            self::assertSame(409, $e->status);
        }

        self::assertSame(EstadoDoPedidoDeExclusao::Reativada, $this->pedidoDe($conta)?->getEstado());
    }

    #[TestDox('auditoria da rota antiga: a linha da conta excluída cai no escritório excluído, não no escritório da sessão do Master')]
    public function testAuditoriaDaRotaAntigaNaContaExcluida(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $contaDoMaster = $this->conta();
        $master = $this->pessoa($contaDoMaster, true, ['ROLE_SUPER_ADMIN']);
        $this->suspenderAPedido($conta, $primario);
        $this->logarComTenant($this->client, $master, $contaDoMaster);

        $this->client->request('POST', '/tenant/' . $conta->getId(), ['_token' => 'TOKEN_delete' . $conta->getId()]);

        $linhas = $this->em->getConnection()->fetchAllAssociative(
            "SELECT tenant_id FROM audit_log WHERE entity_class = :c AND entity_id = :id AND action = 'update'",
            ['c' => Tenant::class, 'id' => (string) $conta->getId()],
        );
        self::assertNotSame([], $linhas, 'a exclusão da conta é auditada');
        self::assertSame([(int) $conta->getId()], array_values(array_unique(array_map(static fn (array $l): int => (int) $l['tenant_id'], $linhas))));
    }
}
