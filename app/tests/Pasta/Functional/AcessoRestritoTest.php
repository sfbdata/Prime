<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\BarreiraDoAcessoRestritoListener;
use App\Pasta\Exception\ResponsavelFixoNoAcessoRestritoException;
use App\Pasta\Service\EscopoDePastas;
use App\Service\Tenant\TenantContext;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec acesso-restrito-e-compartilhar §5.1 item 6: o perfil com "acesso restrito às pastas sob responsabilidade" só
 * alcança as pastas em que é o responsável, em toda porta (pasta, Lista, CSV, contagem, Ctrl+K, setas), não entra
 * nas telas do escritório e não passa a pasta adiante. O perfil comum não muda nada.
 */
#[CoversClass(EscopoDePastas::class)]
#[CoversClass(BarreiraDoAcessoRestritoListener::class)]
final class AcessoRestritoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $tenant;
    private User $restrito;
    private User $comum;
    private Pasta $minha;
    private Pasta $alheia;
    private Pasta $semResponsavel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$primario, $this->tenant] = $this->criarEscritorio();
        $this->restrito = $this->criarColaborador($this->tenant, 'Rita Restrita', self::PERMISSOES);
        $this->comum    = $this->criarColaborador($this->tenant, 'Carlos Comum', self::PERMISSOES);
        $this->restringir($this->restrito);

        $this->minha          = $this->pastaDe($this->restrito, 'CLIENTE DA RITA');
        $this->alheia         = $this->pastaDe($primario, 'CLIENTE DO PRIMARIO');
        $this->semResponsavel = $this->pastaDe(null, 'CLIENTE SEM NINGUEM');
    }

    #[TestDox('restrito abre a própria pasta e recebe 404 na alheia e na sem responsável (sem "Pasta trancada")')]
    public function testPastaAlheiaDa404(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('GET', '/pasta/' . $this->minha->getId());
        self::assertResponseIsSuccessful();

        foreach ([$this->alheia, $this->semResponsavel] as $pasta) {
            $this->client->request('GET', '/pasta/' . $pasta->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('Pasta trancada', (string) $this->client->getResponse()->getContent());
        }
    }

    #[TestDox('na exportação da Lista (CSV) o restrito só vê a própria pasta, nem por número nem por cliente')]
    public function testCsvSoComAPropria(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $csv = $this->baixarCsv();
        self::assertStringContainsString((string) $this->minha->getNup(), $csv);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), $csv);
        self::assertStringNotContainsString('CLIENTE DO PRIMARIO', $csv);
        self::assertStringNotContainsString((string) $this->semResponsavel->getNup(), $csv);
    }

    #[TestDox('a contagem da Lista e a busca Ctrl+K do restrito não enxergam a pasta alheia')]
    public function testContagemEBuscaRapida(): void
    {
        $this->entrar($this->restrito, $this->tenant);
        $repositorio = $this->em()->getRepository(Pasta::class);
        $this->fixarEscritorioDe($this->restrito);
        self::assertSame(1, $repositorio->countByFilters([], $this->tenant, $this->restrito));

        $this->client->request('GET', '/expediente/busca-rapida?q=' . rawurlencode((string) $this->alheia->getNup()));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString((string) $this->alheia->getNup(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/expediente/busca-rapida?q=' . rawurlencode((string) $this->minha->getNup()));
        self::assertStringContainsString((string) $this->minha->getNup(), (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('as setas ‹ › e o "N de M" da pasta andam só pelas pastas do restrito')]
    public function testSetasDoAcervo(): void
    {
        $repositorio = $this->em()->getRepository(Pasta::class);
        $this->fixarEscritorioDe($this->restrito);

        self::assertSame(['anterior' => null, 'proxima' => null], $repositorio->vizinhasNoAcervo($this->minha, $this->restrito));
        self::assertSame(['posicao' => 1, 'total' => 1], $repositorio->posicaoNoAcervo($this->minha, $this->restrito));
        self::assertSame(3, $repositorio->posicaoNoAcervo($this->minha, $this->comum)['total'] ?? null);
    }

    #[TestDox('Painel dá "Fora do seu acesso" (403); a página inicial manda para a Lista; chamada de script recebe JSON')]
    public function testForaDoSeuAcesso(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('GET', '/dashboard');
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString(BarreiraDoAcessoRestritoListener::TITULO, (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Ir para minhas pastas', (string) $this->client->getResponse()->getContent());

        $this->client->xmlHttpRequest('GET', '/dashboard');
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('acesso restrito', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/inicio');
        self::assertResponseRedirects('/expediente');
    }

    #[TestDox('restrito não troca o responsável nem da própria pasta (403 com a frase da spec)')]
    public function testNaoTrocaResponsavel(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('PATCH', '/pasta/' . $this->minha->getId() . '/responsavel', [
            '_token' => 'TOKEN_pasta_responsavel_' . $this->minha->getId(),
            'responsavel_id' => (string) $this->comum->getId(),
        ]);

        self::assertResponseStatusCodeSame(403);
        $resposta = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ResponsavelFixoNoAcessoRestritoException::MENSAGEM, $resposta['erro'] ?? null);
        $this->em()->clear();
        self::assertSame($this->restrito->getId(), $this->em()->find(Pasta::class, $this->minha->getId())?->getResponsavel()?->getId());
    }

    #[TestDox('perfil comum com as mesmas permissões não muda nada: abre a pasta alheia, vê tudo no CSV e no Painel não há barreira')]
    public function testPerfilComumSemMudanca(): void
    {
        $this->entrar($this->comum, $this->tenant);

        $this->client->request('GET', '/pasta/' . $this->alheia->getId());
        self::assertResponseIsSuccessful();

        $csv = $this->baixarCsv();
        foreach ([$this->minha, $this->alheia, $this->semResponsavel] as $pasta) {
            self::assertStringContainsString((string) $pasta->getNup(), $csv);
        }

        $this->client->request('GET', '/dashboard');
        self::assertStringNotContainsString(BarreiraDoAcessoRestritoListener::TITULO, (string) $this->client->getResponse()->getContent());
    }

    private function baixarCsv(): string
    {
        ob_start();
        $this->client->request('GET', '/expediente/exportar.csv');
        $saida = (string) ob_get_clean();
        self::assertResponseIsSuccessful();

        return $saida . (string) $this->client->getResponse()->getContent();
    }

    /** As consultas fora de requisição leem o escritório do `TenantContext` (como no conector, sem sessão). */
    private function fixarEscritorioDe(User $user): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $this->tenant]);
        self::assertNotNull($vinculo);
        static::getContainer()->get(TenantContext::class)->fixarSemSessao($this->tenant, $vinculo);
    }

    private function restringir(User $user): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $this->tenant]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Prospectador');
        $this->em()->flush();
    }

    private function pastaDe(?User $responsavel, string $cliente): Pasta
    {
        $pasta = $this->criarPasta($this->tenant, $cliente);
        $pasta->setResponsavel($responsavel);
        $this->em()->flush();

        return $pasta;
    }
}
