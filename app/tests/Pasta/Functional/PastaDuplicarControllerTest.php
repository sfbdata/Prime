<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastaDuplicarController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\UseCase\DuplicarPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Duplicar pasta" (`pasta_duplicar`, POST /pasta/{id}/duplicar).
 *
 * Guarda: pasta do escritório (404), permissão de VER a origem (403), permissão de CRIAR pasta —
 * o módulo `pastas`, a mesma do `pasta_new` (403) — e CSRF (403). Lápide é recusada pelo
 * `PastaSomenteLeituraListener` antes de chegar ao controller.
 *
 * Cada recusa confere também que NENHUMA pasta nova nasceu: um 403 depois de gravar seria pior
 * que nenhum guarda.
 */
#[CoversClass(PastaDuplicarController::class)]
#[CoversClass(DuplicarPastaUseCase::class)]
#[Group('pasta')]
final class PastaDuplicarControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private int $seqCpf = 0;

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

    private function csrf(Pasta $pasta): string
    {
        return 'TOKEN_pasta_duplicar_' . $pasta->getId();
    }

    private function origem(Tenant $tenant, string $nup = '500'): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setNomeCliente('Maria das Graças');
        $pasta->setNomeAcao('Ação de Cobrança');
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function clientePF(Tenant $tenant, string $nome): ClientePF
    {
        $n = ++$this->seqCpf;

        $cliente = new ClientePF();
        $cliente->setNomeCompleto($nome);
        $cliente->setCpf(sprintf('98%09d', random_int(0, 99_999_999) * 10 + $n % 10));
        $cliente->setRg('77' . $n);
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $cliente->setEmail('duplicar_' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setTenant($tenant);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function itemChecklist(Pasta $pasta, Tenant $tenant, string $titulo, int $ordem, bool $concluido): void
    {
        $item = new PastaChecklistItem();
        $item->setPasta($pasta);
        $item->setTenant($tenant);
        $item->setTitulo($titulo);
        $item->setOrdem($ordem);
        $item->setConcluido($concluido);
        $this->em()->persist($item);
        $this->em()->flush();
    }

    /** EntityManager limpo e sem o filtro de tenant: lê o banco, não a memória da requisição. */
    private function emLimpo(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    /** @return list<Pasta> */
    private function pastasDo(Tenant $tenant): array
    {
        return $this->emLimpo()->getRepository(Pasta::class)->findBy(['tenant' => $tenant->getId()], ['id' => 'ASC']);
    }

    private function postar(object $client, Pasta $pasta, ?string $token = null): void
    {
        $client->request('POST', '/pasta/' . $pasta->getId() . '/duplicar', [
            '_token' => $token ?? $this->csrf($pasta),
        ]);
    }

    // =========================================================================
    // Caminho feliz
    // =========================================================================

    #[TestDox('POST cria a cópia com número novo e leva clientes (mesmo principal), ação, responsável, prioridade e checklist pendente')]
    public function testDuplicaECopiaOQueODesenhoManda(): void
    {
        $client          = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarAdmin();

        $origem   = $this->origem($tenant);
        $primeiro = $this->clientePF($tenant, 'Primeiro Cliente');
        $segundo  = $this->clientePF($tenant, 'Segundo Cliente');
        $origem->addCliente($primeiro);
        $origem->addCliente($segundo);
        $origem->definirClientePrincipal($segundo);
        $origem->setResponsavel($user);
        $origem->setPrioridade(PrioridadePasta::Prioridade);
        $origem->setValorCausa('12345.67');
        $this->em()->flush();
        $this->vincular($origem, $this->criarProcesso($tenant, '07011345720258070007'));
        $this->itemChecklist($origem, $tenant, 'PROCURAÇÃO', 1, true);
        $this->itemChecklist($origem, $tenant, 'RG', 2, false);

        $origemId = (int) $origem->getId();

        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $origem);

        self::assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/pasta/\d+$#', $location);
        preg_match('#/pasta/(\d+)$#', $location, $m);
        $novaId = (int) $m[1];
        self::assertNotSame($origemId, $novaId, 'redireciona para a pasta NOVA');

        $pastas = $this->pastasDo($tenant);
        self::assertCount(2, $pastas);

        $em     = $this->emLimpo();
        $nova   = $em->find(Pasta::class, $novaId);
        $origem = $em->find(Pasta::class, $origemId);
        self::assertNotNull($nova);
        self::assertNotNull($origem);

        // Número pelo mesmo caminho da criação: MAX(prefixo) + 1 do escritório.
        self::assertSame('501', $nova->getNup());
        self::assertSame($tenant->getId(), $nova->getTenant()->getId());
        self::assertSame($user->getId(), $nova->getCriadoPor()?->getId());

        // O que vai.
        self::assertSame($origem->getNomeCliente(), $nova->getNomeCliente());
        self::assertSame($origem->getNomeAcao(), $nova->getNomeAcao());
        self::assertSame($user->getId(), $nova->getResponsavel()?->getId());
        self::assertSame(PrioridadePasta::Prioridade, $nova->getPrioridade());
        $idsClientes = array_map(static fn ($c) => $c->getId(), $nova->getClientes()->toArray());
        sort($idsClientes);
        self::assertSame([$primeiro->getId(), $segundo->getId()], $idsClientes);
        self::assertSame($segundo->getId(), $nova->getClientePrincipal()?->getId(), 'o principal é o mesmo da origem');

        $itens = $em->getRepository(PastaChecklistItem::class)->findBy(['pasta' => $novaId], ['ordem' => 'ASC']);
        self::assertSame(['PROCURAÇÃO', 'RG'], array_map(static fn (PastaChecklistItem $i) => $i->getTitulo(), $itens));
        foreach ($itens as $item) {
            self::assertFalse($item->isConcluido(), 'o checklist da cópia nasce todo pendente');
        }

        // O que não vai.
        self::assertCount(0, $nova->getPastaProcessos(), 'processo vinculado não é copiado');
        self::assertCount(0, $nova->getDocumentos());
        self::assertNull($nova->getValorCausa(), 'financeiro não é copiado');

        // A origem fica intacta.
        self::assertSame('500', $origem->getNup());
        self::assertCount(1, $origem->getPastaProcessos());
        $itensOrigem = $em->getRepository(PastaChecklistItem::class)->findBy(['pasta' => $origemId], ['ordem' => 'ASC']);
        self::assertTrue($itensOrigem[0]->isConcluido());

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Pasta 501 criada como cópia da pasta 500');
    }

    // =========================================================================
    // Guarda
    // =========================================================================

    #[TestDox('CSRF inválido é 403 e não cria pasta')]
    public function testCsrfInvalido(): void
    {
        $client          = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarAdmin();
        $origem          = $this->origem($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $origem, 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->pastasDo($tenant));
    }

    #[TestDox('pasta de outro escritório é 404 e não cria pasta em nenhum dos dois')]
    public function testOutroEscritorio404(): void
    {
        $client          = static::createClient();
        // Duas requisições no mesmo teste: sem reboot, o storage de CSRF trocado continua valendo.
        $client->disableReboot();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarAdmin();
        $alheio          = $this->criarTenant();
        $origemAlheia    = $this->origem($alheio, '900');

        // Controle: a pasta do PRÓPRIO escritório duplica — o 404 abaixo é do isolamento, não
        // da rota.
        $propria = $this->origem($tenant, '10');

        // Super admin de propósito: ele passa por bypass em TODA permissão, então o 404 só pode
        // vir da conferência explícita do escritório da pasta.
        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $origemAlheia);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->pastasDo($alheio));
        self::assertCount(1, $this->pastasDo($tenant));

        $this->postar($client, $propria);
        self::assertResponseRedirects();
        self::assertCount(2, $this->pastasDo($tenant));
    }

    #[TestDox('quem VÊ a pasta mas não pode criar pasta (sem o módulo pastas) leva 403 e nada é criado')]
    public function testSemPermissaoDeCriar403(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioSemPermissaoDoModulo($tenant); // resources.pasta.view, sem modules.pastas.view
        $origem  = $this->origem($tenant);

        $this->logarComTenant($client, $usuario, $tenant);
        $this->postar($client, $origem);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->pastasDo($tenant));
    }

    #[TestDox('quem não pode VER a pasta de origem leva 403 e nada é criado')]
    public function testSemPermissaoDeVer403(): void
    {
        $client  = static::createClient();
        $this->instalarCsrfStorage();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $origem  = $this->origem($tenant);

        $this->logarComTenant($client, $usuario, $tenant);
        $this->postar($client, $origem);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->pastasDo($tenant));
    }

    #[TestDox('pasta excluída (lápide) não duplica: volta para a própria pasta com aviso e nada é criado')]
    public function testLapideRecusada(): void
    {
        $client          = static::createClient();
        $this->instalarCsrfStorage();
        [$user, $tenant] = $this->criarAdmin();
        $origem          = $this->origem($tenant);
        $origem->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $this->postar($client, $origem);

        self::assertResponseRedirects('/pasta/' . $origem->getId());
        self::assertCount(1, $this->pastasDo($tenant));
    }

    #[TestDox('GET na rota de duplicar não existe (só POST)')]
    public function testGetNaoDuplica(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $origem          = $this->origem($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $origem->getId() . '/duplicar');

        self::assertResponseStatusCodeSame(405);
        self::assertCount(1, $this->pastasDo($tenant));
    }

    // =========================================================================
    // Tela
    // =========================================================================

    #[TestDox('o item Duplicar pasta é filho direto do ⋮, logo depois do Histórico, e é um POST com CSRF e confirmação do desenho')]
    public function testItemNoMenu(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $origem          = $this->origem($tenant, '3010');

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $origem->getId());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#psMenuAcoes > form.js-duplicar-pasta[action$="/pasta/' . $origem->getId() . '/duplicar"]');
        self::assertCount(1, $form, 'o form é filho direto do menu ⋮');
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('form > button.ps-pop-item[type="submit"]'));
        self::assertSame('Duplicar pasta', trim($form->filter('button.ps-pop-item > span')->text()));
        self::assertNotSame('', (string) $form->filter('input[name="_token"]')->attr('value'));
        self::assertStringContainsString(
            'A cópia leva cliente, ação, responsável e checklist; documentos, metas e financeiro não são copiados.',
            (string) $form->attr('onsubmit'),
        );

        $itens = $crawler->filter('#psMenuAcoes .ps-pop-item')->each(fn ($n) => trim($n->filter('span')->first()->text()));
        $pos   = array_search('Duplicar pasta', $itens, true);
        self::assertNotFalse($pos);
        self::assertSame('Histórico', $itens[$pos - 1], 'na ordem do desenho: depois do Histórico');
        self::assertSame('Vincular processo', $itens[$pos + 1]);
    }

    #[TestDox('sem permissão de criar pasta, o ⋮ não mostra Duplicar pasta')]
    public function testItemAusenteSemPermissaoDeCriar(): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $usuario = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $origem  = $this->origem($tenant);

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $origem->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#psMenuAcoes'), 'o menu existe — o que falta é só o item');
        self::assertCount(0, $crawler->filter('#psMenuAcoes form.js-duplicar-pasta'));
    }

    #[TestDox('pasta excluída não tem o ⋮ — e portanto não oferece Duplicar')]
    public function testLapideSemItem(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $origem          = $this->origem($tenant);
        $origem->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $origem->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('form.js-duplicar-pasta'));
    }
}
