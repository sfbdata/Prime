<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Controller\DescadastroDeEmailController;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\LinkDeDescadastro;
use App\Shared\Email\Descadastro\PedidoDeDescadastro;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A rota pública do link "Não quero mais receber estes avisos" (spec push-avisar-quando-chegar D3):
 * sem login; o GET só mostra o pedido (antivírus que pré-abrem links não descadastram ninguém); o
 * POST na mesma URL assinada grava (botão "Confirmar", com a opção de opt-out total, ou o one-click
 * do RFC 8058, só o assunto); assinatura inválida, 404; repetir não duplica; quem está logado em
 * OUTRO escritório também consegue; o link da plataforma grava no escopo da plataforma.
 */
#[CoversClass(DescadastroDeEmailController::class)]
final class DescadastroDeEmailControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function descadastro(): DescadastroDeEmailInterface
    {
        return static::getContainer()->get(DescadastroDeEmailInterface::class);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome);
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function link(?Tenant $tenant, string $email = 'joana@gmail.com'): string
    {
        return $this->descadastro()->gerarLink($tenant, $email, 'push_aviso_equipe');
    }

    /** @return list<array{assunto: string, origem: string}> */
    private function linhas(?Tenant $tenant, string $email = 'joana@gmail.com'): array
    {
        $escopo = $tenant === null ? 'tenant_id IS NULL' : 'tenant_id = ' . (int) $tenant->getId();

        /** @var list<array{assunto: string, origem: string}> */
        return $this->em()->getConnection()->fetchAllAssociative(
            'SELECT assunto, origem FROM email_descadastro WHERE ' . $escopo . ' AND email = ? ORDER BY assunto',
            [$email],
        );
    }

    #[TestDox('GET não grava: mostra o endereço mascarado, o nome do remetente, a opção desmarcada e o botão "Confirmar" que posta na mesma URL')]
    public function testGetSoMostra(): void
    {
        $tenant = $this->tenant('Silva Advogados');
        $url = $this->link($tenant);

        $crawler = $this->client->request('GET', $url);
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Não quer mais receber nossos e-mails?');
        self::assertSelectorTextContains('main', 'O endereço j***@gmail.com deixará de receber estes avisos por e-mail de Silva Advogados.');
        self::assertStringNotContainsString('joana@gmail.com', (string) $this->client->getResponse()->getContent());
        $form = $crawler->filter('main form[method="post"]');
        self::assertSame(parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY), $form->attr('action'));
        self::assertSame('Confirmar descadastro', trim($form->filter('button[name="confirmar"]')->text()));
        self::assertSame(['demais', 'sem_interesse', 'nao_cadastrei', 'outro'], $form->filter('input[type="radio"][name="motivo"]')->each(static fn ($n) => (string) $n->attr('value')), 'chips de motivo, nenhum marcado');
        self::assertCount(0, $form->filter('input[type="radio"][checked]'));
        self::assertSame('Não quero receber nenhum e-mail de Silva Advogados', trim($form->filter('input[name="todos"]')->ancestors()->filter('label')->text()));
        self::assertNull($form->filter('input[type="checkbox"][name="todos"]')->attr('checked'), 'desmarcada por padrão');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');
        self::assertSame([], $this->linhas($tenant), 'o GET não grava');
    }

    #[TestDox('Botão "Confirmar" sem a opção: grava só o assunto do link, confirma; o segundo clique não duplica')]
    public function testConfirmarSoOAssunto(): void
    {
        $tenant = $this->tenant('Silva Advogados');
        $url = $this->link($tenant);

        $this->client->submit($this->client->request('GET', $url)->selectButton('Confirmar descadastro')->form());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Pronto, você não receberá mais');
        self::assertSelectorTextContains('main', 'Você não vai mais receber estes avisos por e-mail de Silva Advogados.');
        self::assertStringNotContainsString('—', (string) $this->client->getResponse()->getContent());
        $this->client->request('POST', $url, ['confirmar' => '1']);
        self::assertSame([['assunto' => 'push_aviso_equipe', 'origem' => 'link_do_email']], $this->linhas($tenant));
        self::assertFalse($this->descadastro()->estaDescadastrado($tenant, 'joana@gmail.com', 'outro_assunto'));
    }

    #[TestDox('Chip de motivo: grava o texto do motivo escolhido; chave fora da lista e one-click não gravam motivo')]
    public function testMotivo(): void
    {
        $tenant = $this->tenant('Silva Advogados');
        $url = $this->link($tenant);

        $this->client->request('POST', $url, ['confirmar' => '1', 'motivo' => 'sem_interesse']);
        self::assertSame('Não me interessa mais', $this->motivos($tenant)['push_aviso_equipe']);

        $outro = $this->tenant('Souza Advogados');
        $this->client->request('POST', $this->link($outro), ['confirmar' => '1', 'motivo' => '<script>']);
        self::assertNull($this->motivos($outro)['push_aviso_equipe']);

        $um = $this->tenant('Lima Advogados');
        $this->client->request('POST', $this->link($um), ['motivo' => 'demais']);
        self::assertNull($this->motivos($um)['push_aviso_equipe']);
    }

    /** @return array<string, ?string> */
    private function motivos(Tenant $tenant, string $email = 'joana@gmail.com'): array
    {
        /** @var array<string, ?string> */
        return $this->em()->getConnection()->fetchAllKeyValue(
            'SELECT assunto, motivo FROM email_descadastro WHERE tenant_id = ? AND email = ?',
            [(int) $tenant->getId(), $email],
        );
    }

    #[TestDox('Botão com "Não quero receber nenhum e-mail deste remetente": grava também "todos" no escopo do link')]
    public function testConfirmarTodos(): void
    {
        $tenant = $this->tenant('Silva Advogados');
        $form = $this->client->request('GET', $this->link($tenant))->selectButton('Confirmar descadastro')->form();
        $form['todos']->tick();

        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Você não vai mais receber nenhum e-mail de Silva Advogados.');
        self::assertSame(['push_aviso_equipe', 'todos'], array_column($this->linhas($tenant), 'assunto'));
        self::assertTrue($this->descadastro()->estaDescadastrado($tenant, 'joana@gmail.com', 'outro_assunto'));
    }

    #[TestDox('One-click (List-Unsubscribe-Post, sem token nem botão): grava só o assunto, com origem one_click, mesmo se mandar "todos"')]
    public function testOneClick(): void
    {
        $tenant = $this->tenant('Escritório One Click');

        $this->client->request('POST', $this->link($tenant), ['List-Unsubscribe' => 'One-Click', 'todos' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame([['assunto' => 'push_aviso_equipe', 'origem' => 'one_click']], $this->linhas($tenant));
    }

    #[TestDox('Link da plataforma: remetente "BlueJus" e grava no escopo da plataforma, sem tocar escritório')]
    public function testPlataforma(): void
    {
        $tenant = $this->tenant('Escritório Vizinho');
        $url = $this->link(null, 'plataforma_' . uniqid() . '@gmail.com');
        $email = (string) $this->descadastroDoLink($url);

        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', 'por e-mail de BlueJus.');
        $this->client->request('POST', $url, ['confirmar' => '1', 'todos' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['push_aviso_equipe', 'todos'], array_column($this->linhas(null, $email), 'assunto'));
        self::assertSame([], $this->linhas($tenant, $email));
    }

    #[TestDox('Assinatura inválida, ausente ou de escritório que não existe: 404 no GET e no POST, e nada gravado')]
    public function testLinkInvalido(): void
    {
        $tenant = $this->tenant('Escritório Inválido');
        $url = $this->link($tenant);

        $this->client->request('GET', str_replace('d=', 'd=A', $url));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', str_replace('d=', 'd=A', $url), ['confirmar' => '1']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', (string) preg_replace('/&?_hash=[^&]+/', '', $url));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/email/descadastro');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->linhas($tenant));
    }

    #[TestDox('Link bem assinado de um escritório que não existe (mais): 404 no GET e no POST')]
    public function testEscritorioInexistente(): void
    {
        $url = static::getContainer()->get(LinkDeDescadastro::class)->gerar(new PedidoDeDescadastro(2147480000, 'joana@gmail.com', 'push_aviso_equipe'));

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $url, ['confirmar' => '1']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM email_descadastro WHERE email = 'joana@gmail.com' AND tenant_id = 2147480000"));
    }

    #[TestDox('Logado em OUTRO escritório: o link do primeiro escritório grava no primeiro (TenantFilter não interfere)')]
    public function testLogadoEmOutroEscritorio(): void
    {
        $dono = $this->tenant('Escritório do Link');
        $outro = $this->tenant('Escritório da Sessão');
        $user = new User();
        $user->setEmail('sessao_' . uniqid() . '@test.com');
        $user->setFullName('Logado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $outro));
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $outro);
        $url = $this->link($dono);

        $this->client->request('POST', $url, ['confirmar' => '1']);
        $this->client->request('POST', $url, ['confirmar' => '1']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->linhas($dono));
        self::assertSame([], $this->linhas($outro));
    }

    private function descadastroDoLink(string $url): ?string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $carga = json_decode((string) base64_decode(strtr((string) $query['d'], '-_', '+/')), true);

        return \is_array($carga) ? (string) $carga['e'] : null;
    }
}
