<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Entity\Tarefa\TarefaMensagem;
use App\Pasta\Attribute\PastaPelaFilha;
use App\Pasta\Attribute\PastaPorId;
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
 * escrita por rota que ENVOLVA pasta.
 *
 * O listener enxerga a pasta pelos argumentos da action. Rotas que recebiam só o `int` — da filha
 * (documento, seção) ou da própria pasta (marcadores do Expediente, meta criada pela pasta) —
 * passavam por ele invisíveis, e as duas últimas nem tinham nome `pasta_*`. Este teste percorre o
 * router INTEIRO (não grep) e, de toda rota de escrita (POST/PUT/PATCH/DELETE, ou sem restrição de
 * método) que envolva pasta, exige UM destes:
 *
 *  1. a action recebe `Pasta`, uma entidade com `getPasta()` ou uma que chega a ela por
 *     `getTarefa()->getPasta()` (`TarefaMensagem`) — o listener a vê direto;
 *  2. a action declara `#[PastaPelaFilha]` ou `#[PastaPorId]`, coerente com a rota;
 *  3. a rota está numa lista de exceção do listener (`ROTAS_LIBERADAS`, `ROTAS_SEM_PASTA_EXISTENTE`),
 *     onde cada entrada tem o motivo escrito.
 *
 * "Envolve pasta" é qualquer um destes sinais: nome `pasta_*`; `/pasta/` no path; variável de rota
 * `pastaId`, `pasta_id` ou `pasta`; action que recebe a pasta, uma filha ou uma neta pela meta.
 * Rota que traga o id da pasta só no corpo da requisição não dá nenhum sinal — nem para este
 * teste, nem para o listener.
 *
 * Rota nova que não se encaixar quebra aqui, e a correção é escolher conscientemente um dos três.
 */
#[CoversNothing]
final class PastaSomenteLeituraRotasArquiteturaTest extends KernelTestCase
{
    private const METODOS_DE_ESCRITA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private const VARIAVEIS_DE_PASTA = ['pastaId', 'pasta_id', 'pasta'];

    /** As duas rotas fora do prefixo `pasta_` que recebem o id cru da pasta (achado da revisão do P14). */
    private const ROTAS_POR_ID_DA_PASTA = [
        'expediente_pasta_marcadores' => 'id',
        'tarefa_criar_para_pasta'     => 'pastaId',
    ];

    /** @return array<string, Route> todas as rotas de escrita do router, de qualquer prefixo */
    private static function rotasDeEscrita(): array
    {
        // getContainer() sobe o kernel só se ainda não subiu: chamar duas vezes no mesmo teste não
        // reinicia o container (e não invalida serviço já obtido, como o EntityManager).
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $rotas = [];
        foreach ($router->getRouteCollection()->all() as $nome => $rota) {
            $metodos = $rota->getMethods();
            // Sem restrição de método a rota aceita POST também — e o listener age sobre ela.
            if ($metodos !== [] && array_intersect($metodos, self::METODOS_DE_ESCRITA) === []) {
                continue;
            }

            $rotas[$nome] = $rota;
        }

        return $rotas;
    }

