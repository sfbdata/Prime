<?php

declare(strict_types=1);

namespace App\Tests\Backup\Functional;

use App\Backup\Armazenamento\ChavesDeBackup;
use App\Backup\Controller\BackupController;
use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\UseCase\BaixarBackupUseCase;
use App\Backup\UseCase\ListarPedidosDeBackupUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoTemporarioPossuido;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoIndisponivelNoMomento;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Http\EntregaDeArquivo;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** A seção "Backup e Exportação de Dados" do Meu Perfil: pedir, listar e baixar (spec backup-e-exportacao-de-dados, seções 2, 6 e 8). */
#[CoversClass(BackupController::class)]
#[CoversClass(BaixarBackupUseCase::class)]
#[CoversClass(ListarPedidosDeBackupUseCase::class)]
final class BackupControllerTest extends JusPrimeWebTestCase
{
    private const SHA = 'a3f1c2d4e5b60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ArmazenamentoDeArquivos $armazenamento;
    /** @var list<PedidoDeBackup> */
    private array $comArquivo = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->armazenamento = $c->get(ArmazenamentoDeArquivos::class);
        $c->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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

    protected function tearDown(): void
    {
        foreach ($this->comArquivo as $pedido) {
            try {
                $this->armazenamento->excluir(ChavesDeBackup::zip($pedido));
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function escritorio(): Tenant
    {
        $t = (new Tenant())->setName('Backup tela ' . uniqid())->setIsActive(true);
        $this->em->persist($t);
        $this->em->flush();

        return $t;
    }

    private function pessoa(Tenant $tenant): User
    {
        $u = (new User())->setEmail('bk_' . uniqid() . '@test.com')->setFullName('Pessoa Backup')->setRoles(['ROLE_USER'])->setIsActive(true);
        $u->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($u, 'senha123'));
        $tenant = $this->em->find(Tenant::class, $tenant->getId());
        $papel = (new TenantRole())->setTenant($tenant)->setName('Papel ' . uniqid())->setIsSystem(false);
        $this->em->persist($u);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($u, $tenant))->setTenantRole($papel));
        $this->em->flush();

        return $u;
    }

    private function entrar(User $u, Tenant $t): void
    {
        $this->logarComTenant($this->client, $u, $t);
    }

    private function pedido(Tenant $t, User $u, string $situacao = 'pronto', string $expira = '+7 days', bool $comArquivo = true): PedidoDeBackup
    {
        $agora = new \DateTimeImmutable();
        $p = new PedidoDeBackup($t, $u, $agora->modify('-1 hour'));
        $this->em->persist($p);
        $this->em->flush();
        if ('pedido' === $situacao) {
            return $p;
        }
        $p->iniciarMontagem($agora->modify('-50 minutes'));
        if ('falhou' === $situacao) {
            $p->falhar('x', $agora);
            $this->em->flush();

            return $p;
        }
        $gravado = $this->armazenamento->gravar(ChavesDeBackup::novoZip($p), FonteDeConteudo::deTexto('PK-conteudo-do-zip'));
        // A validade no passado exige datas coerentes: conclui há 2 dias e expira como pedido.
        $p->concluir(new ResultadoDoBackup($gravado->chave->nome, 18, self::SHA, 1, 0), $agora->modify('-2 days'), $agora->modify($expira));
        $this->em->flush();
        $this->comArquivo[] = $p;
        if (!$comArquivo) {
            $this->armazenamento->excluir($gravado->chave);
        }

        return $p;
    }

