<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Aba Detalhes × desenho "02 - EXPEDIENTES 1.2.3" (auditoria 2 da Pasta, lote L6).
 *
 * T2: a edição de uma observação é montada em JS (`_detalhes_obs.html.twig`). No desenho
 * (`bj-editor`, modo edição) os botões moram no rodapé DO editor: "Visível para a equipe"
 * · espaço · Cancelar (transparente) · Salvar (primário), à direita. Antes ficavam fora
 * do editor, à esquerda, com Salvar primeiro. O PHPUnit não roda o JS, então o teste lê o
 * montador no HTML entregue e confere a estrutura e a ORDEM dos botões.
 *
 * T1: a lista de observações não tem teto de altura (cresce com a página).
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaDetalhesEdicaoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /** Trecho do script que monta a edição no lugar (do clique em editar até o de excluir). */
    private function montadorDaEdicao(): string
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $inicio = strpos($html, "e.target.closest('.btn-editar-obs-det')");
        $fim    = strpos($html, "e.target.closest('.btn-excluir-obs-det')");
        self::assertNotFalse($inicio, 'o montador da edição de Detalhes está na página');
        self::assertNotFalse($fim);
        self::assertGreaterThan($inicio, $fim);

        return substr($html, $inicio, $fim - $inicio);
    }

    #[TestDox('T2: a edição fica dentro da moldura do editor, com o rodapé de edição')]
    public function testEdicaoDentroDaMolduraDoEditor(): void
    {
        $js = $this->montadorDaEdicao();

        self::assertStringContainsString("moldura.className = 'ps-compositor-caixa'", $js);
        self::assertStringContainsString("rodape.className = 'ps-anotacao-edicao-rodape'", $js);
        self::assertStringContainsString('moldura.appendChild(textarea);', $js);
        self::assertStringContainsString('moldura.appendChild(rodape);', $js);
        self::assertStringContainsString('caixa.appendChild(moldura);', $js);
        self::assertStringContainsString("nota.textContent = 'Visível para a equipe'", $js);
        self::assertStringNotContainsString('acoesBtns', $js, 'a fileira antiga de botões fora do editor saiu');
    }

    #[TestDox('T2: rodapé na ordem do desenho — nota · espaço · Cancelar (transparente) · Salvar (primário)')]
    public function testOrdemEVarianteDosBotoes(): void
    {
        $js = $this->montadorDaEdicao();

        self::assertStringContainsString("btnCancelar.className = 'ps-anotacao-edicao-cancelar'", $js);
        self::assertStringContainsString("btnConfirmar.className = 'ps-btn ps-btn--primario'", $js);

        $ordem = array_map(
            static fn (string $trecho): int|false => strpos($js, $trecho),
            [
                'rodape.appendChild(nota);',
                'rodape.appendChild(espaco);',
                'rodape.appendChild(btnCancelar);',
                'rodape.appendChild(btnConfirmar);',
            ],
        );
        self::assertNotContains(false, $ordem);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem, 'Cancelar vem antes de Salvar, ambos depois do espaço');
    }

    #[TestDox('T1: a lista de observações de Detalhes não tem teto de altura nem rolagem interna')]
    public function testListaSemTetoDeAltura(): void
    {
        $css = (string) file_get_contents(\dirname(__DIR__, 3) . '/public/css/pasta-show.css');

        self::assertSame(0, preg_match('/\.ps-detalhes-rolagem\s*\{[^}]*(max-height|overflow)/', $css));
    }
}
