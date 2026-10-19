<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Functional;

use App\Inteligencia\Controller\AnalisePushController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * P4h: "Editar" a Análise IA do Push (spec docs/specs/push-editar-analise-ia.md). A edição grava
 * ao lado da saída do modelo; o cartão mostra o selo "editado" e o texto novo.
 */
#[CoversClass(AnalisePushController::class)]
#[Group('pasta')]
final class EditarAnaliseControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private bool $csrfInstalado = false;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        if (!$this->csrfInstalado) {
            $this->csrfInstalado = true;
            static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
                public function getToken(string $tokenId): string
                {
                    return 'TOKEN_' . $tokenId;
                }
                public function setToken(string $tokenId, string $token): void
                {
                }
                public function removeToken(string $tokenId): ?string
                {
                    return null;
                }
                public function hasToken(string $tokenId): bool
                {
                    return true;
                }
                public function clear(): void
                {
                }
            });
        }

        return $client;
    }

    /** @param array<string, mixed> $campos */
    private function editar(KernelBrowser $client, int $pastaId, int $analiseId, array $campos, bool $comToken = true): void
    {
        if ($comToken) {
            $campos['_token'] = 'TOKEN_inteligencia_analise_' . $analiseId;
        }
        $client->request('POST', "/pasta/{$pastaId}/ia/analises/{$analiseId}/editar", $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    }

    private function editada(int $analiseId): bool
    {
        return $this->contarNoBanco('SELECT count(*) FROM inteligencia_analise WHERE id = :id AND editada_em IS NOT NULL', ['id' => $analiseId]) === 1;
    }

    private function cartao(KernelBrowser $client, int $pastaId, int $analiseId): Crawler
    {
        $crawler = $client->request('GET', "/pasta/{$pastaId}/ia/push/analises");
        self::assertResponseIsSuccessful();

        return $crawler->filter('.ps-ia-lista > .ps-ia-cartao[data-ia-analise="' . $analiseId . '"]');
    }

    /** @return array{\App\Entity\Auth\User, \App\Entity\Tenant\Tenant, Pasta, int} */
    private function cenario(): array
    {
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($analise, 'Sentença publicada.', [
            ['tipo' => 'prazo', 'texto' => 'Apelação em 15 dias.'],
            ['tipo' => 'info', 'texto' => 'Custas pagas.'],
        ], 'Advogado');

        return [$admin, $tenant, $pasta, (int) $analise->getId()];
    }

    #[TestDox('quem edita vê "Editar" no ⋮ e no rodapé e o formulário com o resumo e um campo por ponto')]
    public function testCartaoOfereceEdicao(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        $this->logarComTenant($client, $admin, $tenant);

        $cartao = $this->cartao($client, (int) $pasta->getId(), $id);

        self::assertSame('Editar', trim($cartao->filter('.ps-ia-menu > button[data-ia-editar]')->text()));
        self::assertSame('Editar', trim($cartao->filter('.ps-ia-corpo > footer.ps-ia-acoes > button[data-ia-editar]')->text()));
        $form = $cartao->filter('.ps-ia-corpo > form[data-ia-edicao]');
        self::assertSame("/pasta/{$pasta->getId()}/ia/analises/{$id}/editar", $form->attr('action'));
        self::assertSame('Sentença publicada.', $form->filter('textarea[name="resumo"]')->text());
        self::assertSame('Resumo da análise', $form->filter('textarea[name="resumo"]')->attr('aria-label'));
        self::assertSame('Apelação em 15 dias.', $form->filter('input[name="pontos[0]"]')->attr('value'));
        self::assertSame('Custas pagas.', $form->filter('input[name="pontos[1]"]')->attr('value'));
        self::assertCount(2, $form->filter('[data-ia-ed-ponto] > button[aria-label="Remover ponto"]'));
        self::assertStringContainsString('Conteúdo originalmente gerado por IA. Sua edição fica registrada como "editado".', $form->text());
        self::assertSame(['Salvar', 'Cancelar'], $form->filter('.ps-ia-acoes > button')->each(static fn (Crawler $b): string => trim($b->text())));
        self::assertCount(0, $cartao->filter('.ps-ia-editado'), 'sem edição, sem selo');
    }

    #[TestDox('salvar grava a edição: cartão com o selo "editado", o texto novo, o ponto removido some; o original fica no banco')]
    public function testSalvar(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        $this->logarComTenant($client, $admin, $tenant);

        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => 'Sentença de procedência publicada.', 'pontos' => [0 => 'Apelação até 27/10.']]);

        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['editada']);
        self::assertTrue($this->editada($id));
        self::assertSame(1, $this->contarNoBanco("SELECT count(*) FROM inteligencia_analise WHERE id = :id AND resumo = 'Sentença publicada.' AND editada_por_id IS NOT NULL", ['id' => $id]), 'a saída do modelo fica intacta');

        $cartao = $this->cartao($client, (int) $pasta->getId(), $id);
        self::assertSame('editado', trim($cartao->filter('.ps-ia-corpo > .ps-ia-cab > .ps-ia-editado')->text()));
        self::assertStringStartsWith('Editado por ', (string) $cartao->filter('.ps-ia-editado')->attr('title'));
        self::assertSame('Sentença de procedência publicada.', trim($cartao->filter('.ps-ia-corpo > .ps-ia-resumo')->text()));
        self::assertSame(['Apelação até 27/10.'], $cartao->filter('.ps-ia-corpo > .ps-ia-pontos .ps-ia-ponto-txt')->each(static fn (Crawler $p): string => trim($p->text())));

        // O .txt sai com a versão editada e diz que foi editado.
        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$id}.txt");
        $txt = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Sentença de procedência publicada.', $txt);
        self::assertStringContainsString('Editado por ', $txt);
        self::assertStringNotContainsString('Custas pagas.', $txt);
    }

    #[TestDox('sem CSRF → 403; quem só vê a pasta → 403 e não recebe o "Editar"; nada é gravado')]
    public function testGuardas(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        // Usuários criados antes das requisições: depois delas o EntityManager do teste é limpo.
        $leitor = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'modules.inteligencia.view'], 'leitor');
        $semModulo = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view', 'resources.pasta.edit'], 'semia');
        $this->logarComTenant($client, $admin, $tenant);

        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => 'x'], comToken: false);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $leitor, $tenant);
        $cartao = $this->cartao($client, (int) $pasta->getId(), $id);
        self::assertCount(0, $cartao->filter('[data-ia-editar]'));
        self::assertCount(0, $cartao->filter('form[data-ia-edicao]'));

        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(403);

        $this->logarComTenant($client, $semModulo, $tenant);
        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(403);

        self::assertFalse($this->editada($id));
    }

    #[TestDox('pasta excluída (lápide): nem quem edita recebe o "Editar", e o POST é recusado (403)')]
    public function testLapide(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        $pasta->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $admin, $tenant);

        $cartao = $this->cartao($client, (int) $pasta->getId(), $id);
        self::assertCount(0, $cartao->filter('[data-ia-editar]'));

        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->editada($id));
    }

    #[TestDox('outro escritório e pasta irmã → 404; nada é gravado')]
    public function testIsolamento(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        [$outro, $tenantB] = $this->criarAdmin();
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnalisePendente($tenantB, $outro, $pastaB);
        $this->concluirAnalise($analiseB, 'Do outro.');
        [$irma] = $this->criarPastaComPublicacao($tenant, '07099999999999999999');
        $this->logarComTenant($client, $admin, $tenant);

        $this->editar($client, (int) $pastaB->getId(), (int) $analiseB->getId(), ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->editar($client, (int) $pasta->getId(), (int) $analiseB->getId(), ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->editar($client, (int) $irma->getId(), $id, ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse($this->editada($id));
        self::assertFalse($this->editada((int) $analiseB->getId()));
    }

    #[TestDox('análise não concluída → 409; texto longo → 422; nada é gravado')]
    public function testEstadoELimite(): void
    {
        $client = $this->cliente();
        [$admin, $tenant, $pasta, $id] = $this->cenario();
        $pendente = $this->criarAnalisePendente($tenant, $admin, $pasta);
        $this->logarComTenant($client, $admin, $tenant);

        $this->editar($client, (int) $pasta->getId(), (int) $pendente->getId(), ['resumo' => 'x']);
        self::assertResponseStatusCodeSame(409);

        $this->editar($client, (int) $pasta->getId(), $id, ['resumo' => str_repeat('a', 2001)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Texto longo demais.', json_decode((string) $client->getResponse()->getContent(), true)['mensagem']);

        self::assertFalse($this->editada($id));
        self::assertFalse($this->editada((int) $pendente->getId()));
    }
}
