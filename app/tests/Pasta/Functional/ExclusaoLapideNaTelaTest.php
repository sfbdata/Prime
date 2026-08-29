<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A corrente inteira da exclusão-lápide, pela tela: excluir → a pasta continua na lista riscada,
 * a tela dela avisa, o histórico registra e a auditoria guarda quem/quando.
 *
 * Suíte verde não diz nada sobre APARÊNCIA — o risco em si é CSS e continua invisível para o
 * PHPUnit. O que dá para provar aqui é o arranjo: a classe que o CSS usa está na linha certa, e
 * o badge que ressuscitaria a pasta com dois cliques não está.
 */
final class ExclusaoLapideNaTelaTest extends JusPrimeWebTestCase
{
    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioAdmin(): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Lapide Tela ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_lapide_tela_' . uniqid() . '@test.com');
        $user->setFullName('EDLUCIA LINS PEREIRA');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, string $nup): Pasta
    {
        $em    = static::getContainer()->get(EntityManagerInterface::class);
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente('CLIENTE DA LAPIDE');
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /**
     * O dublê de CSRF vive no container, e o KernelBrowser reinicia o kernel a cada requisição:
     * instalado uma vez, ele só vale para o PRIMEIRO POST e o segundo leva 403 — custou um 403
     * fantasma neste teste. Reinstalar também não serve, porque o container recusa trocar
     * serviço já inicializado. Desligar o reboot é o que faz o dublê atravessar a sequência
     * inteira, que é o que estes testes precisam: eles excluem e restauram na mesma sessão.
     *
     * @param array<string, string> $payload
     */
    private function postComCsrf(object $client, string $url, string $tokenId, array $payload = []): void
    {
        $client->request('POST', $url, $payload + ['_token' => 'TOKEN_' . $tokenId]);
    }

    #[TestDox('Excluir pasta do meio pela tela: continua na lista, riscada, e a tela dela avisa')]
    public function testExcluirPelaTelaDeixaALapideVisivel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1238');
        $this->criarPasta($tenant, '1239');

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->postComCsrf($client, "/pasta/{$doMeio->getId()}/deletar", 'delete_pasta_' . $doMeio->getId());

        // Exclusão de pasta com posterior manda para a própria pasta, não para a lista: ela
        // ainda existe e a pessoa precisa ver o que aconteceu com ela.
        self::assertResponseRedirects();
        self::assertStringContainsString(
            "/pasta/{$doMeio->getId()}",
            (string) $client->getResponse()->headers->get('Location'),
        );

        // ── A tela da pasta avisa ────────────────────────────────────────────
        $crawler = $client->request('GET', "/pasta/{$doMeio->getId()}");
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.ps-cab-excluida'), 'A faixa de pasta excluída sumiu.');
        self::assertStringContainsString('EDLUCIA LINS PEREIRA', $crawler->filter('.ps-cab-excluida')->text());
        self::assertCount(0, $crawler->filter('#btn-alternar-status'), 'Pasta riscada não pode oferecer Arquivar.');
        self::assertCount(
            1,
            $crawler->filter('form[action$="/restaurar"]'),
            'A pasta riscada precisa oferecer o caminho de volta.',
        );

        // ── O histórico registra ─────────────────────────────────────────────
        self::assertStringContainsString('Pasta excluída', $crawler->filter('#psHistorico')->html());

        // ── A lista do Expediente mostra riscada ─────────────────────────────
        // O painel do acervo é fragmento: sem o cabeçalho de XHR ele redireciona para a home do
        // Expediente (que lista marcadores, não pastas) — foi o 302 que derrubou este teste antes.
        $lista = $client->request(
            'GET',
            '/expediente/painel/acervo-geral',
            [], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $lista->filter('tr.pasta-excluida')->count() + $lista->filter('.pasta-card.pasta-excluida')->count(),
            'A pasta excluída precisa continuar aparecendo na lista, com a marca do riscado.',
        );
    }

    #[TestDox('A AUDITORIA guarda a exclusão: ação, autor, rota e o que mudou')]
    public function testAuditoriaGuardaAExclusao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1238');
        $this->criarPasta($tenant, '1239');

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->postComCsrf($client, "/pasta/{$doMeio->getId()}/deletar", 'delete_pasta_' . $doMeio->getId());

        $linha = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative(
            "SELECT action, actor_email, route, changes::text AS changes
               FROM audit_log
              WHERE entity_class = ? AND entity_id = ?
              ORDER BY id DESC LIMIT 1",
            [Pasta::class, (string) $doMeio->getId()],
        );

        self::assertNotFalse($linha, 'A exclusão precisa deixar linha na auditoria.');
        self::assertSame($user->getEmail(), $linha['actor_email']);
        self::assertSame('pasta_delete', $linha['route']);
        // O diff é o que responde "o que exatamente aconteceu com esta pasta".
        self::assertStringContainsString('excluidaEm', (string) $linha['changes']);
        self::assertStringContainsString('arquivado', (string) $linha['changes']);
    }

    #[TestDox('Restaurar pela tela tira o riscado e registra a volta no histórico')]
    public function testRestaurarPelaTelaRegistraNoHistorico(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $doMeio          = $this->criarPasta($tenant, '1238');
        $this->criarPasta($tenant, '1239');

        $client->disableReboot();
        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $this->postComCsrf($client, "/pasta/{$doMeio->getId()}/deletar", 'delete_pasta_' . $doMeio->getId());
        $this->postComCsrf($client, "/pasta/{$doMeio->getId()}/restaurar", 'restaurar_pasta_' . $doMeio->getId());

        $crawler = $client->request('GET', "/pasta/{$doMeio->getId()}");

        self::assertCount(0, $crawler->filter('.ps-cab-excluida'), 'A faixa devia ter saído.');
        self::assertCount(1, $crawler->filter('#btn-alternar-status'), 'As ações normais devem voltar.');

        // As duas pontas ficam no histórico: a pasta guarda que foi excluída E que voltou.
        $historico = $crawler->filter('#psHistorico')->html();
        self::assertStringContainsString('Pasta excluída', $historico);
        self::assertStringContainsString('Pasta restaurada', $historico);
    }

    #[TestDox('Pasta recuperada: o delete antigo da auditoria vira "Pasta excluída", não evento genérico')]
    public function testDeleteAntigoNaAuditoriaEhLidoComoExclusao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $recuperada      = $this->criarPasta($tenant, '1238');

        // Reproduz o estado de uma pasta APAGADA antes da exclusão-lápide e recuperada depois:
        // a linha existe de novo, e a auditoria dela carrega o `delete` da exclusão original.
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            "INSERT INTO audit_log (action, entity_class, entity_id, changes, actor_user_id, actor_email, tenant_id, route, created_at)
             VALUES ('delete', ?, ?, ?, ?, ?, ?, 'pasta_delete', '2026-08-28 16:57:06')",
            [
                Pasta::class,
                (string) $recuperada->getId(),
                json_encode(['diff' => ['before' => ['nup' => '1238']]], JSON_THROW_ON_ERROR),
                $user->getId(),
                $user->getEmail(),
                $tenant->getId(),
            ],
        );

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', "/pasta/{$recuperada->getId()}");

        $historico = $crawler->filter('#psHistorico')->html();
        self::assertStringContainsString('Pasta excluída', $historico);
        self::assertStringNotContainsString('Evento registrado', $historico);
    }
}
