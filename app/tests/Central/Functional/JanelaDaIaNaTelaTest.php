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
        foreach (['placeholder', 'placeholder-aguardando', 'previa-vazia', 'aria', 'sugestao-pasta', 'pasta-rotulo', 'abrir-pasta', 'pasta-aria'] as $chave) {
            self::assertNotSame('', trim((string) $janela->attr('data-ia-' . $chave)), $chave);
        }

        $sugestoes = json_decode((string) $janela->attr('data-ia-sugestoes'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Tem alguma pendência?', 'Quais metas estão atrasadas?', 'Existe algum prazo?'], $sugestoes, 'o "resumo desta pasta" não entra aqui: o JS só o soma quando a barra da pasta existe');

        // texto visível sem travessão (regra do dono, 15/10/2026)
        self::assertSame('Faça um resumo desta pasta', $janela->attr('data-ia-sugestao-pasta'));
        self::assertSame('Abrir pasta', $janela->attr('data-ia-abrir-pasta'));
        self::assertSame('/central/ia/pasta/', $janela->attr('data-url-ia-pasta-base'), 'o JS acrescenta o id');
        self::assertNull($janela->attr('data-ia-pasta'), 'fora da tela de uma pasta não há dica de pasta');
        foreach (['aviso', 'vazio-texto', 'funcao', 'placeholder', 'sugestao-pasta', 'pasta-rotulo', 'abrir-pasta', 'pasta-aria'] as $chave) {
            self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $janela->attr('data-ia-' . $chave));
        }
    }

    #[TestDox('Na tela da pasta (/pasta/{id}) a janela flutuante leva data-ia-pasta com o id: só dica, o servidor revalida')]
    public function testJanelaNaPastaTrazADica(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view'], 'ana');
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $janela = $client->getCrawler()->filter('section[data-central]');
        self::assertCount(1, $janela);
        self::assertSame((string) $pasta->getId(), $janela->attr('data-ia-pasta'));
    }

    #[TestDox('As rotas da IA existem no roteador com o prefixo esperado pelo JS')]
    public function testRotasExistem(): void
    {
        $client = $this->cliente();
        $rotas = $client->getContainer()->get('router');
        self::assertSame('/central/ia/perguntas', $rotas->generate('central_ia_pergunta'));
        self::assertSame('/central/ia/perguntas/0', $rotas->generate('central_ia_pergunta_status', ['analiseId' => 0]));
        self::assertSame('/central/ia/pasta/0', $rotas->generate('central_ia_pasta', ['pastaId' => 0]));
    }
}
