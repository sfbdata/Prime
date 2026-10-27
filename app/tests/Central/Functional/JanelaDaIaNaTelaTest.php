<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Controller\CentralController;
use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * F3, PR 3b: a janela da Central entrega ao central.js as rotas da IA (geradas por path()) e os textos da conversa
 * (translations central.ia.*) por data-*. Nada do texto da IA fica fixo no JS.
 */
#[CoversClass(CentralController::class)]
final class JanelaDaIaNaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('/central: data-url-ia-pergunta, data-url-ia-status (base com barra final) e os textos central.ia.* na janela')]
    public function testJanelaTrazRotasETextosDaIa(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/central');
        self::assertResponseIsSuccessful();

        $janela = $client->getCrawler()->filter('section[data-central]');
        self::assertCount(1, $janela);
        self::assertSame('/central/ia/perguntas', $janela->attr('data-url-ia-pergunta'));
        self::assertSame('/central/ia/perguntas/', $janela->attr('data-url-ia-status'), 'o JS acrescenta o analiseId');
        self::assertSame('BlueJus IA', $janela->attr('data-ia-nome'));
        self::assertSame('IA', $janela->attr('data-ia-selo'));
        self::assertSame('Assistente', $janela->attr('data-ia-setor'));
        self::assertSame('inteligência do BlueJus', $janela->attr('data-ia-funcao'));
        self::assertSame('BlueJus IA', $janela->attr('data-ia-vazio-titulo'));
        self::assertStringStartsWith('Pergunte em linguagem natural.', (string) $janela->attr('data-ia-vazio-texto'));
        self::assertSame('digitando...', $janela->attr('data-ia-digitando'));
        self::assertStringContainsString('IA', (string) $janela->attr('data-ia-aviso'));
        foreach (['placeholder', 'placeholder-aguardando', 'previa-vazia', 'aria'] as $chave) {
            self::assertNotSame('', trim((string) $janela->attr('data-ia-' . $chave)), $chave);
        }

        $sugestoes = json_decode((string) $janela->attr('data-ia-sugestoes'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Tem alguma pendência?', 'Quais metas estão atrasadas?', 'Existe algum prazo?'], $sugestoes, 'sem "resumo desta pasta": o contexto da tela é o PR 4');

        // texto visível sem travessão (regra do dono, 15/10/2026)
        foreach (['aviso', 'vazio-texto', 'funcao', 'placeholder'] as $chave) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $janela->attr('data-ia-' . $chave));
        }
    }

    #[TestDox('As rotas da IA existem no roteador com o prefixo esperado pelo JS')]
    public function testRotasExistem(): void
    {
        $client = $this->cliente();
        $rotas = $client->getContainer()->get('router');
        self::assertSame('/central/ia/perguntas', $rotas->generate('central_ia_pergunta'));
        self::assertSame('/central/ia/perguntas/0', $rotas->generate('central_ia_pergunta_status', ['analiseId' => 0]));
    }
}
