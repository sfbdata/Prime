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

/**
 * IA-007: Copiar · Baixar .txt · Imprimir no resultado do agente. O .txt é a análise GRAVADA,
 * servida como anexo `text/plain` + `nosniff` (o texto do modelo nunca é interpretado como HTML).
 * Guarda das rotas por id: outro escritório e pasta irmã → 404; sem o módulo → 403.
 */
#[CoversClass(AnalisePushController::class)]
final class AnaliseEmTextoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        return $client;
    }

    #[TestDox('análise concluída do agente → 200, text/plain, nosniff, anexo .txt e o texto gravado literal')]
    public function testBaixaOTextoGravado(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Processual);
        $this->concluirAnalise($analise, 'Resumo.', [], null, "Fase atual: instrução.\n<script>alert(1)</script>");
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}.txt");

        self::assertResponseIsSuccessful();
        $resposta = $client->getResponse();
        self::assertStringStartsWith('text/plain', (string) $resposta->headers->get('Content-Type'));
        self::assertSame('nosniff', $resposta->headers->get('X-Content-Type-Options'));
        $disposicao = (string) $resposta->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposicao);
        self::assertStringContainsString('-' . $analise->getId() . '.txt', $disposicao);
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));

        $corpo = (string) $resposta->getContent();
        self::assertStringStartsWith('BLUEJUS IA · Agente Processual', $corpo);
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $corpo);
        self::assertStringContainsString("Fase atual: instrução.\n<script>alert(1)</script>", $corpo, 'o .txt é o texto gravado, literal');
        self::assertStringContainsString('Gerado por IA', $corpo);
    }

    #[TestDox('pasta de OUTRO escritório, análise de outro escritório pela própria pasta e pasta irmã → 404')]
    public function testCrossTenantEPastaIrma(): void
    {
        $client = $this->cliente();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        [$pastaA1] = $this->criarPastaComPublicacao($tenantA);
        [$pastaA2] = $this->criarPastaComPublicacao($tenantA, '07099999999999999999');
        [$pastaB] = $this->criarPastaComPublicacao($tenantB);
        $analiseB = $this->criarAnaliseDoAgentePendente($tenantB, $userB, $pastaB);
        $this->concluirAnalise($analiseB, 'Resumo B.', [], null, 'Segredo do escritório B.');
        $analiseA2 = $this->criarAnaliseDoAgentePendente($tenantA, $userA, $pastaA2);
        $this->concluirAnalise($analiseA2, 'Resumo A2.', [], null, 'Texto A2.');
        $this->logarComTenant($client, $userA, $tenantA);

        $client->request('GET', "/pasta/{$pastaB->getId()}/ia/analises/{$analiseB->getId()}.txt");
        self::assertResponseStatusCodeSame(404, 'pasta de outro escritório');

        $client->request('GET', "/pasta/{$pastaA1->getId()}/ia/analises/{$analiseB->getId()}.txt");
        self::assertResponseStatusCodeSame(404, 'análise de outro escritório pela própria pasta');
        self::assertStringNotContainsString('Segredo do escritório B', (string) $client->getResponse()->getContent());

        $client->request('GET', "/pasta/{$pastaA1->getId()}/ia/analises/{$analiseA2->getId()}.txt");
        self::assertResponseStatusCodeSame(404, 'pasta irmã do mesmo escritório');

        // O mesmo id pela pasta certa: senão os 404 acima passariam por engano.
        $client->request('GET', "/pasta/{$pastaA2->getId()}/ia/analises/{$analiseA2->getId()}.txt");
        self::assertResponseIsSuccessful();
    }

    #[TestDox('usuário que vê a pasta mas não tem modules.inteligencia.view → 403')]
    public function testSemModulo(): void
    {
        $client = $this->cliente();
        [$admin, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $analise = $this->criarAnaliseDoAgentePendente($tenant, $admin, $pasta);
        $this->concluirAnalise($analise, 'Resumo.', [], null, 'Texto.');
        $user = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$analise->getId()}.txt");

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('análise pendente ou excluída → 404: só a concluída tem texto para baixar')]
    public function testPendenteOuExcluida(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $pendente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $excluida = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $this->concluirAnalise($excluida, 'Resumo.', [], null, 'Texto.');
        $excluida->excluir($user);
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$pendente->getId()}.txt");
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', "/pasta/{$pasta->getId()}/ia/analises/{$excluida->getId()}.txt");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('o cartão concluído traz Copiar · Baixar .txt · Imprimir no cabeçalho; o pendente não')]
    public function testBotoesNoCartao(): void
    {
        $client = $this->cliente();
        [$user, $tenant] = $this->criarAdmin();
        [$pasta] = $this->criarPastaComPublicacao($tenant);
        $concluida = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Gestor);
        $this->concluirAnalise($concluida, 'Resumo.', [], null, 'Texto.');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/gestor/analises");
        self::assertResponseIsSuccessful();

        $cab = $crawler->filter('.ia-ag-lista > .ia-cartao[data-ia-analise="' . $concluida->getId() . '"] > .ia-corpo > .ia-cab');
        self::assertSame(1, $cab->filter('button.ia-icone[data-ia-copiar]')->count());
        $baixar = $cab->filter('a.ia-icone[data-ia-baixar][download]');
        self::assertSame(1, $baixar->count());
        self::assertSame("/pasta/{$pasta->getId()}/ia/analises/{$concluida->getId()}.txt", $baixar->attr('href'));
        self::assertSame('Baixar .txt', $baixar->attr('title'));
        self::assertSame(1, $cab->filter('button.ia-icone[data-ia-imprimir]')->count());

        // Pendente não tem texto: nada de copiar/baixar/imprimir.
        $pendente = $this->criarAnaliseDoAgentePendente($tenant, $user, $pasta, Agente::Prazos);
        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/ia/agentes/prazos/analises");
        $cartao = $crawler->filter('.ia-cartao[data-ia-analise="' . $pendente->getId() . '"]');
        self::assertSame(1, $cartao->count());
        self::assertSame(0, $cartao->filter('[data-ia-copiar], [data-ia-baixar], [data-ia-imprimir]')->count());
    }
}
