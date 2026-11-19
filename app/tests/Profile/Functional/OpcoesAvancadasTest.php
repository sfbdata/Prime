<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Controller\PreferenciasDoPerfilController;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\FusoHorario;
use App\Profile\EventListener\FormatoDaRequisicaoListener;
use App\Profile\Twig\IdiomaExtension;
use App\Profile\UseCase\SalvarOpcoesAvancadasUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Bandeira do idioma no topo e "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §1 e §2):
 * a bandeira abre o modal de idioma; as opções gravam idioma, fuso e formatos de uma vez, voltam na tela seguinte
 * (no `<html>` para o JS e marcadas no modal) e recusam o que não está na lista.
 */
#[CoversClass(PreferenciasDoPerfilController::class)]
#[CoversClass(SalvarOpcoesAvancadasUseCase::class)]
#[CoversClass(FormatoDaRequisicaoListener::class)]
#[CoversClass(IdiomaExtension::class)]
final class OpcoesAvancadasTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('Escritório Opções ' . uniqid());
        $this->em->persist($this->tenant);
        $papel = (new TenantRole())->setTenant($this->tenant)->setName('Advogado ' . uniqid())->setIsSystem(false);
        $this->em->persist($papel);
        $this->usuario = (new User())->setEmail(uniqid('opcoes_') . '@test.com')->setFullName('Ana Souza')->setRoles(['ROLE_USER'])->setIsActive(true);
        $this->usuario->setPassword('x');
        $this->em->persist($this->usuario);
        $this->em->persist((new UserTenant($this->usuario, $this->tenant))->setTenantRole($papel));
        $this->em->flush();
    }

    #[TestDox('a bandeira do Brasil fica no topo, antes da tela cheia, e abre o modal de idioma; sem opções gravadas, o <html> não leva atributos e o modal marca Brasília e automático')]
    public function testBandeiraEPadrao(): void
    {
        $tela = $this->abrir();

        $bandeira = $tela->filter('.navtop-right > button.navtop-idioma[data-mu-idioma]');
        self::assertCount(1, $bandeira);
        self::assertSame('Idioma: Português (Brasil)', $bandeira->attr('aria-label'));
        self::assertCount(1, $bandeira->filter('.navtop-bandeira > svg'));
        self::assertCount(1, $tela->filter('.navtop-right > button.navtop-idioma + a[data-lte-toggle="fullscreen"]'), 'a bandeira vem logo antes da tela cheia');

        $html = $tela->filter('html');
        self::assertNull($html->attr('data-bj-fuso'));
        self::assertNull($html->attr('data-bj-formato-data'));
        self::assertNull($html->attr('data-bj-formato-numero'));

        self::assertSame('Brasília', trim($tela->filter('.mp-prefs > button[data-mu-opcoes] > .mp-pref-valor')->text()));
        $modal = $tela->filter('#modalOpcoesAvancadas form[data-mu-opcoes-form]');
        self::assertSame('pt-BR', $modal->filter('select[name="idioma"] option[selected]')->attr('value'));
        self::assertSame(['es'], $modal->filter('select[name="idioma"] option[disabled]')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('America/Sao_Paulo', $modal->filter('select[name="fuso"] option[selected]')->attr('value'));
        self::assertCount(12, $modal->filter('select[name="fuso"] > option'));
        self::assertSame('', $modal->filter('select[name="formatoData"] option[selected]')->attr('value'));
        self::assertSame(['', 'dmy', 'mdy', 'ymd'], $modal->filter('select[name="formatoData"] > option')->each(static fn (Crawler $o): string => (string) $o->attr('value')));
        self::assertSame('', $modal->filter('select[name="formatoNumero"] option[selected]')->attr('value'));
        self::assertSame(['R$ 1.234,56', 'R$ 1,234.56'], array_slice($modal->filter('select[name="formatoNumero"] > option')->each(static fn (Crawler $o): string => trim($o->text())), 1));
    }

    #[TestDox('salvar: grava no perfil e, na tela seguinte, o <html> leva fuso e formatos, o modal marca as escolhas e a linha mostra o fuso')]
    public function testSalvarEVoltarNaTela(): void
    {
        $tela = $this->abrir();

        $this->postar($tela, ['idioma' => 'pt-BR', 'fuso' => 'America/Los_Angeles', 'formatoData' => 'mdy', 'formatoNumero' => 'us']);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], json_decode((string) $this->client->getResponse()->getContent(), true));

        $perfil = $this->perfil();
        self::assertSame(FusoHorario::LosAngeles, $perfil?->getFusoHorario());
        self::assertSame('mdy', $perfil?->getFormatoData()?->value);
        self::assertSame('us', $perfil?->getFormatoNumero()?->value);

        $depois = $this->client->request('GET', '/perfil');
        $html = $depois->filter('html');
        self::assertSame('America/Los_Angeles', $html->attr('data-bj-fuso'));
        self::assertSame('mdy', $html->attr('data-bj-formato-data'));
        self::assertSame('us', $html->attr('data-bj-formato-numero'));
        self::assertSame('Los Angeles', trim($depois->filter('.mp-prefs > button[data-mu-opcoes] > .mp-pref-valor')->text()));
        self::assertSame('mdy', $depois->filter('#modalOpcoesAvancadas select[name="formatoData"] option[selected]')->attr('value'));

        $this->postar($depois, ['idioma' => 'pt-BR', 'fuso' => '', 'formatoData' => '', 'formatoNumero' => '']);
        self::assertResponseIsSuccessful();
        $deVolta = $this->client->request('GET', '/perfil');
        self::assertNull($deVolta->filter('html')->attr('data-bj-fuso'));
        self::assertNull($deVolta->filter('html')->attr('data-bj-formato-data'));
    }

    #[TestDox('Brasília escolhida fica gravada e vai ao <html> (a hora do JS sai em Brasília); outra pessoa que entra no mesmo navegador não herda as opções guardadas na sessão')]
    public function testBrasiliaEscolhidaETrocaDeConta(): void
    {
        $tela = $this->abrir();
        $this->postar($tela, ['idioma' => 'pt-BR', 'fuso' => 'America/Sao_Paulo', 'formatoData' => 'ymd', 'formatoNumero' => '']);
        self::assertResponseIsSuccessful();
        self::assertSame(FusoHorario::Brasilia, $this->perfil()?->getFusoHorario());
        $depois = $this->client->request('GET', '/perfil');
        self::assertSame('America/Sao_Paulo', $depois->filter('html')->attr('data-bj-fuso'));
        self::assertSame('ymd', $depois->filter('html')->attr('data-bj-formato-data'));

        $outra = (new User())->setEmail(uniqid('opcoes_outra_') . '@test.com')->setFullName('Bia Lima')->setRoles(['ROLE_USER'])->setIsActive(true);
        $outra->setPassword('x');
        $this->em->persist($outra);
        // perfil() limpa o EntityManager: escritório e papel voltam do banco.
        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $this->usuario->getId()]);
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        self::assertNotNull($vinculo);
        self::assertNotNull($tenant);
        $this->em->persist((new UserTenant($outra, $tenant))->setTenantRole($vinculo->getTenantRole()));
        $this->em->flush();
        $this->logarComTenant($this->client, $outra, $tenant);

        $daOutra = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();
        self::assertNull($daOutra->filter('html')->attr('data-bj-fuso'));
        self::assertNull($daOutra->filter('html')->attr('data-bj-formato-data'));
    }

    #[TestDox('recusas: idioma "em breve" e opção fora da lista dão 422 com a mensagem do catálogo e não gravam; pedido malformado, 400; sem o token, 403')]
    public function testRecusas(): void
    {
        $tela = $this->abrir();

        $this->postar($tela, ['idioma' => 'es', 'fuso' => 'UTC']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Este idioma ainda não está disponível.'], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->postar($tela, ['idioma' => 'pt-BR', 'fuso' => 'Asia/Tokyo']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Escolha uma das opções da lista.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertNull($this->perfil()?->getFusoHorario());

        $this->postar($tela, ['idioma' => 'pt-BR', 'fuso' => 3]);
        self::assertResponseStatusCodeSame(400);
        $this->postar($tela, ['idioma' => 5]);
        self::assertResponseStatusCodeSame(400);
        foreach (['"texto"', '{nao e json'] as $corpo) {
            $this->client->request('POST', '/perfil/opcoes-avancadas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => (string) $tela->filter('meta[name="csrf-token"]')->attr('content')], $corpo);
            self::assertResponseStatusCodeSame(400, $corpo);
        }

        $this->client->request('POST', '/perfil/opcoes-avancadas', [], [], ['CONTENT_TYPE' => 'application/json'], '{"idioma":"pt-BR"}');
        self::assertResponseStatusCodeSame(403);
    }

    private function abrir(): Crawler
    {
        $this->logarComTenant($this->client, $this->usuario, $this->tenant);
        $tela = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** @param array<string, mixed> $dados */
    private function postar(Crawler $tela, array $dados): void
    {
        $token = (string) $tela->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('POST', '/perfil/opcoes-avancadas', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], (string) json_encode($dados));
    }

    private function perfil(): ?UserProfile
    {
        $this->em->clear();

        return $this->em->getRepository(UserProfile::class)->findOneBy(['user' => $this->usuario->getId()]);
    }
}
