<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Controller\IndicacaoController;
use App\Indicacao\Entity\IndicacaoPausa;
use App\Profile\Entity\UserProfile;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Indicar o BlueJus" pela HTTP (spec indicar-bluejus.md): a janela em JSON, os POST com CSRF, o link curto público,
 * e a tela (item no menu, entre "Convites do escritório" e "Trocar de conta", e a janela com os textos do desenho).
 */
#[CoversClass(IndicacaoController::class)]
final class IndicacaoControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conexao;
    private Tenant $tenant;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = $this->em->getConnection();
        $this->tenant = new Tenant();
        $this->tenant->setName('Souza Advogados ' . uniqid());
        $this->em->persist($this->tenant);
        $this->ana = $this->usuario('Ana Souza Lima', '123.456.789-09');
        $this->vincular($this->ana, true);
        $this->em->flush();
    }

    private function usuario(string $nome, ?string $cpf, ?string $email = null): User
    {
        $user = new User();
        $user->setEmail($email ?? uniqid('ind_') . '@exemplo.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('x');
        $this->em->persist($user);
        if ($cpf !== null) {
            $perfil = new UserProfile($user);
            $perfil->setCpf($cpf);
            $perfil->setTelefone('(61) 99999-0000');
            $user->setProfile($perfil);
            $this->em->persist($perfil);
        }

        return $user;
    }

    private function vincular(User $user, bool $primario): void
    {
        $perfil = new TenantRole();
        $perfil->setTenant($this->tenant);
        $perfil->setName($primario ? 'Administrador' : 'Advogado');
        $perfil->setIsSystem($primario);
        $this->em->persist($perfil);
        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($perfil);
        $this->em->persist($vinculo);
    }

    /** Abre uma página com o menu e devolve a janela (e o token CSRF dela). */
    private function janelaNaTela(): Crawler
    {
        $this->logarComTenant($this->client, $this->ana, $this->tenant);
        $crawler = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $crawler->filter('#modalIndicacao');
    }

    private function token(): string
    {
        return (string) $this->janelaNaTela()->attr('data-token');
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo = [], ?string $token = null): array
    {
        $this->client->request('POST', $url, server: ['HTTP_X_CSRF_TOKEN' => $token ?? $this->token(), 'CONTENT_TYPE' => 'application/json'], content: json_encode($corpo, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function contarIndicacoes(User $user): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM indicacao WHERE indicado_por_id = ?', [$user->getId()]);
    }

    #[TestDox('a janela exige login')]
    public function testJanelaExigeLogin(): void
    {
        $this->client->request('GET', '/indicacao');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /indicacao: JSON com nome, link curto /i/{codigo}, mensagem, total e lista vazia')]
    public function testJanelaEmJson(): void
    {
        $this->logarComTenant($this->client, $this->ana, $this->tenant);
        $this->client->request('GET', '/indicacao');

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Ana Souza Lima', $dados['nome']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/i/[A-HJ-NP-Z2-9]{8}$#', $dados['link']);
        self::assertStringEndsWith($dados['link'], $dados['mensagem']);
        self::assertSame([], $dados['pendencias']);
        self::assertNull($dados['trava']);
        self::assertSame(0, $dados['total']);
        self::assertSame([], $dados['ultimas']);

        $this->client->request('GET', '/indicacao');
        self::assertSame($dados['link'], json_decode((string) $this->client->getResponse()->getContent(), true)['link'], 'o código não muda');
    }

    #[TestDox('sem escritório na sessão a janela também abre (o dado é da pessoa)')]
    public function testJanelaSemEscritorio(): void
    {
        $this->client->loginUser($this->ana);
        $this->marcarTermosAceitos($this->client);
        $this->client->request('GET', '/indicacao');

        self::assertResponseIsSuccessful();
        self::assertSame('Ana Souza Lima', json_decode((string) $this->client->getResponse()->getContent(), true)['nome']);
    }

    #[TestDox('cadastro incompleto: a janela lista o que falta')]
    public function testJanelaComCadastroIncompleto(): void
    {
        $bia = $this->usuario('Bia', null);
        $this->vincular($bia, false);
        $this->em->flush();
        $this->logarComTenant($this->client, $bia, $this->tenant);
        $this->client->request('GET', '/indicacao');

        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['nome completo', 'CPF'], $dados['pendencias']);
        self::assertSame('Por segurança, o convite sempre leva o seu nome, então precisamos do seu nome completo, CPF.', $dados['textoDasPendencias']);
    }

    #[TestDox('POST sem token CSRF válido: 403 e nada gravado')]
    public function testCsrf(): void
    {
        $this->logarComTenant($this->client, $this->ana, $this->tenant);

        $dados = $this->postar('/indicacao/email', ['email' => 'bia@exemplo.com'], 'token-errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Token CSRF inválido. Recarregue a página.', $dados['mensagem']);
        $this->postar('/indicacao/canal/link', [], 'token-errado');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contarIndicacoes($this->ana));
    }

    #[TestDox('POST e-mail: grava a indicação e o envio do Carteiro (origem e lista indicacao, sem escopo)')]
    public function testConvitePorEmail(): void
    {
        $dados = $this->postar('/indicacao/email', ['email' => 'Bia@Exemplo.com', 'nome' => 'Bia']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'mensagem' => 'Convite enviado para bia@exemplo.com. Obrigado por indicar o BlueJus!'], $dados);
        $linha = $this->conexao->fetchAssociative('SELECT canal, email, nome, tenant_origem_id FROM indicacao WHERE indicado_por_id = ?', [$this->ana->getId()]);
        self::assertSame(['canal' => 'email', 'email' => 'bia@exemplo.com', 'nome' => 'Bia', 'tenant_origem_id' => $this->tenant->getId()], $linha);
        $envio = $this->conexao->fetchAssociative("SELECT tipo, lista, tenant_id, assunto FROM comercial_envio WHERE destinatario = 'bia@exemplo.com' ORDER BY id DESC LIMIT 1");
        self::assertSame(['tipo' => 'lista', 'lista' => 'indicacao', 'tenant_id' => null, 'assunto' => 'Ana Souza Lima convidou você para o BlueJus'], $envio);

        $this->client->request('GET', '/indicacao');
        $janela = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $janela['total']);
        self::assertSame(['nome' => 'Bia', 'email' => 'bia@exemplo.com', 'canal' => 'email', 'contaCriada' => false], array_diff_key($janela['ultimas'][0], ['em' => 1]));
    }

    #[TestDox('POST e-mail de quem já é do meu escritório: 422 "Esta pessoa já faz parte da sua conta."')]
    public function testMembroDoEscritorio(): void
    {
        $colega = $this->usuario('Colega Lima', null, uniqid('colega_') . '@exemplo.com');
        $this->vincular($colega, false);
        $this->em->flush();

        $dados = $this->postar('/indicacao/email', ['email' => strtoupper((string) $colega->getEmail())]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['ok' => false, 'mensagem' => 'Esta pessoa já faz parte da sua conta.'], $dados);
        self::assertSame(0, $this->contarIndicacoes($this->ana));
    }

    #[TestDox('POST e-mail inválido: 422 "Informe um e-mail válido."')]
    public function testEmailInvalido(): void
    {
        self::assertSame('Informe um e-mail válido.', $this->postar('/indicacao/email', ['email' => 'nao-e-email'])['mensagem']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('POST canal: WhatsApp e link registram; pausa da pessoa trava com o texto do desenho')]
    public function testCanais(): void
    {
        self::assertSame('Mensagem aberta no WhatsApp.', $this->postar('/indicacao/canal/whatsapp')['mensagem']);
        self::assertSame('Link copiado.', $this->postar('/indicacao/canal/link')['mensagem']);
        self::assertSame(2, $this->contarIndicacoes($this->ana));

        $this->em->persist(new IndicacaoPausa($this->em->find(User::class, $this->ana->getId()), null, new \DateTimeImmutable()));
        $this->em->flush();
        $dados = $this->postar('/indicacao/canal/link');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Seus envios de convite foram pausados pela administração do BlueJus.', $dados['mensagem']);
        self::assertSame(2, $this->contarIndicacoes($this->ana));
    }

    #[TestDox('POST canal desconhecido: 404')]
    public function testCanalDesconhecido(): void
    {
        $this->logarComTenant($this->client, $this->ana, $this->tenant);
        $this->client->request('POST', '/indicacao/canal/email');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('/i/{codigo} é pública: guarda o código na sessão e leva ao cadastro')]
    public function testLinkCurtoGuardaOCodigo(): void
    {
        $this->conexao->executeStatement('INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?)', [$this->ana->getId(), 'ZXCV2345']);

        $this->client->request('GET', '/i/zxcv2345');

        self::assertResponseRedirects('/cadastro');
        self::assertSame('ZXCV2345', $this->client->getRequest()->getSession()->get(IndicacaoController::CHAVE_DA_SESSAO));
    }

    #[TestDox('/i/{codigo} inexistente: leva ao cadastro igual, sem código guardado (não revela)')]
    public function testLinkCurtoInexistente(): void
    {
        $this->client->request('GET', '/i/NAOEXISTE');

        self::assertResponseRedirects('/cadastro');
        self::assertNull($this->client->getRequest()->getSession()->get(IndicacaoController::CHAVE_DA_SESSAO));
    }

    #[TestDox('/i/{codigo}: visitante anônimo recebe os mesmos cabeçalhos (inclusive o Set-Cookie) com código válido e inválido')]
    public function testLinkCurtoNaoRevelaPeloCabecalho(): void
    {
        $this->conexao->executeStatement('INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?)', [$this->ana->getId(), 'ZXCV2345']);

        $valido = $this->cabecalhosDoLinkCurto('/i/ZXCV2345');
        $invalido = $this->cabecalhosDoLinkCurto('/i/NAOEXISTE');

        self::assertNotSame([], $valido['cookies'], 'a sessão nasce');
        self::assertSame($valido, $invalido);
    }

    /** @return array{status: int, location: ?string, nomes: list<string>, cookies: list<array<string, mixed>>} */
    private function cabecalhosDoLinkCurto(string $url): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $url);
        $resposta = $this->client->getResponse();
        $cookies = array_map(static fn ($c): array => [
            'nome' => $c->getName(), 'caminho' => $c->getPath(), 'dominio' => $c->getDomain(), 'seguro' => $c->isSecure(),
            'httpOnly' => $c->isHttpOnly(), 'sameSite' => $c->getSameSite(), 'expira' => $c->getExpiresTime() > 0,
        ], $resposta->headers->getCookies());
        $nomes = array_keys($resposta->headers->all());
        sort($nomes);

        return ['status' => $resposta->getStatusCode(), 'location' => $resposta->headers->get('Location'), 'nomes' => $nomes, 'cookies' => $cookies];
    }

    #[TestDox('menu: "Indicar o BlueJus" (bi-megaphone, "nova conta") entre "Convites do escritório" e "Trocar de conta"')]
    public function testItemNoMenu(): void
    {
        $this->janelaNaTela();
        $grupo = $this->client->getCrawler()->filter('.menu-usuario > .usuario-dropdown.mu-menu > .mu-grupo')->first();
        $itens = $grupo->children()->each(static fn (Crawler $c): string => trim((string) $c->filter('.mu-item-titulo')->text('')));

        self::assertSame(['Meu Perfil', 'Convites do escritório', 'Indicar o BlueJus', 'Trocar de conta'], $itens);
        $item = $grupo->filter(':scope > button[data-ind-abrir]');
        self::assertCount(1, $item->filter(':scope > i.bi.bi-megaphone'));
        self::assertSame('nova conta', trim($item->filter(':scope > .mu-item-dir')->text()));
    }

    #[TestDox('menu: o secundário (sem Convites) também vê "Indicar o BlueJus"')]
    public function testItemParaOSecundario(): void
    {
        $bia = $this->usuario('Bia Lima', null);
        $this->vincular($bia, false);
        $this->em->flush();
        $this->logarComTenant($this->client, $bia, $this->tenant);

        $crawler = $this->client->request('GET', '/perfil');

        self::assertCount(1, $crawler->filter('.mu-menu > .mu-grupo > button[data-ind-abrir]'));
        self::assertStringContainsString('Quem chama pessoas para a equipe deste escritório é o usuário primário.', $crawler->filter('#modalIndicacao .ind-dica')->text());
    }

    #[TestDox('janela: textos do desenho, botões e "SUAS INDICAÇÕES", no arranjo do desenho (filho direto)')]
    public function testJanelaNaTela(): void
    {
        $janela = $this->janelaNaTela();
        $corpo = '#modalIndicacao > .modal-dialog > .modal-content > .ind-corpo';
        $c = $this->client->getCrawler();

        self::assertSame('Indicar o BlueJus', $c->filter('#modalIndicacao > .modal-dialog > .modal-content > .mu-modal-topo > b')->text());
        self::assertSame('×', $c->filter('#modalIndicacao .mu-modal-topo > button.ind-x')->text());
        self::assertSame(
            'Convide colegas e outros escritórios para criar a própria conta no BlueJus. Quem aceitar abre um escritório novo e não entra na sua equipe. Para chamar alguém para a sua equipe, use Convites do escritório.',
            trim((string) preg_replace('/\s+/u', ' ', $c->filter($corpo . ' > .ind-dica > span:not(.ind-dica-icone)')->text())),
        );
        self::assertSame('Complete seu cadastro para enviar convites', $c->filter($corpo . ' > .ind-pendencias > b')->text());
        self::assertSame('/perfil', $c->filter($corpo . ' > .ind-pendencias > .ind-pendencias-acao > a')->attr('href'));
        self::assertSame('Completar meu cadastro', $c->filter($corpo . ' > .ind-pendencias > .ind-pendencias-acao > a')->text());
        self::assertSame(['Nome (opcional)', 'E-mail'], $c->filter($corpo . ' > .ind-envio > .ind-campos > input')->each(static fn (Crawler $i): string => (string) $i->attr('placeholder')));
        self::assertCount(1, $c->filter($corpo . ' > .ind-envio > textarea#indMensagem'));
        self::assertSame(
            ['Enviar convite por e-mail', 'WhatsApp', 'Copiar link curto'],
            $c->filter($corpo . ' > .ind-envio > .ind-acoes > button')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertStringStartsWith('O convite é enviado em seu nome:', $c->filter($corpo . ' > .ind-envio > .ind-quem')->text());
        self::assertSame('SUAS INDICAÇÕES (0)', $c->filter($corpo . ' > .ind-titulo-lista')->text());
        self::assertSame('Nenhuma indicação ainda.', $c->filter($corpo . ' > .ind-lista > .ind-vazio')->text());
        self::assertNotSame('', (string) $janela->attr('data-token'));
        self::assertSame('/indicacao', $janela->attr('data-url-janela'));
    }
}