    private function postarPedido(?string $token = 'TOKEN_backup_pedir'): void
    {
        $this->client->request('POST', '/perfil/backup/pedir', null === $token ? [] : ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    #[TestDox('Gerar backup: cria o pedido na fila, despacha GerarBackup e responde ok')]
    public function testGerarFeliz(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->entrar($u, $t);

        $this->postarPedido();

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ok']);
        $n = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pedido_de_backup WHERE tenant_id = ? AND usuario_id = ?', [$t->getId(), $u->getId()]);
        self::assertSame(1, $n);
        /** @var InMemoryTransport $async */
        $async = static::getContainer()->get('messenger.transport.async');
        self::assertCount(1, $async->getSent());
    }

    #[TestDox('Gerar backup: já com pedido aberto, 409 com a mensagem e nenhum pedido novo')]
    public function testRecusaComPedidoAberto(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->pedido($t, $u, 'pedido');
        $this->entrar($u, $t);

        $this->postarPedido();

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('sendo preparado', (string) $this->json()['erro']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pedido_de_backup WHERE tenant_id = ?', [$t->getId()]));
    }

    #[TestDox('Gerar backup: já pediu nas últimas 24 horas, 409 com a mensagem do limite')]
    public function testRecusaPeloLimiteDoDia(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->pedido($t, $u, 'pronto');
        $this->entrar($u, $t);

        $this->postarPedido();

        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('24 horas', (string) $this->json()['erro']);
    }

    #[TestDox('Gerar backup: sem token ou com token errado, 403 e nenhum pedido')]
    public function testSemCsrf(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->entrar($u, $t);

        $this->postarPedido(null);
        self::assertResponseStatusCodeSame(403);
        $this->postarPedido('TOKEN_outro');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pedido_de_backup WHERE tenant_id = ?', [$t->getId()]));
    }

    #[TestDox('Gerar backup: o freio de ritmo por usuário estourado responde 429 sem criar pedido (a chave é o id da pessoa)')]
    public function testFreioDeRitmo(): void
    {
        // O 429 real não é observável por HTTP em teste (ArrayAdapter zerado a cada request): o dublê simula a cota estourada.
        $espiao = new RateLimiterFactoryEspiao(aceita: false);
        static::getContainer()->set('limiter.backup_pedido', $espiao);
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->entrar($u, $t);

        $this->postarPedido();

        self::assertResponseStatusCodeSame(429);
        self::assertSame([(string) $u->getId()], $espiao->chavesConsumidas);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM pedido_de_backup WHERE tenant_id = ?', [$t->getId()]));
    }

    #[TestDox('Sem login: pedir e baixar redirecionam para o login')]
    public function testSemLogin(): void
    {
        $this->client->request('POST', '/perfil/backup/pedir');
        self::assertResponseRedirects();
        $this->client->request('GET', '/perfil/backup/1/baixar');
        self::assertResponseRedirects();
    }

    #[TestDox('A seção está no fim do Meu Perfil, filha direta do contêiner, depois da de exclusão quando ela existe')]
    public function testSecaoNoFimDoPerfil(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->entrar($u, $t);

        $tela = $this->client->request('GET', '/perfil');

        self::assertResponseIsSuccessful();
        $secoes = $tela->filter('section#mpBackup');
        self::assertCount(1, $secoes);
        self::assertSame('Backup e Exportação de Dados', trim($secoes->filter('.mp-card-topo > div > b')->text()));
        $irmas = $secoes->getNode(0)->parentNode->childNodes;
        $ultima = null;
        $posExclusao = null;
        foreach ($irmas as $i => $no) {
            if ($no instanceof \DOMElement) {
                $ultima = $no;
                if ('mpExclusao' === $no->getAttribute('id')) {
                    $posExclusao = $i;
                }
            }
        }
        self::assertSame('mpBackup', $ultima?->getAttribute('id'));
        if (null !== $posExclusao) {
            self::assertGreaterThan($posExclusao, array_search($secoes->getNode(0), iterator_to_array($irmas), true));
        }
        self::assertSame('/perfil/backup/pedir', $secoes->attr('data-url-pedir'));
    }

    #[TestDox('A seção aparece para quem não é primário e lista só os pedidos da pessoa no escritório atual')]
    public function testListaSoDaPessoaNoEscritorioAtual(): void
    {
        $t1 = $this->escritorio();
        $t2 = $this->escritorio();
        $eu = $this->pessoa($t1);
        $colega = $this->pessoa($t1);
        $this->pedido($t1, $eu, 'falhou');
        $this->pedido($t1, $colega, 'falhou');
        $this->pedido($t2, $eu, 'falhou');
        $this->entrar($eu, $t1);

        $tela = $this->client->request('GET', '/perfil');

        self::assertCount(1, $tela->filter('section#mpBackup > ul.mp-bk-lista > li.mp-bk-item'));
        self::assertSame('Falhou', trim($tela->filter('section#mpBackup .mp-bk-item > .mp-bk-sit')->text()));
    }

    #[TestDox('Pronto na validade mostra Baixar e a data; vencido pela data mostra Vencido sem link')]
    public function testListaProntoEVencido(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $pronto = $this->pedido($t, $u, 'pronto');
        $this->entrar($u, $t);

        $tela = $this->client->request('GET', '/perfil');
        $link = $tela->filter('section#mpBackup .mp-bk-item > a.mp-bk-baixar');
        self::assertCount(1, $link);
        self::assertSame('/perfil/backup/' . $pronto->getId() . '/baixar', $link->attr('href'));
        self::assertStringContainsString('Disponível até', $tela->filter('section#mpBackup .mp-bk-validade')->text());

        $t2 = $this->escritorio();
        $u2 = $this->pessoa($t2);
        $this->pedido($t2, $u2, 'pronto', '-1 minute');
        $this->entrar($u2, $t2);
        $tela = $this->client->request('GET', '/perfil');
        self::assertSame('Vencido', trim($tela->filter('section#mpBackup .mp-bk-sit')->text()));
        self::assertCount(0, $tela->filter('section#mpBackup a.mp-bk-baixar'));
    }

    #[TestDox('Download do dono: entrega o ZIP com o nome backup-bluejus-AAAA-MM-DD.zip e grava backup_dl no audit_log')]
    public function testDownloadDoDono(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $p = $this->pedido($t, $u);
        $this->entrar($u, $t);

        $this->client->request('GET', '/perfil/backup/' . $p->getId() . '/baixar');

        self::assertResponseIsSuccessful();
        $cabecalho = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $cabecalho);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertMatchesRegularExpression('/backup-bluejus-\d{4}-\d{2}-\d{2}\.zip/', $cabecalho);
        $log = $this->em->getConnection()->fetchAssociative('SELECT action, tenant_id, actor_user_id, entity_id FROM audit_log WHERE action = ? AND entity_id = ?', ['backup_dl', (string) $p->getId()]);
        self::assertIsArray($log);
        self::assertSame((int) $t->getId(), (int) $log['tenant_id']);
        self::assertSame((int) $u->getId(), (int) $log['actor_user_id']);
    }

    #[TestDox('Download: se a entrega falha (armazenamento indisponível), nada é auditado')]
    public function testEntregaQueLancaNaoAudita(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $p = $this->pedido($t, $u);
        $this->entrar($u, $t);
        $this->client->catchExceptions(false);
        $falho = new class () implements MaterializadorDeArquivo {
            public function paraLeitura(ChaveDeArquivo $chave): ArquivoEmprestado
            {
                throw ArquivoIndisponivelNoMomento::para($chave);
            }

            public function copiaGravavel(ChaveDeArquivo $chave): ArquivoTemporarioPossuido
            {
                throw ArquivoIndisponivelNoMomento::para($chave);
            }
        };
        static::getContainer()->set(EntregaDeArquivo::class, new EntregaDeArquivo($falho));

        $lancou = false;
        try {
            $this->client->request('GET', '/perfil/backup/' . $p->getId() . '/baixar');
        } catch (ServiceUnavailableHttpException) {
            $lancou = true;
        }
        self::assertTrue($lancou, 'A entrega deveria ter falhado.');

        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['backup_dl']));
    }

