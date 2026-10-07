<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Trava o alcance do `PastaSomenteLeituraListener` (D-DOC-RO): pasta excluída (lápide) não aceita
 * escrita por NENHUMA rota.
 *
 * O listener enxerga a pasta pelos argumentos da action. Cinco rotas recebiam só o `int` da filha
 * (documento, seção) e passavam por ele invisíveis — ninguém percebeu, porque pasta riscada é caso
 * raro que ninguém testa à mão. Este teste percorre o router de verdade (não grep) e exige, de toda
 * rota de escrita `pasta_*` (POST/PUT/PATCH/DELETE, ou sem restrição de método), UM destes:
 *
 *  1. a action recebe `Pasta` ou uma entidade com `getPasta()` (o listener a vê direto);
 *  2. a action declara `#[PastaPelaFilha]`, coerente com a rota e com a entidade;
 *  3. a rota está numa lista de exceção do listener (`ROTAS_LIBERADAS`, `ROTAS_SEM_PASTA_EXISTENTE`),
 *     onde cada entrada tem o motivo escrito.
 *
 * Rota nova que não se encaixar quebra aqui, e a correção é escolher conscientemente um dos três.
 */
#[CoversNothing]
final class PastaSomenteLeituraRotasArquiteturaTest extends KernelTestCase
{
    private const METODOS_DE_ESCRITA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array<string, Route> */
    private static function rotasDeEscritaDaPasta(): array
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $rotas = [];
        foreach ($router->getRouteCollection()->all() as $nome => $rota) {
            if (!str_starts_with($nome, 'pasta_')) {
                continue;
            }

            $metodos = $rota->getMethods();
            // Sem restrição de método a rota aceita POST também — e o listener age sobre ela.
            if ($metodos !== [] && array_intersect($metodos, self::METODOS_DE_ESCRITA) === []) {
                continue;
            }

            $rotas[$nome] = $rota;
        }

        return $rotas;
    }

    private static function action(Route $rota): \ReflectionMethod
    {
        $controller = (string) $rota->getDefault('_controller');
        [$classe, $metodo] = str_contains($controller, '::')
            ? explode('::', $controller, 2)
            : [$controller, '__invoke'];

        return new \ReflectionMethod($classe, $metodo);
    }

    /** O critério do `pastaDosArgumentos()` do listener, aplicado à assinatura. */
    private static function recebeAPasta(\ReflectionMethod $action): bool
    {
        foreach ($action->getParameters() as $parametro) {
            $tipo = $parametro->getType();
            if (!$tipo instanceof \ReflectionNamedType || $tipo->isBuiltin()) {
                continue;
            }

            $classe = $tipo->getName();
            if (is_a($classe, Pasta::class, true) || (class_exists($classe) && method_exists($classe, 'getPasta'))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function excecoes(): array
    {
        return [
            ...PastaSomenteLeituraListener::ROTAS_LIBERADAS,
            ...PastaSomenteLeituraListener::ROTAS_SEM_PASTA_EXISTENTE,
        ];
    }

    #[TestDox('toda rota de escrita pasta_* é alcançada pelo listener ou está numa exceção documentada')]
    public function testTodaRotaDeEscritaEstaCoberta(): void
    {
        $rotas = self::rotasDeEscritaDaPasta();

        // Sem isto o teste passaria vazio se o prefixo mudasse ou o router não carregasse.
        self::assertGreaterThan(50, count($rotas), 'o router devolveu rotas de escrita pasta_* de menos — o filtro do teste quebrou?');

        $descobertas = [];
        foreach ($rotas as $nome => $rota) {
            if (in_array($nome, self::excecoes(), true)) {
                continue;
            }

            $action = self::action($rota);
            if (self::recebeAPasta($action) || $action->getAttributes(PastaPelaFilha::class) !== []) {
                continue;
            }

            $descobertas[] = sprintf('%s (%s::%s)', $nome, $action->getDeclaringClass()->getShortName(), $action->getName());
        }

        self::assertSame(
            [],
            $descobertas,
            "Rota de escrita pasta_* que o PastaSomenteLeituraListener não alcança — pasta excluída aceitaria escrita por ela.\n"
            . "Receba a entidade (Pasta ou filha com getPasta()), declare #[PastaPelaFilha] ou registre a exceção, com o motivo, no listener.",
        );
    }

    #[TestDox('cada #[PastaPelaFilha] aponta para variável da rota e para entidade com getPasta() e tenant')]
    public function testDeclaracoesPelaFilhaSaoCoerentes(): void
    {
        $rotas      = self::rotasDeEscritaDaPasta();
        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $declaradas = 0;

        foreach ($rotas as $nome => $rota) {
            foreach (self::action($rota)->getAttributes(PastaPelaFilha::class) as $atributo) {
                $declaracao = $atributo->newInstance();
                ++$declaradas;

                self::assertContains(
                    $declaracao->argumento,
                    $rota->compile()->getVariables(),
                    "{$nome}: '{$declaracao->argumento}' não é variável da rota — o listener não acharia o id.",
                );
                self::assertTrue(
                    method_exists($declaracao->entidade, 'getPasta'),
                    "{$nome}: {$declaracao->entidade} não tem getPasta().",
                );
                self::assertTrue(
                    $em->getClassMetadata($declaracao->entidade)->hasAssociation('tenant'),
                    "{$nome}: {$declaracao->entidade} não tem associação tenant — a busca do listener não seria escopada.",
                );
            }
        }

        self::assertGreaterThanOrEqual(5, $declaradas);
    }

    #[TestDox('as cinco rotas que recebem o int da filha declaram de onde vem a pasta')]
    public function testAsCincoRotasPorIdDeFilhaDeclaramAPasta(): void
    {
        $rotas = self::rotasDeEscritaDaPasta();

        foreach (['pasta_documento_edit', 'pasta_secao_renomear', 'pasta_secao_excluir', 'pasta_secao_mover', 'pasta_documento_mover_secao'] as $nome) {
            self::assertArrayHasKey($nome, $rotas);
            self::assertNotSame([], self::action($rotas[$nome])->getAttributes(PastaPelaFilha::class), $nome);
        }
    }

    #[TestDox('as listas de exceção do listener só têm rotas de escrita pasta_* que existem')]
    public function testExcecoesNaoEnvelhecem(): void
    {
        $rotas = self::rotasDeEscritaDaPasta();

        foreach (self::excecoes() as $nome) {
            self::assertArrayHasKey($nome, $rotas, "exceção '{$nome}' não é (mais) rota de escrita pasta_* — tire da lista.");
        }

        // Exceção é decisão, não esquecimento: as estrelas seguem recusadas (precedente PastaFavorita).
        self::assertNotContains('pasta_favorito_alternar', self::excecoes());
        self::assertNotContains('pasta_documentos_favorito', self::excecoes());
    }
}
