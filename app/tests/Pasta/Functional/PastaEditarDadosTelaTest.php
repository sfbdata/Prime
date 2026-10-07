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
}
