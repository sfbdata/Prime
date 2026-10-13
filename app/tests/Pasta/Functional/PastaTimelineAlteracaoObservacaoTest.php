<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Controller\PastaTimelineController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\MontadorDaTimelineInteligente;
use App\Pasta\UseCase\EditarObservacaoDetalhesUseCase;
use App\Pasta\UseCase\EditarObservacaoFinanceiraUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * "Ver alteração" (Antes e Depois) na Timeline inteligente para as observações de Detalhes e do
 * Financeiro (PAS-045b): a edição pela rota real guarda o texto anterior INTEIRO, e a timeline o
 * entrega — a do Financeiro só a quem vê o financeiro, e nada a quem não vê a pasta ou é de outro
 * escritório.
 */
#[CoversClass(PastaTimelineController::class)]
#[CoversClass(MontadorDaTimelineInteligente::class)]
#[CoversClass(EditarObservacaoDetalhesUseCase::class)]
#[CoversClass(EditarObservacaoFinanceiraUseCase::class)]
#[Group('pasta')]
final class PastaTimelineAlteracaoObservacaoTest extends JusPrimeWebTestCase
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

    /** `$aba`: 'detalhes' ou 'financeiro' — o prefixo da rota e do token CSRF. */
    private function enviar(KernelBrowser $client, Pasta $pasta, string $aba, string $texto): int
    {
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $pasta->getId() . '/' . $aba . '/observacao', [
            'conteudo' => $texto,
            '_token'   => 'TOKEN_pasta_' . $aba . '_obs_' . $pasta->getId(),
        ]);
        self::assertResponseStatusCodeSame(201);

        $dados = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        return (int) $dados['id'];
    }

    private function editar(KernelBrowser $client, Pasta $pasta, string $aba, int $obsId, string $texto): void
    {
        $this->em()->clear();
        $client->request('POST', '/pasta/' . $pasta->getId() . '/' . $aba . '/observacao/' . $obsId . '/editar', [
            'conteudo' => $texto,
            '_token'   => 'TOKEN_pasta_' . $aba . '_obs_editar_' . $obsId,
        ]);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Editar pela rota real (Detalhes e Financeiro): a timeline traz o Antes INTEIRO (acima de 500) e a data; a não editada não traz')]
    public function testEdicaoGuardaEEntregaOAntesInteiro(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $longoDet = 'INICIO ' . str_repeat('palavra ', 120) . 'FIM-DETALHES-ORIGINAL';
        $longoFin = 'INICIO ' . str_repeat('palavra ', 120) . 'FIM-FINANCEIRO-ORIGINAL';
        $det      = $this->enviar($client, $pasta, 'detalhes', $longoDet);
        $fin      = $this->enviar($client, $pasta, 'financeiro', $longoFin);
        $detNunca = $this->enviar($client, $pasta, 'detalhes', 'DETALHES-NUNCA-EDITADO');
        $finNunca = $this->enviar($client, $pasta, 'financeiro', 'FINANCEIRO-NUNCA-EDITADO');
        $this->editar($client, $pasta, 'detalhes', $det, 'DETALHES-CORRIGIDO');
        $this->editar($client, $pasta, 'financeiro', $fin, 'FINANCEIRO-CORRIGIDO');

        $conexao = $this->em()->getConnection();
        self::assertStringContainsString('FIM-DETALHES-ORIGINAL', (string) $conexao->fetchOne('SELECT conteudo_anterior FROM pasta_observacao_detalhes WHERE id = :id', ['id' => $det]));
        self::assertStringContainsString('FIM-FINANCEIRO-ORIGINAL', (string) $conexao->fetchOne('SELECT conteudo_anterior FROM pasta_observacao_financeira WHERE id = :id', ['id' => $fin]));

        $dados = $this->timeline($client, $pasta);

        foreach (['od' . $det => ['DETALHES-CORRIGIDO', 'FIM-DETALHES-ORIGINAL'], 'of' . $fin => ['FINANCEIRO-CORRIGIDO', 'FIM-FINANCEIRO-ORIGINAL']] as $id => [$depois, $fimDoAntes]) {
            $evento = $this->evento($dados, $id);
            self::assertNotNull($evento, $id);
            self::assertTrue($evento['editado']);
            self::assertSame($depois, $evento['texto']);
            self::assertStringStartsWith('INICIO palavra', (string) $evento['antes']);
            self::assertStringEndsWith($fimDoAntes, (string) $evento['antes']);
            self::assertGreaterThan(500, mb_strlen((string) $evento['antes']));
            self::assertNotNull($evento['alteradoEm']);
        }

        foreach (['od' . $detNunca, 'of' . $finNunca] as $id) {
            $semEdicao = $this->evento($dados, $id);
            self::assertNotNull($semEdicao, $id);
            self::assertNull($semEdicao['antes']);
            self::assertNull($semEdicao['alteradoEm']);
        }
    }

    #[TestDox('Quem não vê o financeiro não recebe o Antes da observação financeira; o de Detalhes continua')]
    public function testAntesDoFinanceiroSoParaQuemVeOFinanceiro(): void
    {
        $client          = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        // Dublê com chave: vê durante a escrita e a primeira leitura (o irmão), deixa de ver depois.
        // Instalado antes de qualquer requisição — serviço já inicializado não pode ser trocado.
        $veFinanceiro = true;
        $duble        = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $duble->method('podeVer')->willReturnCallback(static function () use (&$veFinanceiro): bool {
            return $veFinanceiro;
        });
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $duble);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);

        $det = $this->enviar($client, $pasta, 'detalhes', 'ANTES-DETALHES-VISIVEL');
        $fin = $this->enviar($client, $pasta, 'financeiro', 'ANTES-FINANCEIRO-SIGILOSO');
        $this->editar($client, $pasta, 'detalhes', $det, 'DEPOIS-DETALHES');
        $this->editar($client, $pasta, 'financeiro', $fin, 'DEPOIS-FINANCEIRO');

        // O irmão: quem vê o financeiro recebe o Antes dele.
        self::assertSame('ANTES-FINANCEIRO-SIGILOSO', $this->evento($this->timeline($client, $pasta), 'of' . $fin)['antes'] ?? null);

        $veFinanceiro = false;
        $dados        = $this->timeline($client, $pasta);
        $corpo        = (string) $client->getResponse()->getContent();

        self::assertSame('ANTES-DETALHES-VISIVEL', $this->evento($dados, 'od' . $det)['antes'] ?? null);
        self::assertNull($this->evento($dados, 'of' . $fin));
        self::assertStringNotContainsString('ANTES-FINANCEIRO-SIGILOSO', $corpo);
    }

    #[TestDox('Permissão da pasta: quem não vê recebe 403 sem o Antes; outro escritório, 404 sem o Antes')]
    public function testAntesSoParaQuemVeAPasta(): void
    {
        $client           = static::createClient();
        $client->disableReboot();
        [$user, $tenant]  = $this->criarAdmin();
        [$alheio, $outro] = $this->criarAdmin();
        $semNada          = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta            = $this->criarPasta($tenant);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenant);
        $det = $this->enviar($client, $pasta, 'detalhes', 'ANTES-DET-SIGILOSO');
        $fin = $this->enviar($client, $pasta, 'financeiro', 'ANTES-FIN-SIGILOSO');
        $this->editar($client, $pasta, 'detalhes', $det, 'DEPOIS-DET');
        $this->editar($client, $pasta, 'financeiro', $fin, 'DEPOIS-FIN');
        // O irmão: quem pode ver recebe os dois Antes.
        $dados = $this->timeline($client, $pasta);
        self::assertSame('ANTES-DET-SIGILOSO', $this->evento($dados, 'od' . $det)['antes'] ?? null);
        self::assertSame('ANTES-FIN-SIGILOSO', $this->evento($dados, 'of' . $fin)['antes'] ?? null);

        $this->logarComTenant($client, $semNada, $tenant);
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('SIGILOSO', (string) $client->getResponse()->getContent());

        $this->logarComTenant($client, $alheio, $outro);
        $this->em()->clear();
        $client->request('GET', '/pasta/' . $pasta->getId() . '/timeline');
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('SIGILOSO', (string) $client->getResponse()->getContent());
    }
}
