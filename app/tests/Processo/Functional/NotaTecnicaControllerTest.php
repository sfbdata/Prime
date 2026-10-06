<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Processo\Controller\NotaTecnicaController;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Criar, editar e excluir nota técnica por XHR — e, sobretudo, o que NÃO pode acontecer:
 * processo de outro escritório pelo id (IDOR), pasta alheia como porta, publicação de outro
 * processo como gancho, nota de outro escritório/processo pelo id, e quem não é o autor.
 *
 * Os usuários "admin" são ROLE_SUPER_ADMIN (bypass do PermissionChecker): nos testes de
 * isolamento isso prova que quem fecha o vazamento é a busca tenant-safe, não a permissão.
 */
#[CoversClass(NotaTecnicaController::class)]
#[Group('processo')]
final class NotaTecnicaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private const NUMERO       = '07011345720258070007';
    private const OUTRO_NUMERO = '07099999999999999999';

    private function criarNota(Processo $processo, User $autor, Tenant $tenant, ?\DateTimeImmutable $criadaEm = null, ?PublicacaoDjen $pub = null): NotaTecnica
    {
        $nota = new NotaTecnica($tenant, $processo, $autor, '<p>Nota original</p>', $pub);
        if ($criadaEm !== null) {
            (new \ReflectionProperty(NotaTecnica::class, 'criadaEm'))->setValue($nota, $criadaEm);
        }
        $this->em()->persist($nota);
        $this->em()->flush();

        return $nota;
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

    private function csrf(string $tokenId): string
    {
        return 'TOKEN_' . $tokenId;
    }

    /** @return array<string, mixed> */
    private function json(object $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

    // ── Sem autenticação ─────────────────────────────────────────────────────

    #[TestDox('POST sem autenticação redireciona para o login')]
    public function testCriarSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/processos/1/nota-tecnica');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    // ── Criar ────────────────────────────────────────────────────────────────

    #[TestDox('criar pela pasta devolve 201 com o cartão pronto e grava no escritório e no processo')]
    public function testCriarPelaPastaRetorna201(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'pasta_id' => $pasta->getId(),
            'conteudo' => '<p><strong>Prazo:</strong> 15 dias para contestar</p>',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json($client);
        self::assertSame('<p><strong>Prazo:</strong> 15 dias para contestar</p>', $data['conteudo']);
        self::assertStringContainsString('<strong>Prazo:</strong>', (string) $data['conteudoHtml']);
        self::assertSame('Admin Push', $data['autorNome']);
        self::assertNull($data['publicacaoId']);
        self::assertNotEmpty($data['csrfEditar']);
        self::assertNotEmpty($data['csrfExcluir']);
        self::assertSame("/processos/{$processo->getId()}/nota-tecnica/{$data['id']}/editar", $data['urlEditar']);
        self::assertSame("/processos/{$processo->getId()}/nota-tecnica/{$data['id']}/excluir", $data['urlExcluir']);
        // O cartão vem do MESMO partial do Twig.
        self::assertStringContainsString('id="nota-tecnica-' . $data['id'] . '"', (string) $data['html']);
        self::assertStringContainsString('Nota técnica', (string) $data['html']);
        self::assertStringContainsString('· 15 min', (string) $data['html']);

        $this->em()->clear();
        $nota = $this->em()->find(NotaTecnica::class, $data['id']);
        self::assertNotNull($nota);
        self::assertSame($tenant->getId(), $nota->getTenant()?->getId());
        self::assertSame($processo->getId(), $nota->getProcesso()->getId());
        self::assertSame($user->getId(), $nota->getAutor()?->getId());
    }

    #[TestDox('criar direto pelo processo (sem pasta_id) também vale para quem pode editá-lo')]
    public function testCriarPeloProcessoSemPastaRetorna201(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'conteudo' => '<p>Direto no processo</p>',
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('CSRF inválido devolve 403 e não grava')]
    public function testCriarComCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => 'token_invalido',
            'conteudo' => '<p>Nota</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em()->getRepository(NotaTecnica::class)->findBy(['processo' => $processo]));
    }

    #[TestDox('vazio e parágrafo vazio do editor devolvem 422')]
    public function testCriarVazioRetorna422(): void
    {
        $client = static::createClient();
        // Várias requisições no mesmo teste: sem isto o kernel reinicia antes da segunda e o
        // storage de CSRF previsível some — o 403 que viria seria "token inválido", não o 422.
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        foreach (['', '   ', '<p><br></p>'] as $conteudo) {
            $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
                '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
                'conteudo' => $conteudo,
            ]);
            self::assertResponseStatusCodeSame(422, var_export($conteudo, true));
        }
    }

    #[TestDox('script no conteúdo grava LIMPO — a barreira é o servidor, não o editor')]
    public function testCriarComScriptGravaLimpo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'conteudo' => '<p onclick="roubar()">Combinado</p><script>alert(document.cookie)</script>',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = $this->json($client);
        self::assertStringNotContainsStringIgnoringCase('<script', (string) $data['conteudo']);
        self::assertStringNotContainsStringIgnoringCase('onclick', (string) $data['conteudo']);
        self::assertStringNotContainsStringIgnoringCase('<script', (string) $data['html']);
        self::assertStringContainsString('Combinado', (string) $data['conteudo']);

        $this->em()->clear();
        $nota = $this->em()->find(NotaTecnica::class, $data['id']);
        self::assertStringNotContainsStringIgnoringCase('<script', (string) $nota?->getConteudo());
    }

    #[TestDox('IDOR: processo de OUTRO escritório pelo id devolve 404 e não grava — mesmo para super admin')]
    public function testCriarEmProcessoDeOutroEscritorioRetorna404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $idB               = (int) $processoB->getId();
        $this->em()->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('POST', "/processos/{$idB}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $idB),
            'conteudo' => '<p>Invasão</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
        $this->desligarFiltroDeTenant();
        self::assertCount(0, $this->em()->getRepository(NotaTecnica::class)->findBy(['processo' => $idB]));
    }

    #[TestDox('pasta de OUTRO escritório como porta devolve 404')]
    public function testCriarComPastaDeOutroEscritorioRetorna404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $processoA         = $this->criarProcesso($tenantA, self::NUMERO);
        $pastaB            = $this->criarPasta($tenantB);
        $this->vincular($pastaB, $this->criarProcesso($tenantB, self::NUMERO));
        $this->em()->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('POST', "/processos/{$processoA->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processoA->getId()),
            'pasta_id' => $pastaB->getId(),
            'conteudo' => '<p>Pela pasta alheia</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('pasta do MESMO escritório que não vincula o processo devolve 404')]
    public function testCriarComPastaQueNaoVinculaOProcessoRetorna404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $outraPasta      = $this->criarPasta($tenant);
        $this->vincular($outraPasta, $this->criarProcesso($tenant, self::OUTRO_NUMERO));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'pasta_id' => $outraPasta->getId(),
            'conteudo' => '<p>Pasta errada</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('quem não pode editar a pasta recebe 403 pela pasta; e 403 direto no processo')]
    public function testSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // duas requisições: ver testCriarVazioRetorna422
        $tenant   = $this->criarTenant();
        $user     = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta    = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'pasta_id' => $pasta->getId(),
            'conteudo' => '<p>Sem permissão</p>',
        ]);
        self::assertResponseStatusCodeSame(403, 'pela pasta');

        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'   => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'conteudo' => '<p>Sem permissão</p>',
        ]);
        self::assertResponseStatusCodeSame(403, 'direto no processo');
        // O 403 tem de ser o de PERMISSÃO, não o de CSRF.
        self::assertSame('Sem permissão.', $this->json($client)['erro']);
    }

    #[TestDox('publicação do processo (com FK ou só pelo número) vira o gancho da nota')]
    public function testCriarComPublicacaoDoProcessoRetorna201(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // duas requisições: ver testCriarVazioRetorna422
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);
        $comFk  = $this->criarPublicacao($tenant, '40000001', self::NUMERO, '2026-08-20', $processo);
        $semFk  = $this->criarPublicacao($tenant, '40000002', self::NUMERO, '2026-08-21');

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        foreach ([$comFk, $semFk] as $pub) {
            $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
                '_token'        => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
                'pasta_id'      => $pasta->getId(),
                'publicacao_id' => $pub->getId(),
                'conteudo'      => '<p>Sobre a intimação</p>',
            ]);
            self::assertResponseStatusCodeSame(201, 'publicação ' . $pub->getDjenId());
            $data = $this->json($client);
            self::assertSame($pub->getId(), $data['publicacaoId']);
            self::assertStringStartsWith('Intimação · ', (string) $data['movimentacaoRotulo']);
        }
    }

    #[TestDox('publicação de OUTRO processo não vira gancho: 404 e nada gravado')]
    public function testCriarComPublicacaoDeOutroProcessoRetorna404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $outro           = $this->criarProcesso($tenant, self::OUTRO_NUMERO);
        $pubDoOutro      = $this->criarPublicacao($tenant, '40000010', self::OUTRO_NUMERO, '2026-08-20', $outro);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica", [
            '_token'        => $this->csrf('processo_nota_tecnica_' . $processo->getId()),
            'publicacao_id' => $pubDoOutro->getId(),
            'conteudo'      => '<p>Gancho errado</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $this->em()->getRepository(NotaTecnica::class)->findBy(['processo' => $processo]));
    }

    #[TestDox('publicação de OUTRO escritório, mesmo com o mesmo número, devolve 404')]
    public function testCriarComPublicacaoDeOutroEscritorioRetorna404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $processoA         = $this->criarProcesso($tenantA, self::NUMERO);
        $pubB              = $this->criarPublicacao($tenantB, '40000020', self::NUMERO, '2026-08-20');
        $this->em()->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('POST', "/processos/{$processoA->getId()}/nota-tecnica", [
            '_token'        => $this->csrf('processo_nota_tecnica_' . $processoA->getId()),
            'publicacao_id' => $pubB->getId(),
            'conteudo'      => '<p>Gancho alheio</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Editar ───────────────────────────────────────────────────────────────

    #[TestDox('o autor edita dentro dos 15 min: 200, conteúdo novo e editadaEm')]
    public function testAutorEditaDentroDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $user, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/editar", [
            '_token'   => $this->csrf('processo_nota_tecnica_editar_' . $nota->getId()),
            'conteudo' => '<p>Corrigido</p><img src=x onerror="alert(1)">',
        ]);

        self::assertResponseIsSuccessful();
        $data = $this->json($client);
        self::assertStringContainsString('Corrigido', (string) $data['conteudo']);
        self::assertStringNotContainsStringIgnoringCase('onerror', (string) $data['conteudo']);
        self::assertNotNull($data['editadaEm']);
    }

    #[TestDox('quem não é o autor recebe 403 ao editar')]
    public function testNaoAutorNaoEdita(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $outro, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/editar", [
            '_token'   => $this->csrf('processo_nota_tecnica_editar_' . $nota->getId()),
            'conteudo' => '<p>Tentando</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('passados os 15 min (20 min), o autor recebe 403 ao editar e ao excluir')]
    public function testForaDaJanelaRetorna403(): void
    {
        $client = static::createClient();
        $client->disableReboot(); // duas requisições: ver testCriarVazioRetorna422
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $user, $tenant, new \DateTimeImmutable('-20 minutes'));

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/editar", [
            '_token'   => $this->csrf('processo_nota_tecnica_editar_' . $nota->getId()),
            'conteudo' => '<p>Tarde</p>',
        ]);
        self::assertResponseStatusCodeSame(403, 'editar');

        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/excluir", [
            '_token' => $this->csrf('processo_nota_tecnica_excluir_' . $nota->getId()),
        ]);
        self::assertResponseStatusCodeSame(403, 'excluir');
        // O 403 tem de ser o da JANELA, não o de CSRF: a mensagem distingue os dois.
        self::assertStringContainsString('não pode ser excluída', (string) $this->json($client)['erro']);
    }

    #[TestDox('editar com CSRF inválido devolve 403')]
    public function testEditarCsrfInvalidoRetorna403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $user, $tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/editar", [
            '_token'   => 'token_invalido',
            'conteudo' => '<p>Qualquer</p>',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('IDOR: nota de OUTRO escritório pelo id devolve 404 ao editar e ao excluir, e a nota continua lá')]
    public function testNotaDeOutroEscritorioRetorna404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $processoA         = $this->criarProcesso($tenantA, self::NUMERO);
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $notaB             = $this->criarNota($processoB, $userB, $tenantB);
        $notaBId           = (int) $notaB->getId();
        $this->em()->clear();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('POST', "/processos/{$processoA->getId()}/nota-tecnica/{$notaBId}/editar", [
            '_token'   => $this->csrf('processo_nota_tecnica_editar_' . $notaBId),
            'conteudo' => '<p>Invasão</p>',
        ]);
        self::assertResponseStatusCodeSame(404, 'editar');

        $client->request('POST', "/processos/{$processoA->getId()}/nota-tecnica/{$notaBId}/excluir", [
            '_token' => $this->csrf('processo_nota_tecnica_excluir_' . $notaBId),
        ]);
        self::assertResponseStatusCodeSame(404, 'excluir');

        $this->desligarFiltroDeTenant();
        $this->em()->clear();
        $aindaLa = $this->em()->find(NotaTecnica::class, $notaBId);
        self::assertNotNull($aindaLa, 'a nota de B deve continuar existindo');
        self::assertSame('<p>Nota original</p>', $aindaLa->getConteudo());
    }

    #[TestDox('nota de OUTRO processo do mesmo escritório pelo id devolve 404')]
    public function testNotaDeOutroProcessoRetorna404(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $outro           = $this->criarProcesso($tenant, self::OUTRO_NUMERO);
        $notaDoOutro     = $this->criarNota($outro, $user, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$notaDoOutro->getId()}/editar", [
            '_token'   => $this->csrf('processo_nota_tecnica_editar_' . $notaDoOutro->getId()),
            'conteudo' => '<p>Processo errado</p>',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    // ── Excluir ──────────────────────────────────────────────────────────────

    #[TestDox('o autor exclui dentro dos 15 min e a nota some do banco')]
    public function testAutorExclui(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $user, $tenant);
        $notaId          = (int) $nota->getId();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$notaId}/excluir", [
            '_token' => $this->csrf('processo_nota_tecnica_excluir_' . $notaId),
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['sucesso']);
        $this->em()->clear();
        self::assertNull($this->em()->find(NotaTecnica::class, $notaId));
    }

    #[TestDox('quem não é o autor recebe 403 ao excluir')]
    public function testNaoAutorNaoExclui(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $outro           = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $nota            = $this->criarNota($processo, $outro, $tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $client->request('POST', "/processos/{$processo->getId()}/nota-tecnica/{$nota->getId()}/excluir", [
            '_token' => $this->csrf('processo_nota_tecnica_excluir_' . $nota->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * O TenantFilter continua ligado com o escritório da sessão depois da request; sem desligá-lo,
     * uma consulta devolveria vazio por FILTRO e o teste "não gravou / não apagou" provaria outra coisa.
     */
    private function desligarFiltroDeTenant(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
    }
}
