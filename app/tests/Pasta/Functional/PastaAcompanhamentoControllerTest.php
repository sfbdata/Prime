<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Pasta\Controller\PastaAcompanhamentoController;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Twig\PastaAcompanhamentoExtension;
use App\Pasta\UseCase\AlternarAcompanhamentoDaPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Acompanhar alterações" (`pasta_acompanhamento_alternar`, menu ⋮ da pasta, desenho "02 - EXPEDIENTES
 * - VS 18.10.26" D:1240 e D:4493-4499).
 *
 * Guardas: pasta do escritório (404), CSRF (403), permissão de VER a pasta (403), pasta excluída recusada
 * pelo PastaSomenteLeituraListener e o cadeado: em Visualizar a preferência pessoal passa; em Oculto, não.
 * E a tela: o item na seção Preferências, antes do favorito, ligado por padrão.
 */
#[CoversClass(PastaAcompanhamentoController::class)]
#[CoversClass(AlternarAcompanhamentoDaPastaUseCase::class)]
#[CoversClass(PastaAcompanhamentoExtension::class)]
#[Group('pasta')]
final class PastaAcompanhamentoControllerTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
    }

    private function alternar(Pasta $pasta, ?string $token = null): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/acompanhar', [
            '_token' => $token ?? 'TOKEN_pasta_acompanhar_' . $pasta->getId(),
        ]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function desligadosNoBanco(Pasta $pasta, ?User $usuario = null): int
    {
        $sql    = 'SELECT COUNT(*) FROM pasta_acompanhamento_desligado WHERE pasta_id = :pasta';
        $params = ['pasta' => $pasta->getId()];
        if ($usuario !== null) {
            $sql .= ' AND user_id = :usuario';
            $params['usuario'] = $usuario->getId();
        }

        return (int) $this->em()->getConnection()->fetchOne($sql, $params);
    }

    #[TestDox('Padrão ligado: o primeiro POST desliga (acompanha: false, grava a exceção com escritório e usuário); o segundo religa')]
    public function testDesligaEReliga(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana        = $this->criarColaborador($tenant);
        $pasta      = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);

        $this->alternar($pasta);
        self::assertResponseIsSuccessful();
        self::assertSame(['acompanha' => false], $this->json());
        $linha = $this->em()->getConnection()->fetchAssociative(
            'SELECT tenant_id, user_id FROM pasta_acompanhamento_desligado WHERE pasta_id = :pasta',
            ['pasta' => $pasta->getId()],
        );
        self::assertSame(
            ['tenant_id' => $tenant->getId(), 'user_id' => $ana->getId()],
            ['tenant_id' => (int) $linha['tenant_id'], 'user_id' => (int) $linha['user_id']],
        );

        $this->alternar($pasta);
        self::assertResponseIsSuccessful();
        self::assertSame(['acompanha' => true], $this->json());
        self::assertSame(0, $this->desligadosNoBanco($pasta));
    }

    #[TestDox('Token CSRF inválido dá 403 e não grava nada')]
    public function testCsrfInvalido(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana        = $this->criarColaborador($tenant);
        $pasta      = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);

        $this->alternar($pasta, 'token_invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->desligadosNoBanco($pasta));
    }

    #[TestDox('Pasta de OUTRO escritório dá 404 e não grava nada')]
    public function testOutroEscritorioDa404(): void
    {
        [, $tenantA] = $this->criarEscritorio();
        $ana         = $this->criarColaborador($tenantA);
        [, $tenantB] = $this->criarEscritorio();
        $pastaB      = $this->criarPasta($tenantB);
        $this->entrar($ana, $tenantA);

        $this->alternar($pastaB);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->desligadosNoBanco($pastaB));
    }

    #[TestDox('Colega do MESMO escritório sem permissão de ver a pasta leva 403 e não grava nada')]
    public function testSemPermissaoDeVerDa403(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $semNada    = $this->criarColaborador($tenant, 'Sem Nada', []);
        $pasta      = $this->criarPasta($tenant);
        $this->entrar($semNada, $tenant);

        $this->alternar($pasta);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->desligadosNoBanco($pasta));
    }

    #[TestDox('Pasta excluída (lápide) recusa, como o favorito, e não grava nada')]
    public function testPastaExcluidaRecusa(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana                 = $this->criarColaborador($tenant);
        $pasta               = $this->criarPasta($tenant);
        $pasta->marcarExcluida($primario, new \DateTimeImmutable());
        $this->em()->flush();
        $this->entrar($ana, $tenant);

        $this->alternar($pasta);

        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('excluída', (string) $this->json()['mensagem']);
        self::assertSame(0, $this->desligadosNoBanco($pasta));
    }

    #[TestDox('Cadeado em Visualizar: a preferência pessoal continua valendo')]
    public function testCadeadoVisualizarPassa(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana                 = $this->criarColaborador($tenant);
        $pasta               = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario, [[$ana, NivelDeAcesso::Visualizar]]);
        $this->entrar($ana, $tenant);

        $this->alternar($pasta);

        self::assertResponseIsSuccessful();
        self::assertSame(['acompanha' => false], $this->json());
    }

    #[TestDox('Cadeado em Oculto: a barreira recusa e nada é gravado')]
    public function testCadeadoOcultoBarrado(): void
    {
        [$primario, $tenant] = $this->criarEscritorio();
        $ana                 = $this->criarColaborador($tenant);
        $pasta               = $this->criarPasta($tenant);
        $this->trancar($pasta, $primario);
        $this->entrar($ana, $tenant);

        $this->alternar($pasta);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->desligadosNoBanco($pasta));
    }

    #[TestDox('Menu ⋮: "Acompanhar alterações" na seção Preferências, antes do favorito, ligado por padrão e desligado depois do POST')]
    public function testMenuMostraOItemNaOrdemDoDesenho(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana        = $this->criarColaborador($tenant);
        $pasta      = $this->criarPasta($tenant);
        $this->entrar($ana, $tenant);

        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        // Filho direto do menu, logo depois do título "Preferências" e logo antes do favorito.
        $trio = $crawler->filter('#psMenuAcoes > .ps-pop-secao + button.js-pasta-acompanhar + button.js-pasta-favorito');
        self::assertCount(1, $trio, 'o item fica entre o título Preferências e o favorito');
        $item = $crawler->filter('#psMenuAcoes > button.js-pasta-acompanhar');
        self::assertCount(1, $item);
        self::assertSame('Preferências', trim($item->previousAll()->first()->text()));
        self::assertSame('menuitemcheckbox', $item->attr('role'));
        self::assertCount(1, $item->filter('i.bi.bi-bell'));
        self::assertSame('Acompanhar alterações', trim($item->filter('span')->first()->text()));
        self::assertSame('true', $item->attr('aria-checked'), 'padrão ligado');
        self::assertCount(1, $item->filter('.ps-pop-sw.is-ligado > .ps-pop-sw-bola'));
        self::assertStringEndsWith('/pasta/' . $pasta->getId() . '/acompanhar', (string) $item->attr('data-url'));
        self::assertSame($pasta->getNup(), $item->attr('data-numero'), 'o número do aviso da tela é o NUP');

        $this->alternar($pasta);
        self::assertSame(['acompanha' => false], $this->json());

        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        $item    = $crawler->filter('#psMenuAcoes > button.js-pasta-acompanhar');
        self::assertSame('false', $item->attr('aria-checked'));
        self::assertCount(0, $item->filter('.ps-pop-sw.is-ligado'));
    }

    #[TestDox('Pasta sem NUP: o aviso da tela usa o mesmo recuo do servidor (#id), pronto no data-numero')]
    public function testSemNupUsaORecuoDoServidor(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana        = $this->criarColaborador($tenant);
        $pasta      = $this->criarPasta($tenant);
        $pasta->setNup('');
        $this->em()->flush();
        $this->entrar($ana, $tenant);

        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertSame('#' . $pasta->getId(), $crawler->filter('#psMenuAcoes > button.js-pasta-acompanhar')->attr('data-numero'));
    }

    #[TestDox('O "desligado" de um colega não desliga o meu interruptor')]
    public function testDesligadoEhPorUsuario(): void
    {
        [, $tenant] = $this->criarEscritorio();
        $ana        = $this->criarColaborador($tenant);
        $bruno      = $this->criarColaborador($tenant, 'Bruno Equipe');
        $pasta      = $this->criarPasta($tenant);
        $this->em()->persist(new \App\Pasta\Entity\PastaAcompanhamentoDesligado($tenant, $bruno, $pasta));
        $this->em()->flush();
        $this->entrar($ana, $tenant);

        $crawler = $this->client->request('GET', '/pasta/' . $pasta->getId());

        self::assertSame('true', $crawler->filter('#psMenuAcoes > button.js-pasta-acompanhar')->attr('aria-checked'));
    }
}
