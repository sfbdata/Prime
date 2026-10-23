<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Editar dados" da pasta na janela do desenho 02 (VS 18.10.26, L.634-668), sem o modal do Bootstrap:
 * sobreposição própria > caixa role=dialog > cabeçalho · atalhos · formulário (corpo e rodapé). Os
 * campos e o POST para `pasta_edit` são os de antes: a troca é só da moldura.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaEditarDadosJanelaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('janela do desenho 02: sobreposição escondida > caixa role=dialog > cabeçalho, atalhos e form com corpo e rodapé; nada do modal do Bootstrap')]
    public function testArranjoDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $sobre = $crawler->filter('body #modalEditarPasta.ps-pe-sobre');
        self::assertCount(1, $sobre);
        self::assertNotNull($sobre->attr('hidden'), 'fechada ao carregar');
        self::assertStringNotContainsString('modal', (string) $sobre->attr('class'), 'sem o .modal do Bootstrap');

        $caixa = $sobre->filter('#modalEditarPasta > .ps-modal-pasta[role="dialog"][aria-modal="true"][aria-labelledby="modalEditarPastaLabel"]');
        self::assertCount(1, $caixa);
        self::assertCount(3, $caixa->filter('.ps-modal-pasta > *'), 'cabeçalho, atalhos e formulário');
        self::assertCount(1, $caixa->filter('.ps-modal-pasta > .ps-modal-pasta-cab + nav.ps-modal-pasta-abas + form[method="post"]'));
        self::assertCount(1, $caixa->filter('.ps-modal-pasta > .ps-modal-pasta-cab > button.ps-modal-pasta-fechar[data-pe-fechar][aria-label="Fechar"] > i.bi-x-lg'));
        self::assertCount(1, $caixa->filter('.ps-modal-pasta > form > .ps-modal-pasta-corpo + .ps-modal-pasta-rodape'));
        self::assertCount(1, $caixa->filter('.ps-modal-pasta > form > .ps-modal-pasta-rodape > button.ps-modal-pasta-btn--fechar[data-pe-fechar][type="button"]'));
        self::assertCount(1, $caixa->filter('.ps-modal-pasta > form > .ps-modal-pasta-rodape > button.ps-modal-pasta-btn--salvar[type="submit"]'));

        self::assertCount(0, $crawler->filter('#modalEditarPasta .modal-dialog, #modalEditarPasta .modal-content, #modalEditarPasta [data-bs-dismiss]'));
        self::assertCount(0, $crawler->filter('[data-bs-target="#modalEditarPasta"]'), 'o ⋮ não abre mais pelo Bootstrap');
        self::assertCount(1, $crawler->filter('#psMenuAcoes > button.ps-pop-item[data-pe-abrir][aria-haspopup="dialog"][aria-controls="modalEditarPasta"][data-ps-atalho="e"]'));
    }

    #[TestDox('Salvar continua igual: o formulário da janela manda os mesmos campos ao pasta_edit e grava número, nome e ação')]
    public function testSalvarPeloFormularioDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pastaId         = (int) $pasta->getId();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pastaId);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#modalEditarPasta > .ps-modal-pasta > form')->form();
        self::assertSame('/pasta/' . $pastaId . '/editar', parse_url($form->getUri(), PHP_URL_PATH));
        $nomes = array_keys($form->getValues());
        sort($nomes);
        self::assertSame(['_token', 'nome_acao', 'nome_cliente', 'nup', 'situacao'], $nomes, 'os mesmos names de antes');

        $novoNup = 'JAN-' . strtoupper(uniqid());
        $form['nup']          = $novoNup;
        $form['nome_cliente'] = 'Cliente da Janela';
        $form['nome_acao']    = 'Ação da Janela';
        $client->submit($form);

        self::assertResponseRedirects('/pasta/' . $pastaId);
        $this->em()->clear();
        $gravada = $this->em()->find(Pasta::class, $pastaId);
        self::assertNotNull($gravada);
        self::assertSame($novoNup, $gravada->getNup());
        self::assertSame('CLIENTE DA JANELA', $gravada->getNomeCliente(), 'a entidade grava em maiúsculas, como antes');
        self::assertSame('AÇÃO DA JANELA', $gravada->getNomeAcao());
    }
}
