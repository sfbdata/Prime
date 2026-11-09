<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ModelosDaAssinaturaController;
use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\Tests\AssinaturaEletronica\Unit\ValidadorDoModeloTest;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * As rotas dos modelos do escritório pela HTTP (contrato da etapa 4, seção 4): porta do módulo e da chave do Master,
 * CSRF, modelo de outro escritório (404 sem mudar nada), recusas com o texto (422) e o documento feito com o modelo,
 * que não muda quando o modelo sai. O banco é desfeito pelo DAMA ao fim de cada teste.
 */
#[CoversClass(ModelosDaAssinaturaController::class)]
final class ModelosDaAssinaturaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
    }

    /** @return iterable<string, array{string, string}> */
    public static function rotas(): iterable
    {
        yield 'lista' => ['GET', '/assinatura/modelos'];
        yield 'criar' => ['POST', '/assinatura/modelos'];
        yield 'salvar' => ['POST', '/assinatura/modelos/1'];
        yield 'excluir' => ['POST', '/assinatura/modelos/1/excluir'];
    }

    #[DataProvider('rotas')]
    #[TestDox('sem o módulo: 403 em $_dataName')]
    public function testSemModulo(string $metodo, string $url): void
    {
        $this->entrar(false);

        $this->client->request($metodo, $url, [], [], ['HTTP_X_CSRF_TOKEN' => self::CSRF, 'CONTENT_TYPE' => 'application/json'], (string) json_encode(self::entrada()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Você não tem acesso à Assinatura de documentos.', $this->json()['erro'] ?? null);
    }

    #[TestDox('com a chave do Master desligada (como nasce): 503 na lista de modelos para quem não é Master')]
    public function testChaveDoMasterDesligada(): void
    {
        $this->entrar(true, chaveLigada: false);

        $this->client->request('GET', '/assinatura/modelos', [], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(503);
    }

    /** @return iterable<string, array{string}> */
    public static function postagens(): iterable
    {
        yield 'criar' => ['/assinatura/modelos'];
        yield 'salvar' => ['/assinatura/modelos/%d'];
        yield 'excluir' => ['/assinatura/modelos/%d/excluir'];
    }

    #[DataProvider('postagens')]
    #[TestDox('POST sem o X-CSRF-Token válido: 403 e nada muda em $_dataName')]
    public function testSemCsrf(string $url): void
    {
        $this->entrar(true);
        $id = $this->criar(self::entrada(['nome' => 'Original']));

        $this->client->request('POST', sprintf($url, $id), [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], (string) json_encode(self::entrada(['nome' => 'Mudado'])));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['Original'], array_column($this->listarModelos(), 'nome'));
    }

    #[TestDox('criar, salvar e listar: 201 com o id, a chave esc-<id>, o conteúdo normalizado e o alterado por último em cima')]
    public function testCriarSalvarEListar(): void
    {
        $this->entrar(true);
        $primeiro = $this->criar(self::entrada(['nome' => 'Procuração', 'categoria' => '', 'folhaBranca' => true]));
        self::assertResponseStatusCodeSame(201);
        $segundo = $this->criar(self::entrada(['nome' => 'Contrato', 'layout' => '2col', 'lateral' => 'CONTRATANTE']));
        $this->envelhecer($primeiro, 2);
        $this->envelhecer($segundo, 1);

        $lista = $this->listarModelos();
        self::assertSame([$segundo, $primeiro], array_column($lista, 'id'), 'o mais recente primeiro');
        $procuracao = $lista[1];
        self::assertSame('esc-' . $primeiro, $procuracao['chave']);
        self::assertSame(['Procuração', 'Meus modelos', '', '', true], [$procuracao['nome'], $procuracao['categoria'], $procuracao['layout'], $procuracao['lateral'], $procuracao['folhaBranca']]);
        self::assertSame([['nome', 'Nome completo', 1]], $procuracao['campos']);
        self::assertSame([['papel' => 'Outorgante']], $procuracao['signatarios']);
        self::assertSame([['t' => 'PROCURAÇÃO'], ['p' => 'Eu, [[nome]], nomeio.', 'full' => 1], ['a' => 0, 'r' => '[[nome]]']], $procuracao['corpo']);
        self::assertSame(['2col', 'CONTRATANTE'], [$lista[0]['layout'], $lista[0]['lateral']]);

        $this->postarJson('/assinatura/modelos/' . $primeiro, self::entrada(['nome' => 'Procuração ad judicia']));
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $primeiro], $this->json());

        $lista = $this->listarModelos();
        self::assertSame([$primeiro, $segundo], array_column($lista, 'id'), 'o salvo sobe para o topo');
        self::assertSame('Procuração ad judicia', $lista[0]['nome']);
        self::assertFalse($lista[0]['folhaBranca'], 'Editar carrega a folha branca: o que vem no salvar vale');
    }

    #[TestDox('nomes repetidos são aceitos (Duplicar e adaptar cria cópias com o mesmo nome)')]
    public function testNomeRepetido(): void
    {
        $this->entrar(true);
        $this->criar(self::entrada(['nome' => 'Procuração']));
        $this->criar(self::entrada(['nome' => 'Procuração']));

        self::assertSame(['Procuração', 'Procuração'], array_column($this->listarModelos(), 'nome'));
    }

    #[TestDox('excluir: o modelo sai da lista, e o documento feito com ele guarda a chave e os blocos dele')]
    public function testExcluirNaoMudaODocumento(): void
    {
        $this->entrar(true);
        $id = $this->criar(self::entrada());
        $documento = $this->salvar(null, ['nome' => 'Procuração da Ana', 'modelo' => 'esc-' . $id, 'blocos' => [['k' => 'p', 'x' => 'Eu, Ana, nomeio.'], ['k' => 'a', 'i' => 0, 'r' => 'Ana']]]);
        $antes = $this->detalhe($documento);

        $this->postarJson('/assinatura/modelos/' . $id . '/excluir', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true], $this->json());

        self::assertSame([], $this->listarModelos());
        $depois = $this->detalhe($documento);
        self::assertSame('esc-' . $id, $depois['modelo']);
        self::assertSame($antes['blocos'], $depois['blocos']);

        $this->postarJson('/assinatura/modelos/' . $id . '/excluir', []);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Modelo não encontrado.', $this->json()['erro'] ?? null);
    }

    #[TestDox('modelo de outro escritório: 404 ao salvar e ao excluir, ele não muda e não aparece na lista daqui')]
    public function testOutroEscritorio(): void
    {
        $this->entrar(true);
        $alheio = $this->criar(self::entrada(['nome' => 'Do outro escritório']));
        $this->entrar(true);

        self::assertSame([], $this->listarModelos(), 'a lista não vaza o modelo do outro escritório');

        $this->postarJson('/assinatura/modelos/' . $alheio, self::entrada(['nome' => 'Invadido']));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('Modelo não encontrado.', $this->json()['erro'] ?? null);

        $this->postarJson('/assinatura/modelos/' . $alheio . '/excluir', []);
        self::assertResponseStatusCodeSame(404);

        $linha = $this->conexao()->fetchAssociative('SELECT nome FROM assinatura_eletronica_modelo WHERE id = ?', [$alheio]);
        self::assertSame(['nome' => 'Do outro escritório'], $linha, 'o modelo do outro escritório segue como estava');
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function recusas(): iterable
    {
        yield 'nome vazio' => [['nome' => ' '], 'Informe o nome do modelo.'];
        yield 'nome acima de 200' => [['nome' => str_repeat('n', 201)], 'O nome do modelo pode ter até 200 caracteres.'];
        yield 'trecho acima do limite' => [['corpo' => [['p' => str_repeat('a', 20001)], ['a' => 0]]], 'Um trecho do modelo passa de 20.000 caracteres (posição 1).'];
        yield 'bloco desconhecido' => [['corpo' => [['p' => 'Texto.'], ['img' => '<img src=x onerror=alert(1)>'], ['a' => 0]]], 'O texto do modelo tem um bloco inválido (posição 2).'];
        yield 'campo fora do catálogo' => [['campos' => [['nome', 'Nome'], ['cpf']]], 'O modelo tem um campo inválido (posição 2).'];
        yield 'campo com colchete' => [['campos' => [['no]me', 'Nome']]], 'O nome do campo 1 deve ter de 1 a 60 caracteres, sem colchetes e sem começar com ^.'];
        yield 'campo começando com ^' => [['campos' => [['^Nome', 'Nome']]], 'O nome do campo 1 deve ter de 1 a 60 caracteres, sem colchetes e sem começar com ^.'];
        yield 'assinatura de quem não existe' => [['corpo' => [['p' => 'Texto.'], ['a' => 3]]], 'O texto do modelo tem um lugar de assinatura inválido (posição 2).'];
        yield 'sem assinatura' => [['corpo' => [['p' => 'Texto.']]], 'Inclua ao menos uma assinatura.'];
        yield 'folha branca que não é booleano' => [['folhaBranca' => 'sim'], 'Os dados do modelo não puderam ser lidos. Recarregue a página e tente de novo.'];
        yield 'HTML no pedido' => [['html' => '<p onclick="x()">Texto</p>'], 'Os dados do modelo não puderam ser lidos. Recarregue a página e tente de novo.'];
    }

    /** @param array<string, mixed> $troca */
    #[DataProvider('recusas')]
    #[TestDox('422 com o texto da regra, e nada é gravado: $_dataName')]
    public function testRecusa(array $troca, string $erro): void
    {
        $this->entrar(true);

        $this->postarJson('/assinatura/modelos', self::entrada($troca));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => $erro], $this->json());
        self::assertSame([], $this->listarModelos());
    }

    #[TestDox('pedido acima de 4 MiB: 422 com o texto antes de ler o JSON, nada gravado; o maior modelo válido passa')]
    public function testTetoDoPedido(): void
    {
        $this->entrar(true);

        $grande = '{"nome":"' . str_repeat('a', DadosDoModelo::PEDIDO_MAXIMO) . '"}';
        $this->client->request('POST', '/assinatura/modelos', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], $grande);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'O modelo passa do tamanho que pode ser salvo. Divida o texto em mais de um modelo.'], $this->json());
        self::assertSame([], $this->listarModelos());

        $maior = json_encode(ValidadorDoModeloTest::maiorModeloValido(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $this->client->request('POST', '/assinatura/modelos', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], $maior);
        self::assertResponseStatusCodeSame(201);
    }

    #[TestDox('422 ao salvar não muda o modelo; corpo que não é JSON também é 422 com o texto')]
    public function testRecusaAoSalvarEJsonQuebrado(): void
    {
        $this->entrar(true);
        $id = $this->criar(self::entrada(['nome' => 'Original']));

        $this->postarJson('/assinatura/modelos/' . $id, self::entrada(['nome' => 'Mudado', 'layout' => '2col', 'corpo' => [['p' => 'Sem título.'], ['a' => 0]]]));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('No formato de folha única, marque uma linha como Título.', $this->json()['erro'] ?? null);

        $this->client->request('POST', '/assinatura/modelos/' . $id, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => self::CSRF], '{"nome": ');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Os dados do modelo não puderam ser lidos. Recarregue a página e tente de novo.', $this->json()['erro'] ?? null);

        self::assertSame(['Original'], array_column($this->listarModelos(), 'nome'));
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private static function entrada(array $troca = []): array
    {
        return array_merge([
            'nome' => 'Procuração simples',
            'categoria' => 'Procurações',
            'layout' => '',
            'lateral' => '',
            'folhaBranca' => false,
            'campos' => [['nome', 'Nome completo', 1]],
            'signatarios' => [['papel' => 'Outorgante']],
            'corpo' => [['t' => 'PROCURAÇÃO'], ['p' => 'Eu, [[nome]], nomeio.', 'full' => 1], ['a' => 0, 'r' => '[[nome]]']],
        ], $troca);
    }

    /** @param array<string, mixed> $entrada */
    private function criar(array $entrada): int
    {
        $this->postarJson('/assinatura/modelos', $entrada);

        return (int) ($this->json()['id'] ?? 0);
    }

    /** Recua o `alterado_em` em horas: os pedidos do teste caem no mesmo segundo. */
    private function envelhecer(int $id, int $horas): void
    {
        $this->conexao()->executeStatement(sprintf("UPDATE assinatura_eletronica_modelo SET alterado_em = alterado_em - INTERVAL '%d hours' WHERE id = ?", $horas), [$id]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** @return list<array<string, mixed>> */
    private function listarModelos(): array
    {
        $this->client->request('GET', '/assinatura/modelos');
        self::assertResponseIsSuccessful();

        return $this->json()['modelos'];
    }
}
