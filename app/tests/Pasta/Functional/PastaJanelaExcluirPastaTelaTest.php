<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * "Excluir pasta" do cabeçalho pergunta na janela da casa (#psExcluirPasta, o `.mp-modal`), não no prompt() do
 * navegador (pedido do Samuel, 18/11/2026). A janela pede o número da pasta, diz o que acontece (sai das listas, o
 * número fica livre, volta pela Lixeira do Expediente) e o pasta-excluir-pasta.js envia o form do menu com
 * `confirmar_nup`. Quem confere o número de verdade continua sendo o servidor (ExcluirPastaConfirmacaoNumeroTest).
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaJanelaExcluirPastaTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    #[TestDox('a janela é o .mp-modal da casa: cabeça, corpo com aviso e campo do número, pé com Cancelar e o Excluir desabilitado')]
    public function testArranjoDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        $raiz = '#psExcluirPasta.mp-modal.mp-devolucao.ps-excluir-pasta[hidden]';
        self::assertCount(1, $crawler->filter($raiz . ' > .mp-modal-fundo[data-mp-fechar]'));
        $caixa = $raiz . ' > .mp-modal-centro > .mp-modal-caixa[role="dialog"][aria-modal="true"]';
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-cabeca > b#psExcluirPastaTitulo.mp-modal-cabeca-titulo'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-cabeca > button.mp-modal-x[data-mp-fechar]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > .mp-dev-texto[data-excluir-pasta-aviso]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-corpo > label.mp-campo > input#psExcluirPastaNumero[data-excluir-pasta-numero][autocomplete="off"]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-pe > button.mp-cancelar[data-mp-fechar]'));
        self::assertCount(1, $crawler->filter($caixa . ' > .mp-modal-pe > button.mp-confirmar.ps-excluir-pasta-ok[data-excluir-pasta-ok][disabled]'));

        self::assertSame($pasta->getNup(), $crawler->filter('#psExcluirPasta')->attr('data-nup'));
        self::assertCount(1, $crawler->filter('link[href*="css/meta-passagem.css"]'), 'o CSS do .mp-modal está na página');
        self::assertCount(1, $crawler->filter('script[src*="js/pasta-excluir-pasta.js"]'));
        self::assertNull($crawler->filter('#psMenuAcoes > form.js-excluir-pasta')->attr('onsubmit'), 'o form do menu não pergunta sozinho');
        self::assertStringNotContainsString('window.prompt(', (string) $client->getResponse()->getContent());
    }

    #[TestDox('os textos dizem a verdade: sai das listas, o número fica livre e a pasta volta pela Lixeira do Expediente; sem travessão')]
    public function testTextosDaJanela(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $nup             = (string) $pasta->getNup();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        $janela = $crawler->filter('#psExcluirPasta');
        self::assertSame(
            'A pasta sai das listas e vai para a lixeira do escritório. O número ' . $nup . ' fica livre para outra pasta. Ela pode ser restaurada pela Lixeira do Expediente.',
            trim($janela->filter('[data-excluir-pasta-aviso]')->text()),
        );
        self::assertStringStartsWith('Para confirmar, digite ' . $nup . ' (o NUP da pasta, não o número da URL)', trim($janela->filter('label.mp-campo')->text()));
        self::assertSame('Cancelar', trim($janela->filter('.mp-cancelar')->text()));
        self::assertSame('Excluir pasta', trim($janela->filter('.ps-excluir-pasta-ok')->text()));

        $menu = CatalogoPtBr::carregar()['pasta_cabecalho']['cabecalho']['menu'];
        foreach (['excluir_aviso', 'excluir_digite', 'excluir_cancelar', 'excluir_nao_confere'] as $chave) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $menu[$chave], $chave);
        }
    }

    #[TestDox('o script segura o envio, confere o número sem espaços e sem caixa e só então envia o form com confirmar_nup')]
    public function testScriptDaJanela(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/pasta-excluir-pasta.js');

        self::assertStringContainsString("form.matches('form.js-excluir-pasta')", $js);
        self::assertStringContainsString('e.preventDefault();', $js);
        self::assertStringContainsString("return String(valor || '').trim().toUpperCase();", $js);
        self::assertStringContainsString("j.querySelector('[data-excluir-pasta-ok]').disabled = !confere(j);", $js);
        self::assertStringContainsString('form.elements.confirmar_nup.value = ', $js);
        self::assertStringContainsString('form.submit();', $js);
        self::assertStringContainsString('window.avisarPs(j.dataset.naoConfere);', $js);
        foreach (['prompt(', 'confirm(', 'alert('] as $nativo) {
            self::assertStringNotContainsString('window.' . $nativo, $js, $nativo);
        }
    }

    #[TestDox('a pasta já excluída (na lixeira) não tem a janela nem o script')]
    public function testSemJanelaNaLixeira(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->marcarExcluida($user, new \DateTimeImmutable());
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#psExcluirPasta'));
        self::assertCount(0, $crawler->filter('script[src*="js/pasta-excluir-pasta.js"]'));
    }
}
