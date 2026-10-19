<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Attribute\PastaPorId;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\BarreiraDeAbaOcultaListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * O mapa rota → aba da barreira (spec pasta-ocultar-aba §4) conferido contra o roteador de verdade:
 * nome ou prefixo que não casa com nenhuma rota é mapa podre (rota renomeada passaria destravada), e
 * rota alcançada de onde a barreira não consegue tirar a pasta seria uma barreira de enfeite.
 */
#[CoversClass(BarreiraDeAbaOcultaListener::class)]
#[Group('pasta')]
final class BarreiraDeAbaOcultaMapaTest extends KernelTestCase
{
    /** @return array<string, Route> */
    private function rotas(): array
    {
        return static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all();
    }

    #[TestDox('Todo nome ou prefixo do mapa casa com ao menos uma rota, e toda aba do mapa existe')]
    public function testMapaCasaComORoteador(): void
    {
        $nomes = array_keys($this->rotas());
        foreach (BarreiraDeAbaOcultaListener::MAPA as $padrao => $aba) {
            self::assertNotNull(AbaDaPasta::tryFrom($aba), $aba);
            $casam = array_filter($nomes, static fn (string $n): bool => str_ends_with($padrao, '*')
                ? str_starts_with($n, substr($padrao, 0, -1))
                : $n === $padrao);
            self::assertNotEmpty($casam, "o padrão {$padrao} não casa com nenhuma rota");
        }
    }

    #[TestDox('Toda rota alcançada pelo mapa recebe a pasta (ou uma filha), ou declara #[PastaPelaFilha]/#[PastaPorId]')]
    public function testTodaRotaAlcancadaTemDeOndeTirarAPasta(): void
    {
        $alcancadas = 0;
        foreach ($this->rotas() as $nome => $rota) {
            if (BarreiraDeAbaOcultaListener::abaDaRota($nome) === null) {
                continue;
            }
            ++$alcancadas;
            self::assertTrue($this->acaoAchaAPasta((string) $rota->getDefault('_controller')), "a rota {$nome} não dá à barreira de onde tirar a pasta");
        }

        self::assertGreaterThan(40, $alcancadas, 'o mapa alcança as rotas das seis abas');
    }

    #[TestDox('As rotas da meta (tarefa_*), da tela e do ocultar aba ficam fora do mapa')]
    public function testForaDoMapa(): void
    {
        foreach (['tarefa_show', 'pasta_show', 'pasta_aba_equipe', 'pasta_aba_ocultar_todos', 'pasta_resumo_imprimir', 'pasta_timeline'] as $nome) {
            self::assertNull(BarreiraDeAbaOcultaListener::abaDaRota($nome), $nome);
        }
        self::assertSame(AbaDaPasta::Push, BarreiraDeAbaOcultaListener::abaDaRota('pasta_push_links_gerar'));
        self::assertSame(AbaDaPasta::Documentos, BarreiraDeAbaOcultaListener::abaDaRota('pasta_documento_download'));
    }

    private function acaoAchaAPasta(string $controller): bool
    {
        [$classe, $metodo] = explode('::', $controller) + [1 => '__invoke'];
        $acao = new \ReflectionMethod($classe, $metodo);
        if ($acao->getAttributes(PastaPelaFilha::class) !== [] || $acao->getAttributes(PastaPorId::class) !== []) {
            return true;
        }

        foreach ($acao->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if (!$tipo instanceof \ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }
            $nomeDoTipo = $tipo->getName();
            if ($nomeDoTipo === Pasta::class || (class_exists($nomeDoTipo) && method_exists($nomeDoTipo, 'getPasta') && str_contains($nomeDoTipo, '\\Entity\\'))) {
                return true;
            }
        }

        return false;
    }
}
