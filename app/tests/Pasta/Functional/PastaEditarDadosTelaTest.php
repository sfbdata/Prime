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
 * "Editar dados" da pasta no desenho 1.2.3 (dc L.560-592, `peVals` L.4278-4307): linha do
 * Responsável com "Trocar" (PAS-021), pílula de estado, Salvar travado e "Pendências"
 * (PAS-020) e a confirmação da troca do número (PAS-019).
 *
 * O que muda na tela roda no navegador; aqui se prova o arranjo (filho direto) e o script.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaEditarDadosTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(object $client, Pasta $pasta): object
    {
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('PAS-021: Responsável só leitura, antes da Ação, com "Trocar" que leva ao menu do cabeçalho; sem name')]
    public function testLinhaDoResponsavel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setResponsavel($user);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $linha = $crawler->filter('#modalEditarPasta form .ps-modal-pasta-corpo > .ps-modal-pasta-linha--larga');
        self::assertCount(1, $linha);
        $campos = $linha->filter('.ps-modal-pasta-linha--larga > .ps-modal-pasta-campo');
        self::assertSame('Responsável', trim($campos->eq(0)->filter('.ps-modal-pasta-campo > .ps-modal-pasta-rotulo')->text()), 'o Responsável vem antes da Ação, como no desenho');
        self::assertCount(1, $campos->eq(1)->filter('#edit_nome_acao'));

        $resp = $campos->eq(0)->filter('.ps-modal-pasta-campo > .ps-pe-resp[aria-labelledby="editRespRotulo"]');
        self::assertSame($user->getFullName(), trim($resp->filter('.ps-pe-resp > [data-pe-resp-nome]')->text()));
        $trocar = $resp->filter('.ps-pe-resp > button.ps-pe-resp-trocar.js-pe-ir[data-pe-ir="responsavel"]');
        self::assertSame('Trocar', trim($trocar->text()));
        self::assertSame('button', $trocar->attr('type'));
        self::assertCount(0, $campos->eq(0)->filter('[name]'), 'nada da linha vai no POST do pasta_edit');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("var trocar = document.getElementById('psMenuTrocarResponsavel');\n            if (trocar) { trocar.click(); }", $html);
    }

    #[TestDox('PAS-021: sem responsável, "Sem responsável"; sem permissão de editar, sem "Trocar"')]
    public function testSemResponsavelESemPermissao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $leitor = $this->criarUsuarioSemPermissaoDoModulo($tenant);
        $this->logarComTenant($client, $leitor, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $resp = $crawler->filter('#modalEditarPasta .ps-pe-resp');
        self::assertSame('Sem responsável', trim($resp->filter('[data-pe-resp-nome]')->text()));
        self::assertCount(0, $resp->filter('.ps-pe-resp-trocar'), 'quem só lê não troca o responsável');
    }

    #[TestDox('PAS-020: pílula "Dados da pasta" na linha do subtítulo, bloco "Pendências" escondido e o script que trava o Salvar')]
    public function testPilulaSalvarEPendencias(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $sub = $crawler->filter('#modalEditarPasta .ps-modal-pasta-cab-corpo > .ps-modal-pasta-sub');
        self::assertCount(1, $sub, 'a linha existe mesmo sem cliente: ela leva a pílula');
        $pilula = $sub->filter('.ps-modal-pasta-sub > .ps-pe-estado[data-pe-estado]');
        self::assertSame('Dados da pasta', trim($pilula->text()));
        self::assertCount(1, $pilula->filter('.ps-pe-estado > .ps-pe-estado-ponto'));

        $pend = $crawler->filter('#modalEditarPasta form > .ps-modal-pasta-corpo > .ps-pe-pendencias[role="alert"][data-pe-pendencias]');
        self::assertCount(1, $pend, 'o bloco fica no fim do corpo, dentro do formulário');
        self::assertNotNull($pend->attr('hidden'));
        self::assertSame('Pendências', trim($pend->filter('.ps-pe-pendencias-rot')->text()));
        self::assertCount(1, $pend->filter('.ps-pe-pendencias-linha > i.bi-x-circle-fill'));

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("var foto = serializar();", $html, 'o retrato é o do servidor, tirado ao carregar');
        self::assertStringContainsString("estadoTxt.textContent = mudou ? 'Alterações não salvas' : 'Dados da pasta';", $html);
        self::assertStringContainsString("var erro = nup && nup.value.trim() === '' ? 'Informe o número da pasta.' : '';", $html);
        self::assertStringContainsString('salvar.disabled = !(mudou && !erro);', $html);
        self::assertStringContainsString('pendencias.hidden = !(mudou && erro);', $html);
    }

    #[TestDox('PAS-019: trocar o número pede a confirmação do desenho antes de enviar; o número antigo é o do servidor')]
    public function testConfirmaTrocaDoNumero(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);
        self::assertSame($pasta->getNup(), $crawler->filter('#edit_nup')->attr('value'), 'o defaultValue que o script compara');

        $html = (string) $client->getResponse()->getContent();
        // E7: a pergunta é calculada no envio e vai para a janela AZUL da tela (psConfirmarEnvio,
        // dc `confirmarUI(…, false)` L.4416), que segura o envio até o "sim".
        $ini  = strpos($html, 'var perguntaDoNumero = function () {');
        self::assertNotFalse($ini, 'a confirmação fica no envio do formulário');
        $trecho = substr($html, $ini, 1200);
        self::assertStringContainsString("if (depois === '' || depois === antes) { return null; }", $trecho, 'só quando o número muda');
        self::assertStringContainsString("mensagem: 'Alterar número da pasta\\nAlterar o número da pasta de ' + antes + ' para ' + depois + '? Links e referências antigas passam a apontar para o novo número.',", $trecho);
        self::assertStringContainsString('perigo: false', $trecho);
        self::assertStringContainsString('window.psConfirmarEnvio(form, perguntaDoNumero)', $trecho);
        self::assertStringContainsString('e.preventDefault();', $trecho, 'sem a janela, Cancelar no confirm não envia');
    }
}