    #[TestDox('Recusas do pedir saem pelas chaves de tradução, e pt_BR e en têm todas')]
    public function testRecusasTraduzidas(): void
    {
        $t = $this->escritorio();
        $u = $this->pessoa($t);
        $this->pedido($t, $u, 'pedido');
        $this->entrar($u, $t);
        $this->postarPedido();
        self::assertSame('Você já tem um backup sendo preparado. Aguarde ele ficar pronto.', $this->json()['erro']);

        $tradutor = static::getContainer()->get('translator');
        foreach (['pt_BR', 'en'] as $locale) {
            foreach (['ja_tem_aberto', 'limite_do_dia', 'muitos_pedidos', 'token_invalido', 'selecione_escritorio', 'pedido_recebido'] as $chave) {
                $id = 'perfil.backup.recusa.' . $chave;
                self::assertNotSame($id, $tradutor->trans($id, [], null, $locale), "$locale $id");
            }
        }
    }

    #[TestDox('Download: outro usuário do escritório, outro escritório, vencido, não pronto e arquivo sumido dão 404 e nenhuma auditoria')]
    public function testDownloadNegado(): void
    {
        $t = $this->escritorio();
        $dono = $this->pessoa($t);
        $colega = $this->pessoa($t);
        $outro = $this->escritorio();
        $forasteiro = $this->pessoa($outro);
        $pronto = $this->pedido($t, $dono);
        $vencido = $this->pedido($t, $dono, 'pronto', '-1 minute');
        $naoPronto = $this->pedido($t, $dono, 'pedido');
        $semArquivo = $this->pedido($t, $dono, 'pronto', '+7 days', false);

        $this->entrar($colega, $t);
        $this->client->request('GET', '/perfil/backup/' . $pronto->getId() . '/baixar');
        self::assertResponseStatusCodeSame(404);

        $this->entrar($forasteiro, $outro);
        $this->client->request('GET', '/perfil/backup/' . $pronto->getId() . '/baixar');
        self::assertResponseStatusCodeSame(404);

        // O dono logado, mas com a sessão no escritório errado, também não baixa.
        $donoNoOutro = $this->pessoa($outro);
        $this->entrar($donoNoOutro, $outro);
        $this->client->request('GET', '/perfil/backup/' . $pronto->getId() . '/baixar');
        self::assertResponseStatusCodeSame(404);

        $this->entrar($dono, $t);
        foreach ([$vencido, $naoPronto, $semArquivo] as $p) {
            $this->client->request('GET', '/perfil/backup/' . $p->getId() . '/baixar');
            self::assertResponseStatusCodeSame(404);
        }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ?', ['backup_dl']));
        self::assertSame(SituacaoDoBackup::Pronto, $pronto->getSituacao());
    }
}
