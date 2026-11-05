<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Expediente\Entity\Marcador;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Auditoria 5.07: o nome do marcador é texto livre e vai para a lista do modal "Mover para" dentro de
 * um `<script>`. Sem as flags HEX do `json_encode`, um nome como `<!--<script>` levava o navegador a
 * engolir o resto da tela da pasta como texto do script. Com elas, `<`, `>`, `&` e aspas saem como
 * `<` e afins: o script compila, o resto da tela existe e o nome chega igual ao digitado.
 */
#[CoversClass(PastaController::class)]
final class MarcadorNoScriptDoMoverParaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;
    use CriaUsuarioComPermissoesTrait;

    /** @return iterable<string, array{0: string}> */
    public static function nomesHostis(): iterable
    {
        yield 'fecha o script' => ['</script><b>x'];
        yield 'abre comentário e script' => ['<!--<script>'];
        yield 'aspas e e-comercial' => ['A & B "x" \'y\''];
    }

    #[DataProvider('nomesHostis')]
    #[TestDox('Marcador chamado $_dataName não quebra o script do modal Mover para')]
    public function testNomeDoMarcadorNaoQuebraOScript(string $nome): void
    {
        $client  = static::createClient();
        $tenant  = $this->criarTenant();
        $pasta   = $this->criarPasta($tenant);
        $usuario = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'resources.pasta.view']);
        $this->em()->persist(new Marcador($nome, $tenant, $usuario));
        $this->em()->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $script = $crawler->filter('script')->reduce(
            static fn ($no): bool => str_contains($no->text(''), 'var todosMarcadores'),
        );
        self::assertCount(1, $script, 'o script do modal Mover para continua sendo um script só');
        self::assertSame(1, preg_match('/var todosMarcadores\s*=\s*(\[.*?\]);/s', $script->text(''), $partes));
        // As aspas duplas que sobram são as do próprio JSON; dentro da string elas saem como \u0022.
        foreach (['<', '>', '&', "'"] as $perigoso) {
            self::assertStringNotContainsString($perigoso, $partes[1], 'o JSON do script não leva ' . $perigoso . ' cru');
        }
        $lista = json_decode($partes[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertContains($nome, array_column($lista, 'nome'), 'o nome chega igual ao digitado');

        self::assertStringNotContainsString('<!--<script>', $html);
        self::assertStringNotContainsString('</script><b>x', $html);
        self::assertCount(1, $crawler->filter('#modalCriarTarefa'), 'o modal de Nova meta, depois do include, segue no DOM');
    }
}
