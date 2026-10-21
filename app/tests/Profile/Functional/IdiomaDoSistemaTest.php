<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Profile\Controller\PreferenciasDoPerfilController;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\Idioma;
use App\Profile\EventListener\IdiomaDaRequisicaoListener;
use App\Profile\Twig\IdiomaExtension;
use App\Profile\UseCase\DefinirIdiomaDoPerfilUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Idioma do sistema, entrega 1 (spec docs/specs/idioma-do-sistema.md): o idioma fica no perfil, vale para a
 * requisição (`<html lang/dir>`), o Meu Perfil mostra o nome e o modal sai da lista do enum. Só o português está
 * liberado; o resto aparece "em breve", como hoje.
 */
#[CoversClass(PreferenciasDoPerfilController::class)]
#[CoversClass(DefinirIdiomaDoPerfilUseCase::class)]
#[CoversClass(IdiomaDaRequisicaoListener::class)]
#[CoversClass(IdiomaExtension::class)]
final class IdiomaDoSistemaTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->tenant = (new Tenant())->setName('Escritório Idioma ' . uniqid());
        $this->em->persist($this->tenant);
        $papel = (new TenantRole())->setTenant($this->tenant)->setName('Advogado ' . uniqid())->setIsSystem(false);
        $this->em->persist($papel);
        $this->usuario = (new User())->setEmail(uniqid('idioma_') . '@test.com')->setFullName('Ana Souza')->setRoles(['ROLE_USER'])->setIsActive(true);
        $this->usuario->setPassword('x');
        $this->em->persist($this->usuario);
        $this->em->persist((new UserTenant($this->usuario, $this->tenant))->setTenantRole($papel));
        $this->em->flush();
    }

    #[TestDox('a página sai com lang="pt-BR" e dir="ltr"; o Meu Perfil mostra "Português (Brasil)"; o modal lista os 10 idiomas, só o português liberado e marcado')]
    public function testTelaEmPortugues(): void
    {
        $tela = $this->abrir();

        self::assertSame('pt-BR', $tela->filter('html')->attr('lang'));
        self::assertSame('ltr', $tela->filter('html')->attr('dir'));
        self::assertCount(1, $tela->filter('script[src*="js/bj-formato.js"]'), 'os formatos do JS pelo idioma (entrega 2)');
        self::assertSame('Português (Brasil)', trim($tela->filter('.mp-prefs > button[data-mu-idioma] > .mp-pref-valor')->text()));

        $botoes = $tela->filter('#modalIdioma .mu-idiomas > button.mu-idioma');
        self::assertCount(10, $botoes);
        self::assertSame(array_map(static fn (Idioma $i): string => $i->nome(), Idioma::cases()), $botoes->each(static fn (Crawler $b): string => trim($b->filter('.mu-idioma-nome')->text())));
        self::assertCount(1, $tela->filter('#modalIdioma .mu-idiomas > button.mu-idioma.atual[data-mu-idioma-escolher="pt-BR"]:not([disabled])'));
        self::assertCount(9, $tela->filter('#modalIdioma .mu-idiomas > button.mu-idioma[disabled] > .mu-idioma-breve'));
    }

    #[TestDox('POST /perfil/idioma: o português grava no perfil; idioma "em breve" ou desconhecido dá 422 e não grava; sem o token, 403')]
    public function testGravarIdioma(): void
    {
        $tela = $this->abrir();

        $this->postar($tela, ['idioma' => 'pt-BR']);
        self::assertResponseIsSuccessful();
        self::assertSame('pt-BR', $this->idiomaGravado());

        $this->postar($tela, ['idioma' => 'en']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Este idioma ainda não está disponível.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->postar($tela, ['idioma' => 'xx']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('pt-BR', $this->idiomaGravado());

        $this->client->request('POST', '/perfil/idioma', [], [], ['CONTENT_TYPE' => 'application/json'], '{"idioma":"pt-BR"}');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('idioma gravado mas ainda não liberado (ex.: inglês gravado antes) vale como português na tela')]
    public function testIdiomaNaoLiberadoValeComoPortugues(): void
    {
        $perfil = (new UserProfile($this->usuario))->setIdioma(Idioma::Ingles);
        $this->em->persist($perfil);
        $this->em->flush();

        $tela = $this->abrir();

        self::assertSame('pt-BR', $tela->filter('html')->attr('lang'));
        self::assertSame('Português (Brasil)', trim($tela->filter('.mp-prefs > button[data-mu-idioma] > .mp-pref-valor')->text()));
        self::assertSame('pt_BR', $this->client->getRequest()->getLocale());
    }

    private function abrir(): Crawler
    {
        $this->logarComTenant($this->client, $this->usuario, $this->tenant);
        $tela = $this->client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        return $tela;
    }

    /** @param array<string, string> $dados */
    private function postar(Crawler $tela, array $dados): void
    {
        $token = (string) $tela->filter('meta[name="csrf-token"]')->attr('content');
        $this->client->request('POST', '/perfil/idioma', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], (string) json_encode($dados));
    }

    private function idiomaGravado(): ?string
    {
        $valor = $this->em->getConnection()->fetchOne('SELECT idioma FROM user_profiles WHERE user_id = ?', [$this->usuario->getId()]);

        return $valor === false ? null : $valor;
    }
}
