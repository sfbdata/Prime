<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Entity\Auth\User;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Controller\AnalisePushController;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Preferencia\CatalogoDePreferenciasDaInteligencia;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * IA-008b: colega do MESMO escritório, com o módulo da IA, mas SEM acesso à pasta (nem
 * `resources.pasta.view` no papel, nem `ResourceAccess` na pasta). As rotas por id da análise e a
 * trilha de auditoria respondem 403 e nada é gravado.
 *
 * Cada teste termina com a PROVA DO GUARDA: o mesmo usuário, com o mesmo token e o mesmo módulo,
 * ganha `ResourceAccess` de leitura nesta pasta e a mesma requisição passa. Assim o 403 de cima
 * é do guarda da pasta — e não do CSRF, do módulo ou de uma análise que não existe.
 */
#[CoversClass(AnalisePushController::class)]
final class SemAcessoAPastaControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private const SIGILOSO = 'CONTEUDO-SIGILOSO-DA-ANALISE';

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

    /**
     * O cenário: pasta do escritório com uma análise concluída (pedida pelo admin) e um colega com
     * o módulo da IA (e o de Pastas), sem nenhum acesso a ESTA pasta.
     *
     * @return array{Tenant, Pasta, AnaliseDeInteligencia, User}
     */
    private function cenario(KernelBrowser $client): array
    {
        [$admin, $tenant] = $this->criarAdmin();
        $this->ligarIaNoTenant($tenant, $admin);
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $admin, $pasta, Agente::Gestor);
        $this->concluirAnalise($analise, self::SIGILOSO . ' resumo.', [], null, self::SIGILOSO . ' texto.');
        $colega = $this->criarUsuarioComPermissoes($tenant, ['modules.inteligencia.view', 'modules.pastas.view'], 'sem_acesso');
        $this->logarComTenant($client, $colega, $tenant);

        return [$tenant, $pasta, $analise, $colega];
    }

    /** A prova do guarda: só o acesso à pasta muda. */
    private function darAcessoDeLeitura(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = new ResourceAccess();
        $acesso->setUser($user);
        $acesso->setTenant($tenant);
        $acesso->setResourceType(ResourceAccess::RESOURCE_PASTA);
        $acesso->setResourceId((int) $pasta->getId());
        $acesso->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function postar(KernelBrowser $client, Pasta $pasta, AnaliseDeInteligencia $analise, string $acao, array $corpo = []): void
    {
        $client->request(
            'POST',
            "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}/{$acao}",
            $corpo + ['_token' => 'TOKEN_inteligencia_analise_' . $analise->getId()],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    private function preferenciasDeRecolhida(): int
    {
        return $this->contarNoBanco(
            'SELECT count(*) FROM preferencia_usuario WHERE chave = :c',
            ['c' => CatalogoDePreferenciasDaInteligencia::PUSH_RECOLHIDAS],
        );
    }

    private function lida(AnaliseDeInteligencia $analise): bool
    {
        return $this->contarNoBanco(
            'SELECT count(*) FROM inteligencia_analise WHERE id = :id AND lida_em IS NOT NULL AND lida_por_id IS NOT NULL',
            ['id' => $analise->getId()],
        ) === 1;
    }

    #[TestDox('inteligencia_analise_txt: sem acesso à pasta → 403 e o texto do modelo não sai; com ResourceAccess, o mesmo GET baixa')]
    public function testBaixarTxt(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $analise, $colega] = $this->cenario($client);
        $url = "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}.txt";

        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(self::SIGILOSO, (string) $client->getResponse()->getContent());
        self::assertFalse($client->getResponse()->headers->has('Content-Disposition'), 'nenhum anexo é oferecido');

        $this->darAcessoDeLeitura($colega, $tenant, $pasta);
        $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::SIGILOSO, (string) $client->getResponse()->getContent());
    }

    #[TestDox('inteligencia_auditoria_da_pasta: sem acesso à pasta → 403 e a trilha não é montada; com ResourceAccess, a mesma trilha aparece')]
    public function testAuditoria(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, , $colega] = $this->cenario($client);
        $url = "/pasta/{$pasta->getId()}/ia/auditoria";

        $client->request('GET', $url);

        self::assertResponseStatusCodeSame(403);
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-ia-auditoria', $html);
        self::assertStringNotContainsString('Registro de auditoria', $html);

        $this->darAcessoDeLeitura($colega, $tenant, $pasta);
        $crawler = $client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSame(
            'Registro de auditoria (1)',
            trim($crawler->filter('footer.ia-drawer-audit[data-ia-auditoria] .ia-audit-botao > .ia-audit-rotulo')->text()),
        );
    }

    #[TestDox('inteligencia_analise_recolher: sem acesso à pasta → 403 e NADA em preferencia_usuario; com ResourceAccess, grava')]
    public function testRecolher(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $analise, $colega] = $this->cenario($client);

        $this->postar($client, $pasta, $analise, 'recolher', ['recolhida' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->preferenciasDeRecolhida(), 'a recusa não grava preferência');

        $this->darAcessoDeLeitura($colega, $tenant, $pasta);
        $this->postar($client, $pasta, $analise, 'recolher', ['recolhida' => '1']);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->preferenciasDeRecolhida());
    }

    #[TestDox('inteligencia_analise_nao_lida: sem acesso à pasta → 403 e a leitura NÃO é desfeita; com ResourceAccess, desfaz')]
    public function testNaoLida(): void
    {
        $client = $this->cliente();
        [$tenant, $pasta, $analise, $colega] = $this->cenario($client);
        $admin = $analise->getSolicitante();
        self::assertNotNull($admin);
        $analise->marcarLida($admin);
        $this->em()->flush();
        self::assertTrue($this->lida($analise));

        $this->postar($client, $pasta, $analise, 'nao-lida');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->lida($analise), 'a recusa não zera lida_em/lida_por');

        $this->darAcessoDeLeitura($colega, $tenant, $pasta);
        $this->postar($client, $pasta, $analise, 'nao-lida');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->lida($analise));
    }
}