    /** @return array<string, Route> as rotas de escrita que envolvem pasta (ver o docblock da classe) */
    private static function rotasDeEscritaComPasta(): array
    {
        return array_filter(
            self::rotasDeEscrita(),
            static fn (Route $rota, string $nome): bool => self::envolvePasta($nome, $rota),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function envolvePasta(string $nome, Route $rota): bool
    {
        if (str_starts_with($nome, 'pasta_') || str_contains($rota->getPath(), '/pasta/')) {
            return true;
        }

        if (array_intersect($rota->compile()->getVariables(), self::VARIAVEIS_DE_PASTA) !== []) {
            return true;
        }

        $action = self::action($rota);

        return $action !== null && self::recebeAPasta($action);
    }

    /**
     * A action da rota, ou `null` se o `_controller` não for `Classe::metodo` de uma classe
     * carregável (rotas do framework apontam para id de serviço, e nenhuma delas é de pasta).
     */
    private static function action(Route $rota): ?\ReflectionMethod
    {
        $controller = $rota->getDefault('_controller');
        if (!is_string($controller) || $controller === '') {
            return null;
        }

        [$classe, $metodo] = str_contains($controller, '::')
            ? explode('::', $controller, 2)
            : [$controller, '__invoke'];

        if (!class_exists($classe) || !method_exists($classe, $metodo)) {
            return null;
        }

        return new \ReflectionMethod($classe, $metodo);
    }

    /** A action de uma rota que o teste exige existir — falha clara se o controller não resolver. */
    private static function actionObrigatoria(string $nome, Route $rota): \ReflectionMethod
    {
        $action = self::action($rota);
        self::assertNotNull($action, "{$nome}: o _controller não resolve para Classe::metodo — o teste não consegue ler a action.");

        return $action;
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

            if (self::chegaAPastaPelaMeta($classe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O degrau de dois níveis do `pastaDosArgumentos()`: a classe tem `getTarefa()` e o TIPO de
     * retorno declarado dele tem `getPasta()` (`TarefaMensagem` → `Tarefa` → `Pasta`). Lido pela
     * assinatura, não por instância: o teste não carrega entidade nenhuma.
     */
    private static function chegaAPastaPelaMeta(string $classe): bool
    {
        if (!class_exists($classe) || !method_exists($classe, 'getTarefa')) {
            return false;
        }

        $retorno = (new \ReflectionMethod($classe, 'getTarefa'))->getReturnType();
        if (!$retorno instanceof \ReflectionNamedType || $retorno->isBuiltin()) {
            return false;
        }

        $meta = $retorno->getName();

        return class_exists($meta) && method_exists($meta, 'getPasta');
    }

    private static function declaraAPasta(\ReflectionMethod $action): bool
    {
        return $action->getAttributes(PastaPelaFilha::class) !== []
            || $action->getAttributes(PastaPorId::class) !== [];
    }

    /** @return list<string> */
    private static function excecoes(): array
    {
        return [
            ...PastaSomenteLeituraListener::ROTAS_LIBERADAS,
            ...PastaSomenteLeituraListener::ROTAS_SEM_PASTA_EXISTENTE,
        ];
    }

    /**
     * Rotas que envolvem pasta e que o listener não alcançaria.
     *
     * @param bool $contarAtributos `false` finge que `#[PastaPelaFilha]`/`#[PastaPorId]` não
     *                              existem — usado para provar que o critério enxerga as rotas
     *                              que dependem deles.
     *
     * @return list<string>
     */
    private static function descobertas(bool $contarAtributos = true): array
    {
        $descobertas = [];
        foreach (self::rotasDeEscritaComPasta() as $nome => $rota) {
            if (in_array($nome, self::excecoes(), true)) {
                continue;
            }

            $action = self::actionObrigatoria($nome, $rota);
            if (self::recebeAPasta($action) || ($contarAtributos && self::declaraAPasta($action))) {
                continue;
            }

            $descobertas[] = sprintf('%s (%s::%s)', $nome, $action->getDeclaringClass()->getShortName(), $action->getName());
        }

        return $descobertas;
    }

    #[TestDox('toda rota de escrita que envolve pasta é alcançada pelo listener ou está numa exceção documentada')]
    public function testTodaRotaDeEscritaEstaCoberta(): void
    {
        $rotas = self::rotasDeEscritaComPasta();

        // Sem isto o teste passaria vazio se o filtro quebrasse ou o router não carregasse.
        $comPrefixo = array_filter(array_keys($rotas), static fn (string $nome): bool => str_starts_with($nome, 'pasta_'));
        self::assertGreaterThan(50, count($comPrefixo), 'o router devolveu rotas de escrita pasta_* de menos — o filtro do teste quebrou?');
        self::assertGreaterThan(count($comPrefixo), count($rotas), 'nenhuma rota fora do prefixo pasta_ foi reconhecida — o critério ampliado quebrou?');

        self::assertSame(
            [],
            self::descobertas(),
            "Rota de escrita que envolve pasta e que o PastaSomenteLeituraListener não alcança — pasta excluída aceitaria escrita por ela.\n"
            . 'Receba a entidade (Pasta ou filha com getPasta()), declare #[PastaPelaFilha]/#[PastaPorId] ou registre a exceção, com o motivo, no listener.',
        );
    }

    #[TestDox('o critério ampliado enxerga as rotas fora do prefixo pasta_: sem os atributos, elas apareceriam descobertas')]
    public function testCriterioAmpliadoPegariaAsRotasPorIdDaPasta(): void
    {
        $semAtributos = implode("\n", self::descobertas(false));

        foreach (array_keys(self::ROTAS_POR_ID_DA_PASTA) as $nome) {
            self::assertFalse(str_starts_with($nome, 'pasta_'), "{$nome}: o ponto do teste é rota FORA do prefixo.");
            self::assertStringContainsString($nome . ' (', $semAtributos, "{$nome}: sem #[PastaPorId] o critério deveria acusá-la — ele não a enxerga.");
        }
    }

    #[TestDox('cada #[PastaPelaFilha] aponta para variável da rota e para entidade com getPasta() e tenant')]
    public function testDeclaracoesPelaFilhaSaoCoerentes(): void
    {
        $em         = static::getContainer()->get(EntityManagerInterface::class);
        $declaradas = 0;

        foreach (self::rotasDeEscritaComPasta() as $nome => $rota) {
            foreach (self::actionObrigatoria($nome, $rota)->getAttributes(PastaPelaFilha::class) as $atributo) {
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

    #[TestDox('cada #[PastaPorId] aponta para variável da rota')]
    public function testDeclaracoesPorIdSaoCoerentes(): void
    {
        $declaradas = 0;

        foreach (self::rotasDeEscritaComPasta() as $nome => $rota) {
            foreach (self::actionObrigatoria($nome, $rota)->getAttributes(PastaPorId::class) as $atributo) {
                $declaracao = $atributo->newInstance();
                ++$declaradas;

                self::assertContains(
                    $declaracao->argumento,
                    $rota->compile()->getVariables(),
                    "{$nome}: '{$declaracao->argumento}' não é variável da rota — o listener não acharia o id.",
                );
            }
        }

        self::assertGreaterThanOrEqual(count(self::ROTAS_POR_ID_DA_PASTA), $declaradas);
    }

    #[TestDox('as cinco rotas que recebem o int da filha declaram de onde vem a pasta')]
    public function testAsCincoRotasPorIdDeFilhaDeclaramAPasta(): void
    {
        $rotas = self::rotasDeEscritaComPasta();

        foreach (['pasta_documento_edit', 'pasta_secao_renomear', 'pasta_secao_excluir', 'pasta_secao_mover', 'pasta_documento_mover_secao'] as $nome) {
            self::assertArrayHasKey($nome, $rotas);
            self::assertNotSame([], self::actionObrigatoria($nome, $rotas[$nome])->getAttributes(PastaPelaFilha::class), $nome);
        }
    }

    #[TestDox('as duas rotas que recebem o int da própria pasta declaram #[PastaPorId] com a variável certa')]
    public function testAsDuasRotasPorIdDaPastaDeclaramAPasta(): void
    {
        $rotas = self::rotasDeEscritaComPasta();

        foreach (self::ROTAS_POR_ID_DA_PASTA as $nome => $variavel) {
            self::assertArrayHasKey($nome, $rotas, "{$nome}: não é (mais) rota de escrita reconhecida como de pasta.");
            $atributos = self::actionObrigatoria($nome, $rotas[$nome])->getAttributes(PastaPorId::class);
            self::assertCount(1, $atributos, $nome);
            self::assertSame($variavel, $atributos[0]->newInstance()->argumento, $nome);
        }
    }

    #[TestDox('a edição da mensagem da meta chega à pasta por dois níveis (getTarefa → getPasta) e é reconhecida')]
    public function testMensagemDaMetaEReconhecidaPelosDoisNiveis(): void
    {
        $rotas = self::rotasDeEscritaComPasta();

        // O path `/tarefas/mensagem/{id}/editar` não dá nenhum dos outros sinais: só a assinatura
        // (TarefaMensagem → Tarefa → Pasta) a põe no recorte. Se o degrau de dois níveis quebrar,
        // ela some daqui — e o listener deixaria de vê-la sem o teste principal acusar.
        self::assertArrayHasKey('tarefa_mensagem_editar', $rotas, 'tarefa_mensagem_editar não é reconhecida como rota de escrita de pasta.');
        self::assertTrue(self::chegaAPastaPelaMeta(TarefaMensagem::class));
        self::assertFalse(method_exists(TarefaMensagem::class, 'getPasta'), 'TarefaMensagem ganhou getPasta(): o degrau de dois níveis deixou de ser o que a cobre.');
        self::assertTrue(self::recebeAPasta(self::actionObrigatoria('tarefa_mensagem_editar', $rotas['tarefa_mensagem_editar'])));
    }

    #[TestDox('as listas de exceção do listener só têm rotas de escrita de pasta que existem')]
    public function testExcecoesNaoEnvelhecem(): void
    {
        $rotas = self::rotasDeEscritaComPasta();

        foreach (self::excecoes() as $nome) {
            self::assertArrayHasKey($nome, $rotas, "exceção '{$nome}' não é (mais) rota de escrita de pasta — tire da lista.");
        }

        // Exceção é decisão, não esquecimento: as estrelas seguem recusadas (precedente PastaFavorita).
        self::assertNotContains('pasta_favorito_alternar', self::excecoes());
        self::assertNotContains('pasta_documentos_favorito', self::excecoes());
    }
}
