<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\UseCase\EditarMensagemPastaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Ver alteração" (Antes e Depois) na Timeline inteligente (PAS-045): a edição do registro pela
 * rota real guarda o texto anterior INTEIRO, e a timeline o entrega — só a quem pode ver a pasta.
 */
#[CoversClass(PastaTimelineController::class)]
#[CoversClass(MontadorDaTimelineInteligente::class)]
#[CoversClass(EditarMensagemPastaUseCase::class)]
#[Group('pasta')]
final class PastaTimelineAlteracaoTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

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

    /** @return array<string, mixed> */
    private function timeline(KernelBrowser $client, Pasta $pasta): array
    {
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $dados @return array<string, mixed>|null */
    private function evento(array $dados, string $id): ?array
    {
        foreach ($dados['eventos'] as $e) {
            if ($e['id'] === $id) {
                return $e;
            }
        }

        return null;
    }

    private function enviar(KernelBrowser $client, Pasta $pasta, string $texto): int
    {
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem', ['conteudo' => $texto, '_token' => 'TOKEN_pasta_mensagem_' . $pasta->getId()]);

        return (int) $this->em()->getConnection()->fetchOne(
            'SELECT id FROM pasta_mensagem WHERE pasta_id = :p ORDER BY id DESC LIMIT 1',
            ['p' => $pasta->getId()],
        );
    }

    private function editar(KernelBrowser $client, Pasta $pasta, int $msgId, string $texto): void
    {
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $pasta->getId() . '/mensagem/' . $msgId . '/editar', [
            'conteudo' => $texto,
            '_token'   => 'TOKEN_pasta_mensagem_editar_' . $msgId,
        ]);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Editar pela rota real: a timeline traz o Antes INTEIRO (acima de 500) e a data; o não editado não traz')]
    public function testEdicaoGuardaEEntregaOAntesInteiro(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $longo  = 'INICIO ' . str_repeat('palavra ', 120) . 'FIM-DO-ORIGINAL';
        $msgId  = $this->enviar($client, $pasta, $longo);
        $outro  = $this->enviar($client, $pasta, 'NUNCA-EDITADO');
        self::assertGreaterThan(0, $msgId);
        $this->editar($client, $pasta, $msgId, 'TEXTO-CORRIGIDO');

        $gravado = (string) $this->em()->getConnection()->fetchOne('SELECT conteudo_anterior FROM pasta_mensagem WHERE id = :id', ['id' => $msgId]);
        self::assertStringContainsString('FIM-DO-ORIGINAL', $gravado);

        $dados  = $this->timeline($client, $pasta);
        $evento = $this->evento($dados, 'm' . $msgId);
        self::assertNotNull($evento);
        self::assertTrue($evento['editado']);
        self::assertSame('TEXTO-CORRIGIDO', $evento['texto']);
        self::assertStringStartsWith('INICIO palavra', (string) $evento['antes']);
        self::assertStringEndsWith('FIM-DO-ORIGINAL', (string) $evento['antes']);
        self::assertGreaterThan(500, mb_strlen((string) $evento['antes']));
        self::assertNotNull($evento['alteradoEm']);

        $semEdicao = $this->evento($dados, 'm' . $outro);
        self::assertNotNull($semEdicao);
        self::assertNull($semEdicao['antes']);
        self::assertNull($semEdicao['alteradoEm']);
    }

    #[TestDox('Permissão da pasta: quem não vê recebe 403 sem o Antes; outro escritório, 404 sem o Antes')]
    public function testAntesSoParaQuemVeAPasta(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        [$alheio, $outro] = $this->criarAdmin();
        $semNada         = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $msgId = $this->enviar($client, $pasta, 'ANTES-SIGILOSO');
        $this->editar($client, $pasta, $msgId, 'DEPOIS');
        // O irmão: quem pode ver recebe o Antes.
        self::assertSame('ANTES-SIGILOSO', $this->evento($this->timeline($client, $pasta), 'm' . $msgId)['antes']);

        $this->logarComTenant($client, $semNada, $tenant);
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('ANTES-SIGILOSO', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $alheio, $outro);
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('ANTES-SIGILOSO', (string) $client->getResponse()->getContent());
    }
}
