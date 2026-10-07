<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaMencaoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\UseCase\EnviarMensagemPastaUseCase;
use App\Pasta\UseCase\ListarMencionaveisDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Item 20b: @menção no Registro da pasta — o autocompletar (`GET /pasta/{id}/mencionaveis`), o
 * envio com menção (`POST /pasta/{id}/mensagem`, notificação ao mencionado) e a exibição escapada
 * na `pasta_show`.
 */
#[CoversClass(PastaMencaoController::class)]
#[CoversClass(ListarMencionaveisDaPastaUseCase::class)]
#[CoversClass(EnviarMensagemPastaUseCase::class)]
#[CoversClass(MencoesDoRegistro::class)]
#[Group('pasta')]
final class PastaMencaoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function membro(Tenant $tenant, string $nome): User
    {
        $user = new User();
        $user->setEmail('menc_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @return list<array<string, mixed>> */
    private function mencionaveis(KernelBrowser $client, Pasta $pasta, string $q = ''): array
    {
        $client->request('GET', '/pasta/' . $pasta->getId() . '/mencionaveis', ['q' => $q], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    /** @return array<string, mixed> o JSON do envio */
    private function enviar(KernelBrowser $client, Pasta $pasta, string $conteudo): array
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', [
            '_token'   => 'TOKEN_pasta_mensagem_' . $pasta->getId(),
            'conteudo' => $conteudo,
        ]);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    /** @return Notificacao[] */
    private function mencoesRecebidas(User $usuario): array
    {
        return $this->em()->createQuery(
            'SELECT n FROM ' . Notificacao::class . ' n WHERE n.usuario = :u AND n.tipo = :t ORDER BY n.id ASC'
        )
            ->setParameter('u', $usuario)
            ->setParameter('t', Notificacao::TIPO_PASTA_MENCAO_REGISTRO)
            ->getResult();
    }

    // ── Autocompletar ────────────────────────────────────────────────────────

    #[TestDox('Autocompletar: só colegas DESTE escritório com acesso à pasta, sem quem pergunta e sem e-mail na resposta')]
    public function testAutocompletarSoColegasComAcessoSemEmail(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Eu Mesmo');
        $ana     = $this->membro($tenantA, 'Ana Paula Souza');
        $semAcesso = $this->criarUsuarioSemNenhumaPermissao($tenantA);
        $deFora  = $this->membro($tenantB, 'Ana de Outro Escritório');
        $pasta   = $this->criarPasta($tenantA);

        $this->logarComTenant($client, $eu, $tenantA);
        $lista = $this->mencionaveis($client, $pasta);

        self::assertResponseIsSuccessful();
        $ids = array_column($lista, 'id');
        self::assertContains($ana->getId(), $ids);
        self::assertNotContains($semAcesso->getId(), $ids, 'sem acesso à pasta não aparece');
        self::assertNotContains($deFora->getId(), $ids, 'colega de outro escritório nunca aparece');
        self::assertNotContains($eu->getId(), $ids, 'quem pergunta não se menciona');

        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('@test.com', $corpo);
        self::assertStringNotContainsString('email', $corpo);
        foreach ($lista as $pessoa) {
            self::assertSame(['id', 'nome', 'iniciais', 'cargo', 'foto'], array_keys($pessoa));
        }

        // Busca pelo começo de qualquer palavra do nome.
        self::assertSame([$ana->getId()], array_column($this->mencionaveis($client, $pasta, 'sou'), 'id'));
        self::assertSame([], $this->mencionaveis($client, $pasta, 'outro'));
    }

    #[TestDox('Autocompletar numa pasta de OUTRO escritório responde 404 e não lista ninguém')]
    public function testAutocompletarPastaDeOutroEscritorio(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Eu Mesmo');
        $this->membro($tenantB, 'Bruno de Lá');
        $pastaB  = $this->criarPasta($tenantB);

        $this->logarComTenant($client, $eu, $tenantA);
        $client->request('GET', '/pasta/' . $pastaB->getId() . '/mencionaveis', ['q' => '']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Bruno', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Autocompletar sem acesso à pasta responde 403')]
    public function testAutocompletarSemAcessoAPasta(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $this->membro($tenant, 'Ana');
        $semNada = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta  = $this->criarPasta($tenant);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId() . '/mencionaveis', ['q' => '']);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Ana', (string) $client->getResponse()->getContent());
    }

    // ── Envio com menção ─────────────────────────────────────────────────────

    #[TestDox('Mencionar notifica o colega com acesso (link para a mensagem); sem acesso, a si mesmo e id de outro escritório não')]
    public function testMencaoNotificaSoQuemPode(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Ana Paula Souza');
        $bruno   = $this->membro($tenantA, 'Bruno Lima');
        $semAcesso = $this->criarUsuarioSemNenhumaPermissao($tenantA);
        $deFora  = $this->membro($tenantB, 'Carla de Fora');
        $pasta   = $this->criarPasta($tenantA);

        $this->logarComTenant($client, $eu, $tenantA);
        $dados = $this->enviar($client, $pasta, sprintf(
            '<p>Veja @[Bru](user:%d), @[S](user:%d), @[Eu](user:%d) e @[Carla](user:%d)</p>',
            $bruno->getId(),
            $semAcesso->getId(),
            $eu->getId(),
            $deFora->getId(),
        ));

        self::assertResponseStatusCodeSame(201);
        $msgId = (int) $dados['id'];

        $doBruno = $this->mencoesRecebidas($bruno);
        self::assertCount(1, $doBruno);
        self::assertSame('Ana mencionou você na Pasta ' . $pasta->getNup() . ' · Dados da pasta', $doBruno[0]->getTitulo());
        self::assertSame('/pasta/' . $pasta->getId() . '#pasta-msg-' . $msgId, $doBruno[0]->getUrl());
        self::assertSame($tenantA->getId(), $doBruno[0]->getTenant()?->getId());
        self::assertSame('bi-at text-primary', $doBruno[0]->getIcone());
        self::assertStringContainsString('@Bruno Lima', (string) $doBruno[0]->getMensagem());

        self::assertCount(0, $this->mencoesRecebidas($semAcesso), 'colega sem acesso à pasta não recebe');
        self::assertCount(0, $this->mencoesRecebidas($eu), 'quem escreveu não recebe');
        self::assertCount(0, $this->mencoesRecebidas($deFora), 'outro escritório nunca recebe');

        // Gravado: Bruno com o nome do banco; o id de outro escritório virou texto comum,
        // sem o nome de lá (o rótulo digitado é o que fica).
        $msg = $this->em()->find(PastaMensagem::class, $msgId);
        self::assertNotNull($msg);
        self::assertStringContainsString('@[Bruno Lima](user:' . $bruno->getId() . ')', $msg->getConteudo());
        self::assertStringNotContainsString('(user:' . $deFora->getId() . ')', $msg->getConteudo());
        self::assertStringNotContainsString('Carla de Fora', $msg->getConteudo());

        // A resposta já traz o destaque para o JS inserir o cartão.
        self::assertStringContainsString('<span class="ps-mencao" data-user-id="' . $bruno->getId() . '">@Bruno Lima</span>', (string) $dados['conteudoHtml']);

        // O link abre a pasta para o mencionado, com a âncora da mensagem e o destaque.
        $this->logarComTenant($client, $bruno, $tenantA);
        $crawler = $client->request('GET', (string) strtok((string) $doBruno[0]->getUrl(), '#'));
        self::assertResponseIsSuccessful();
        self::assertSame('@Bruno Lima', trim($crawler->filter('#pasta-msg-' . $msgId . ' .ps-mencao')->text()));
    }

    #[TestDox('Ex-colaborador (vínculo inativo) mencionado não recebe e perde a marcação')]
    public function testExColaboradorNaoRecebe(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $saiu   = $this->membro($tenant, 'Bruno Saiu');
        $pasta  = $this->criarPasta($tenant);

        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $saiu, 'tenant' => $tenant]);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($vinculo, false);
        $this->em()->flush();

        $this->logarComTenant($client, $eu, $tenant);
        $dados = $this->enviar($client, $pasta, 'Oi @[Bruno](user:' . $saiu->getId() . ')');

        self::assertResponseStatusCodeSame(201);
        self::assertCount(0, $this->mencoesRecebidas($saiu));
        self::assertSame('Oi @Bruno', $dados['conteudo']);
    }

    // ── Edição ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> o JSON da edição */
    private function editar(KernelBrowser $client, Pasta $pasta, int $msgId, string $conteudo): array
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem/' . $msgId . '/editar', [
            '_token'   => 'TOKEN_pasta_mensagem_editar_' . $msgId,
            'conteudo' => $conteudo,
        ]);
        $dados = json_decode((string) $client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    #[TestDox('Editar com token forjado ou com id de OUTRO escritório grava texto comum, sem destaque e sem notificar')]
    public function testEdicaoComTokenForjadoViraTexto(): void
    {
        $client  = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $eu      = $this->membro($tenantA, 'Ana');
        $deFora  = $this->membro($tenantB, 'Carla de Fora');
        $pasta   = $this->criarPasta($tenantA);

        $this->logarComTenant($client, $eu, $tenantA);
        $msgId = (int) $this->enviar($client, $pasta, 'Original')['id'];
        self::assertResponseStatusCodeSame(201);

        $dados = $this->editar($client, $pasta, $msgId, '<p>Falar com @[Diretor Geral](user:9999999999) e @[Carla](user:' . $deFora->getId() . ')</p>');

        self::assertResponseIsSuccessful();
        self::assertSame('<p>Falar com @Diretor Geral e @Carla</p>', $dados['conteudo']);
        self::assertStringNotContainsString('ps-mencao', (string) $dados['conteudoHtml']);
        self::assertCount(0, $this->mencoesRecebidas($deFora));
    }

    #[TestDox('Editar notifica quem passou a ser mencionado; quem já foi avisado por esta mensagem não recebe de novo')]
    public function testEdicaoNotificaSoMencaoNova(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $bruno  = $this->membro($tenant, 'Bruno Lima');
        $carla  = $this->membro($tenant, 'Carla Dias');
        $pasta  = $this->criarPasta($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $msgId = (int) $this->enviar($client, $pasta, 'Oi @[Bruno](user:' . $bruno->getId() . ')')['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->mencoesRecebidas($bruno));

        $this->editar($client, $pasta, $msgId, 'Oi @[Bruno](user:' . $bruno->getId() . ') e @[Carla](user:' . $carla->getId() . ')');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->mencoesRecebidas($bruno), 'já avisado por esta mensagem: não recebe de novo');
        $daCarla = $this->mencoesRecebidas($carla);
        self::assertCount(1, $daCarla);
        self::assertSame('/pasta/' . $pasta->getId() . '#pasta-msg-' . $msgId, $daCarla[0]->getUrl());
        self::assertCount(0, $this->mencoesRecebidas($eu));
    }

    // ── Exibição ─────────────────────────────────────────────────────────────

    #[TestDox('XSS no nome: o destaque da menção sai escapado na pasta_show (nada vira tag)')]
    public function testDestaqueEscapadoNaTela(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $pasta  = $this->criarPasta($tenant);

        // Gravado por qualquer caminho (importação, banco): o rótulo traz uma tag codificada.
        $msg = (new PastaMensagem())
            ->setPasta($pasta)
            ->setAutor($eu)
            ->setTenant($tenant)
            ->setConteudo('<p>Oi @[&lt;img src=x onerror=alert(1)&gt;](user:' . $eu->getId() . ')</p>');
        $this->em()->persist($msg);
        $this->em()->flush();

        $this->logarComTenant($client, $eu, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        $cartao = $crawler->filter('#pasta-msg-' . $msg->getId() . ' .pasta-msg-conteudo');
        self::assertCount(0, $cartao->filter('img'), 'o rótulo não pode virar <img>');
        self::assertSame('@<img src=x onerror=alert(1)>', trim($cartao->filter('.ps-mencao')->text()));
    }

    #[TestDox('Nome com aspas e "&" é gravado e exibido íntegro no destaque')]
    public function testNomeComCaracteresEspeciais(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $eu     = $this->membro($tenant, 'Ana');
        $outro  = $this->membro($tenant, 'Zé "Bigode" & Cia');
        $pasta  = $this->criarPasta($tenant);

        $this->logarComTenant($client, $eu, $tenant);
        $dados = $this->enviar($client, $pasta, '<p>Oi @[Zé](user:' . $outro->getId() . ')</p>');
        self::assertResponseStatusCodeSame(201);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('@Zé "Bigode" & Cia', trim($crawler->filter('#pasta-msg-' . $dados['id'] . ' .ps-mencao')->text()));
    }
}
