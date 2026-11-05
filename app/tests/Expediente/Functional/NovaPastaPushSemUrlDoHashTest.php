<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Expediente\Controller\ExpedienteController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 5.01 (XSS por DOM): o "Abrir nova pasta" do Push abre o Expediente com o contexto no hash da URL. A URL
 * do POST que vincula o processo à pasta nova NUNCA vem do hash (javascript: ou outro site rodariam com a sessão de
 * quem abriu o link): sai dos data-atributos que o Twig grava na tag do script, e o hash leva só identificadores.
 * O JS é lido como texto (padrão de ChatIaJsTest); o comportamento na tela é do smoke do dono.
 */
#[CoversClass(ExpedienteController::class)]
final class NovaPastaPushSemUrlDoHashTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private function js(string $arquivo): string
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/' . $arquivo);
        self::assertNotSame('', $js);

        return $js;
    }

    #[TestDox('o Expediente grava as duas URLs de vínculo na tag do script, geradas pelo Twig')]
    public function testUrlsSaemDoTwig(): void
    {
        $this->prepararCliente();
        [$primario, $tenant] = $this->criarEscritorio();
        $this->entrar($primario, $tenant);

        $crawler = $this->client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $tag = $crawler->filter('script[src*="expediente-nova-pasta-push.js"]');
        self::assertCount(1, $tag);
        self::assertSame('/push-processual/publicacoes/processo/vincular', $tag->attr('data-url-vincular-processo'));
        self::assertSame('/push-processual/publicacoes/%id%/vincular', $tag->attr('data-url-vincular-publicacao'));
    }

    #[TestDox('o script não usa acao do hash; a publicação só entra com id numérico; o Push não manda mais &acao=')]
    public function testHashSoLevaIdentificadores(): void
    {
        $js = $this->js('expediente-nova-pasta-push.js');
        self::assertDoesNotMatchRegularExpression('/partes\.acao|partes\[[\'"]acao[\'"]\]/', $js);
        self::assertStringContainsString('form.action = acao;', $js);
        self::assertStringContainsString("tag.dataset.urlVincularProcesso", $js);
        self::assertStringContainsString("/^\\d+$/.test(partes.publicacao)", $js);
        self::assertStringContainsString("tag.dataset.urlVincularPublicacao", $js);

        self::assertStringNotContainsString('&acao=', $this->js('push-processos-pasta.js'));
        self::assertStringContainsString("'&processo=1&token='", $this->js('push-processos-pasta.js'));
        self::assertStringNotContainsString('&acao=', $this->js('push-publicacoes.js'));
    }
}
