<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\VotoDoMasterController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Repository\LiberacaoDoVotoRepository;
use App\Voto\UseCase\AlterarLiberacaoDoVotoUseCase;
use App\Voto\UseCase\ObterLiberacoesDoVotoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Aba "Voto" do Painel do Master (docs/specs/bluejus-voto.md, D5 e D7, fase A1b): a grade dos escritórios ativos e a
 * liberação de cada um. Só o Master.
 *
 * O armazém de CSRF é fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): o 403 de quem não é Master vem
 * da regra, não do token. O CSRF errado tem teste próprio.
 */
#[CoversClass(VotoDoMasterController::class)]
#[CoversClass(AlterarLiberacaoDoVotoUseCase::class)]
#[CoversClass(ObterLiberacoesDoVotoUseCase::class)]
#[CoversClass(LiberacaoDoVotoRepository::class)]
final class VotoDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . VotoDoMasterController::TOKEN;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
    }

    #[TestDox('Master lê a grade: só os escritórios ativos, com o nome e se o Voto está liberado (sem linha = não liberado)')]
    public function testMasterLe(): void
    {
        $this->entrarComoMaster();
        $semLinha = $this->escritorio('Escritório sem linha ' . uniqid());
        $liberado = $this->escritorio('Escritório liberado ' . uniqid());
        $this->liberacaoGravada($liberado, true);
        $suspenso = $this->escritorio('Escritório suspenso ' . uniqid());
        $suspenso->setIsActive(false);
        $excluido = $this->escritorio('Escritório excluído ' . uniqid());
        $excluido->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('GET', '/master/voto', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $d = $this->json();
        self::assertSame(['escritorios'], array_keys($d));
        self::assertSame(['tenantId' => $semLinha->getId(), 'nome' => $semLinha->getName(), 'liberado' => false], $this->linhaDe($d, $semLinha));
        self::assertSame(['tenantId' => $liberado->getId(), 'nome' => $liberado->getName(), 'liberado' => true], $this->linhaDe($d, $liberado));
        self::assertNull($this->linhaDe($d, $suspenso, false), 'escritório suspenso não entra na grade');
        self::assertNull($this->linhaDe($d, $excluido, false), 'escritório excluído não entra na grade');
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'GET dados' => ['GET', '/master/voto'];
        yield 'POST escritório' => ['POST', '/master/voto/escritorios/1'];
    }

    #[DataProvider('rotas')]
    #[TestDox('quem não é Master (primário de escritório) recebe 403 em $metodo $url, e nada muda')]
    public function testNaoMaster403(string $metodo, string $url): void
    {
        $tenant = $this->entrarComoPrimarioDeEscritorio();

        if ($metodo === 'GET') {
            $this->client->request('GET', $url, server: ['HTTP_ACCEPT' => 'application/json']);
        } else {
            $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => true]);
        }

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->liberacaoDo($tenant), 'o primário do escritório não libera o Voto para si');
    }

    #[TestDox('sem login: não chega ao JSON')]
    public function testSemLogin(): void
    {
        $this->client->request('GET', '/master/voto');

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertTrue($this->client->getResponse()->isRedirect(), 'vai para o login');
    }

    #[TestDox('sem login: o POST não grava nada')]
    public function testPostSemLogin(): void
    {
        $tenant = $this->escritorio('Escritório sem login ' . uniqid());

        $this->client->request('POST', '/master/voto/escritorios/' . $tenant->getId(), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: (string) json_encode(['liberado' => true]));

        self::assertFalse($this->client->getResponse()->isSuccessful());
        self::assertSame(0, $this->linhasDo($tenant));
    }

    #[TestDox('CSRF errado ou ausente: 403 com a mensagem e nada é gravado')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório CSRF ' . uniqid());

        foreach (['errado', ''] as $token) {
            $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => true], $token);
            self::assertResponseStatusCodeSame(403);
            self::assertSame('Token CSRF inválido. Recarregue a página.', $this->json()['erro']);
        }
        self::assertNull($this->liberacaoDo($tenant));
    }

    #[TestDox('liberar e bloquear: a linha nasce no primeiro clique, a resposta traz a grade nova e o segundo clique grava a mesma linha')]
    public function testLiberarEBloquear(): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório do Voto ' . uniqid());

        $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->linhaDe($this->json(), $tenant)['liberado']);
        self::assertTrue($this->liberacaoDo($tenant)?->isLiberado());

        $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->linhaDe($this->json(), $tenant)['liberado']);
        self::assertFalse($this->liberacaoDo($tenant)?->isLiberado());
        self::assertSame(1, $this->linhasDo($tenant), 'uma linha só por escritório');
    }

    #[TestDox('escritório de outro tenant que não o da sessão do Master: o Master alcança (visão de plataforma), mesmo com o filtro de tenant ligado')]
    public function testEscritorioDeOutroTenantComFiltroLigado(): void
    {
        $tenantDoMaster = $this->escritorio('Escritório do Master ' . uniqid());
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->em->persist(new UserTenant($master, $tenantDoMaster));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $tenantDoMaster);
        $outro = $this->escritorio('Outro escritório ' . uniqid());
        $this->liberacaoGravada($outro, false);

        $this->postar('/master/voto/escritorios/' . $outro->getId(), ['liberado' => true]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->linhaDe($this->json(), $outro)['liberado']);
        self::assertSame(1, $this->linhasDo($outro), 'atualiza a linha que existe; não cria outra (a única por tenant)');
        self::assertTrue($this->liberacaoDo($outro)?->isLiberado());
        self::assertNull($this->liberacaoDo($tenantDoMaster), 'o escritório da sessão do Master não é tocado');
    }

    /** @return iterable<string, array{mixed}> */
    public static function corposInvalidos(): iterable
    {
        yield 'texto' => [['liberado' => 'sim']];
        yield 'número' => [['liberado' => 1]];
        yield 'nulo' => [['liberado' => null]];
        yield 'ausente' => [[]];
        yield 'nome antigo da I.A.' => [['liberada' => true]];
        yield 'corpo que não é JSON' => ['liberado=true'];
    }

    #[DataProvider('corposInvalidos')]
    #[TestDox('corpo inválido ($_dataName): 422 com a mensagem e nada é gravado')]
    public function testCorpoInvalido(mixed $corpo): void
    {
        $this->entrarComoMaster();
        $tenant = $this->escritorio('Escritório inválido ' . uniqid());

        $this->postar('/master/voto/escritorios/' . $tenant->getId(), $corpo);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe se o escritório fica liberado ou bloqueado.', $this->json()['erro']);
        self::assertNull($this->liberacaoDo($tenant));
    }

    #[TestDox('escritório inexistente, suspenso ou excluído: 404 e nada é gravado')]
    public function testEscritorioInexistente(): void
    {
        $this->entrarComoMaster();
        $suspenso = $this->escritorio('Suspenso ' . uniqid());
        $suspenso->setIsActive(false);
        $excluido = $this->escritorio('Excluído ' . uniqid());
        $excluido->setExcluidoEm(new \DateTimeImmutable('-1 day'));
        $this->em->flush();

        foreach ([999999999, (int) $suspenso->getId(), (int) $excluido->getId()] as $id) {
            $this->postar('/master/voto/escritorios/' . $id, ['liberado' => true]);
            self::assertResponseStatusCodeSame(404);
            self::assertSame('Escritório não encontrado.', $this->json()['erro']);
        }
        self::assertNull($this->liberacaoDo($suspenso));
        self::assertNull($this->liberacaoDo($excluido));
    }

    #[TestDox('auditoria: liberar e bloquear entram no audit_log do escritório alterado, nunca no da sessão do Master')]
    public function testAuditoriaNoEscritorioAlterado(): void
    {
        $tenantDoMaster = $this->escritorio('Escritório do Master auditado ' . uniqid());
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->em->persist(new UserTenant($master, $tenantDoMaster));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $tenantDoMaster);
        $tenant = $this->escritorio('Escritório auditado ' . uniqid());

        $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => true]);
        self::assertResponseIsSuccessful();
        $this->postar('/master/voto/escritorios/' . $tenant->getId(), ['liberado' => false]);
        self::assertResponseIsSuccessful();

        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT tenant_id, action FROM audit_log WHERE entity_class = ? AND actor_user_id = ? ORDER BY id',
            [LiberacaoDoVoto::class, $master->getId()],
        );
        self::assertCount(2, $linhas, 'uma linha ao liberar (criação) e outra ao bloquear');
        self::assertSame([(int) $tenant->getId()], array_values(array_unique(array_map(static fn (array $l): int => (int) $l['tenant_id'], $linhas))));
    }

    #[TestDox('tela: a aba "Voto" é a quinta do painel, depois da I.A.; o parcial é filho direto do painel da aba, com a nota e a grade Escritório | Liberado')]
    public function testAbaNoPainel(): void
    {
        $this->entrarComoMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $abas = $crawler->filter('div.mp-abas > button.mp-aba');
        self::assertCount(5, $abas);
        self::assertSame('voto', $abas->eq(4)->attr('data-aba'));
        self::assertSame('Voto', trim($abas->eq(4)->text()));
        self::assertCount(1, $abas->eq(4)->filter('i.bi.bi-archive'));
        self::assertCount(1, $crawler->filter('div.mp-abas > button.mp-aba[data-aba="ia"] + button.mp-aba[data-aba="voto"]'), 'a quinta aba, depois da I.A.');

        $painel = $crawler->filter('#master-painel > div.mp-voto[data-painel-aba="voto"]');
        self::assertCount(1, $painel);
        self::assertNotNull($painel->attr('hidden'), 'a aba abre pelo painel.js, oculta de início');
        $raiz = $painel->filter('div.mp-voto[data-painel-aba="voto"] > div.mp-voto-raiz[data-mp-voto]');
        self::assertCount(1, $raiz);
        self::assertSame('/master/voto', $raiz->attr('data-url-dados'));
        self::assertSame('/master/voto/escritorios/0', $raiz->attr('data-url-escritorio'));
        self::assertNotEmpty($raiz->attr('data-token'));

        $card = $raiz->filter('div.mp-voto-raiz > section.mp-card');
        self::assertCount(1, $card);
        self::assertSame('Liberação por escritório', trim($card->filter('section > div.mp-cab-roxo > b')->text()));
        self::assertSame(
            'O Voto só aparece para o escritório liberado aqui e enquanto estiver ligado em Telas e recursos. Todo escritório começa em modo demonstração.',
            $card->filter('section > p.mp-voto-nota')->text(),
        );
        self::assertSame(['Escritório', 'Liberado'], $card->filter('div.mp-rolagem > div.mp-voto-grade > div.mp-voto-cab > div')->each(static fn ($c): string => trim($c->text())));
        self::assertCount(0, $card->filter('button'), 'sem botão Salvar: a chave de cada linha grava na hora (montada pelo voto.js)');
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $raiz->text());

        self::assertCount(1, $crawler->filter('script[src*="js/master/voto.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="css/master-voto.css"]'));
    }

    #[TestDox('JS da aba: sem texto cru (tudo pelo catálogo master.js.voto.*), as chaves usadas existem e nenhuma tem travessão')]
    public function testJsUsaOCatalogo(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/voto.js');
        preg_match_all("/T\\('(master\\.js\\.voto\\.[a-z_]+)'/", $js, $m);
        $tradutor = static::getContainer()->get('translator');

        self::assertNotEmpty($m[1]);
        self::assertStringContainsString('mp-rec-item', $js, 'a chave da linha é a do .mp-rec-item');
        self::assertStringContainsString("'switch'", $js);
        foreach (array_unique($m[1]) as $chave) {
            self::assertNotSame($chave, $tradutor->trans($chave, [], null, 'pt_BR'), $chave . ' existe no catálogo');
            self::assertDoesNotMatchRegularExpression('/[—–]/u', $tradutor->trans($chave, [], null, 'pt_BR'));
        }
    }

    private function entrarComoMaster(): User
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): Tenant
    {
        $tenant = $this->escritorio('Escritório comum ' . uniqid());
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        return $tenant;
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome);
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function liberacaoGravada(Tenant $tenant, bool $liberado): void
    {
        $liberacao = new LiberacaoDoVoto($tenant);
        if ($liberado) {
            $liberacao->liberar();
        }
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('voto_master_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário do Voto do Master');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    private function liberacaoDo(Tenant $tenant): ?LiberacaoDoVoto
    {
        $this->em->clear();

        return $this->em->getRepository(LiberacaoDoVoto::class)->findOneBy(['tenant' => $tenant->getId()]);
    }

    private function linhasDo(Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.liberacao WHERE tenant_id = ?', [$tenant->getId()]);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return ?array<string, mixed>
     */
    private function linhaDe(array $dados, Tenant $tenant, bool $exigir = true): ?array
    {
        foreach ($dados['escritorios'] as $linha) {
            if ($linha['tenantId'] === $tenant->getId()) {
                return $linha;
            }
        }
        if ($exigir) {
            self::fail('o escritório ' . $tenant->getId() . ' não está na grade');
        }

        return null;
    }

    private function postar(string $url, mixed $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: is_string($corpo) ? $corpo : (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
    }

    private function instalarCsrfFixo(): void
    {
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
}
