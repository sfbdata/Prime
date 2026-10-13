<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Enum\Agente;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * IA-011: "Marcar como não lido". O estado de lida já existe (`lida_em`, `lida_por`); a ação o
 * zera. A opção alterna no menu: não lida → "Marcar como lida"; lida → "Marcar como não lido".
 */
#[CoversClass(AnalisePushController::class)]
final class MarcarNaoLidaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        return $client;
    }

    private function postar(KernelBrowser $client, int $pastaId, int $analiseId, string $acao, bool $comToken = true): void
    {
        $client->request(
            'POST',
            "/pasta/{$pastaId}/ia/analises/{$analiseId}/{$acao}",
            $comToken ? ['_token' => 'TOKEN_inteligencia_analise_' . $analiseId] : [],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function lida(int $analiseId): bool
    {
        return $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND lida_em IS NOT NULL AND lida_por_id IS NOT NULL',
            ['id' => $analiseId],
        ) === 1;
    }

    #[TestDox('lida → "não lido" zera lida_em/lida_por; o menu volta a oferecer "Marcar como lida"; e o ciclo repete')]
    public function testAlternaLidoENaoLido(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $user, $pasta);
        $this->concluirAnalise($analise);
        $id = (int) $analise->getId();
        $this->logarComTenant($client, $user, $tenant);

        $this->postar($client, (int) $pasta->getId(), $id, 'lida');
        self::assertTrue($this->lida($id));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        $menu = $crawler->filter('.ps-ia-cartao[data-ia-analise="' . $id . '"] .ps-ia-menu');
        self::assertSame(1, $menu->filter('form[data-ia-acao="nao-lida"]')->count());
        self::assertSame(0, $menu->filter('form[data-ia-acao="lida"]')->count());
        self::assertStringContainsString('Marcar como não lido', $menu->text());
        self::assertSame("/pasta/{$pasta->getId()}/ia/analises/{$id}/nao-lida", $menu->filter('form[data-ia-acao="nao-lida"]')->attr('action'));

        $this->postar($client, (int) $pasta->getId(), $id, 'nao-lida');
        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['lida']);
        self::assertFalse($this->lida($id));
        self::assertSame(1, $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE id = :id AND lida_em IS NULL AND lida_por_id IS NULL', ['id' => $id]));

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/push/analises");
        $cartao = $crawler->filter('.ps-ia-cartao[data-ia-analise="' . $id . '"]');
        self::assertSame(0, $cartao->filter('.ps-ia-lido')->count(), 'o selo "lido" some');
        self::assertSame(1, $cartao->filter('.ps-ia-menu form[data-ia-acao="lida"]')->count());
        self::assertSame(0, $cartao->filter('form[data-ia-acao="nao-lida"]')->count());

        // E dá para ler de novo.
        $this->postar($client, (int) $pasta->getId(), $id, 'lida');
        self::assertTrue($this->lida($id));
    }

    #[TestDox('no cartão do agente, a lida também oferece "Marcar como não lido"')]
    public function testNoCartaoDoAgente(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($analise, 'Resumo.', [], null, 'Texto.');
        $analise->marcarLida($user);
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/gestor/analises");

        self::assertSame(1, $crawler->filter('.ia-cartao[data-ia-analise="' . $analise->getId() . '"] .ia-menu form[data-ia-acao="nao-lida"]')->count());
    }

    #[TestDox('outro escritório (pasta ou análise) e pasta irmã → 404 e a leitura NÃO é desfeita')]
    public function testIsolamento(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA] = $this->criarPastaComPublicacao($tenantA);
        [$pastaA2] = $this->criarPastaComPublicacao($tenantA, '07099999999999999999');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnalisePendente($tenantB, $userB, $pastaB);
        $this->concluirAnalise($analiseB);
        $analiseB->marcarLida($userB);
        $analiseA2 = $this->criarAnalisePendente($tenantA, $userA, $pastaA2);
        $this->concluirAnalise($analiseA2);
        $analiseA2->marcarLida($userA);
        $this->em()->flush();
        $this->logarComTenant($client, $userA, $tenantA);

        $this->postar($client, (int) $pastaB->getId(), (int) $analiseB->getId(), 'nao-lida');
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, (int) $pastaA->getId(), (int) $analiseB->getId(), 'nao-lida');
        self::assertResponseStatusCodeSame(404);
        $this->postar($client, (int) $pastaA->getId(), (int) $analiseA2->getId(), 'nao-lida');
        self::assertResponseStatusCodeSame(404);

        self::assertTrue($this->lida((int) $analiseB->getId()), 'a de outro escritório continua lida');
        self::assertTrue($this->lida((int) $analiseA2->getId()), 'a da pasta irmã continua lida');
    }

    #[TestDox('sem CSRF → 403; sem modules.inteligencia.view → 403; a leitura fica')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($analise);
        $analise->marcarLida($admin);
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $this->postar($client, (int) $pasta->getId(), (int) $analise->getId(), 'nao-lida', comToken: false);
        self::assertResponseStatusCodeSame(403);

        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $semModulo, $tenant);
        $this->postar($client, (int) $pasta->getId(), (int) $analise->getId(), 'nao-lida');
        self::assertResponseStatusCodeSame(403);

        self::assertTrue($this->lida((int) $analise->getId()));
    }
}
