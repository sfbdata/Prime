<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Functional;

use App\Tests\Functional\JusPrimeWebTestCase;
use App\TrabalhoExterno\Controller\CurriculosController;
use App\TrabalhoExterno\Repository\ConfiguracaoCentralRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Os currículos pela HTTP (spec VS 18.10.26 §4.5): o externo cuida só do dele, a Central vê o banco,
 * gerir exige `talentos`, excluir o de outra pessoa é só do Master, a prévia do Master não escreve e
 * os arquivos são conferidos pelo conteúdo e entregues pela rota, com a permissão.
 */
#[CoversClass(CurriculosController::class)]
final class CurriculosDoTrabalhoExternoTest extends JusPrimeWebTestCase
{
    use CriaFixturesTrabalhoExternoTrait;

    private const API = '/trabalho-externo/api/curriculos';

    private KernelBrowser $client;

    /** @var array<string, true> */
    private array $chavesVistas = [];

    #[TestDox('o externo cria e edita o dele; outro escritório não vê (404); a Central vê o banco; a nota não sai para o dono; o dono exclui o dele')]
    public function testExternoCriaEditaEExclui(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        [$doA, $doB] = [$this->criarUsuario($a), $this->criarUsuario($b)];

        $this->logarComTenant($this->client, $doA, $a);
        self::assertNull($this->lista()['meu']);
        $erro = $this->postarJson($this->client, self::API . '/meu', ['lgpd' => false] + self::curriculo());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('É preciso autorizar o uso dos dados (LGPD) para enviar o currículo.', $erro['mensagem']);

        $cv = $this->postarJson($this->client, self::API . '/meu', self::curriculo())['curriculo'];
        self::assertSame(['12345', 'DF', 'Brasília'], [$cv['oabNum'], $cv['oabUf'], $cv['cidade']], 'crus no cartão, para a busca da tela');
        self::assertMatchesRegularExpression('/^CV-\d{4,}$/', $cv['codigo']);
        self::assertSame(['novo', 'Advogado(a)', 'OAB 12345/DF', 'Brasília/DF', null], [$cv['status'], $cv['situacaoRotulo'], $cv['oab'], $cv['local'], $cv['nota']]);
        self::assertTrue($cv['podeEditar']);
        $editado = $this->postarJson($this->client, self::API . '/meu', ['nome' => 'Ana Souza Lima'] + self::curriculo())['curriculo'];
        self::assertSame([$cv['codigo'], 'Ana Souza Lima'], [$editado['codigo'], $editado['nome']], 'um por usuário: editar não cria outro');
        self::assertSame($cv['codigo'], $this->lista()['meu']['codigo']);
        self::assertSame([], $this->lista()['curriculos'], 'o externo não recebe o banco');

        $this->logarComTenant($this->client, $doB, $b);
        $this->client->request('GET', self::API . '/' . $cv['codigo']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/trabalho-externo/curriculos/' . $cv['codigo'] . '/documento');
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $master, $sede);
        $lista = $this->lista();
        self::assertTrue($lista['interno'] && $lista['podeGerir'] && $lista['ehMaster']);
        self::assertNull($lista['meu']);
        self::assertSame([$cv['codigo']], array_column($lista['curriculos'], 'codigo'));
        $this->postarJson($this->client, self::API . '/' . $cv['codigo'] . '/nota', ['nota' => 'Entrevistar']);
        self::assertResponseIsSuccessful();
        self::assertSame('Entrevistar', $this->detalhe($cv['codigo'])['nota']);

        $this->logarComTenant($this->client, $doA, $a);
        self::assertNull($this->detalhe($cv['codigo'])['nota'], 'a nota interna nunca sai para o dono');
        $this->postarJson($this->client, self::API . '/' . $cv['codigo'] . '/status', ['status' => 'favorito']);
        self::assertResponseStatusCodeSame(403, 'o dono não muda a situação no banco');
        $this->postarJson($this->client, self::API . '/meu/excluir');
        self::assertResponseIsSuccessful();
        self::assertNull($this->lista()['meu']);
    }

    #[TestDox('arquivos: só depois de salvar; foto reduzida a 256×256 e entregue inline; tipo e tamanho pelo conteúdo (422); outro usuário não baixa; excluir apaga os arquivos')]
    public function testArquivos(): void
    {
        [$a, $b] = [$this->criarTenant('A'), $this->criarTenant('B')];
        [$doA, $doB] = [$this->criarUsuario($a), $this->criarUsuario($b)];
        $this->logarComTenant($this->client, $doA, $a);

        $this->enviar('foto', $this->png(600, 300));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Salve o currículo antes de anexar arquivos.', $this->json($this->client)['mensagem']);

        $codigo = $this->postarJson($this->client, self::API . '/meu', self::curriculo())['curriculo']['codigo'];
        $foto = $this->enviar('foto', $this->png(600, 300))['curriculo']['arquivos']['foto'];
        self::assertSame('foto.jpg', $foto['nome']);
        $this->client->request('GET', $foto['url']);
        self::assertResponseIsSuccessful();
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $imagem = getimagesizefromstring((string) file_get_contents($this->client->getResponse()->getFile()->getPathname()));
        self::assertSame([256, 256, 'image/jpeg'], [$imagem[0], $imagem[1], $imagem['mime']]);

        $this->enviar('oab', $this->arquivo('carteira.pdf', 'texto qualquer, não é PDF'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Envie o arquivo em PDF, JPG ou PNG.', $this->json($this->client)['mensagem']);
        $this->enviar('curriculo', $this->arquivo('grande.pdf', "%PDF-1.4\n" . str_repeat('x', 1_600_000)));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O arquivo passa de 1,5 MB. Envie um arquivo menor.', $this->json($this->client)['mensagem']);

        $cv = $this->enviar('curriculo', $this->arquivo('Meu CV.pdf', "%PDF-1.4\n%%EOF\n"))['curriculo'];
        self::assertSame('Meu CV.pdf', $cv['arquivos']['curriculo']['nome']);
        $this->client->request('GET', $cv['arquivos']['curriculo']['url']);
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $disco = $this->arquivosNoDisco();
        self::assertCount(2, $disco);

        $semFoto = $this->postarJson($this->client, self::API . '/meu/arquivos/foto/remover')['curriculo'];
        self::assertNull($semFoto['arquivos']['foto']);
        self::assertCount(1, $this->arquivosNoDisco(), 'o arquivo removido sai do disco depois do COMMIT');

        $this->enviar('oab', $this->arquivo('carteira.pdf', "%PDF-1.4\n%%EOF\n"));
        self::assertCount(2, $this->arquivosNoDisco());
        $estudante = $this->postarJson($this->client, self::API . '/meu', ['situacao' => 'est', 'inst' => 'UnB'] + self::curriculo())['curriculo'];
        self::assertNull($estudante['arquivos']['oab'], 'a carteira sai com o bloco da OAB');
        self::assertNotNull($estudante['arquivos']['curriculo']);
        self::assertCount(1, $this->arquivosNoDisco(), 'e o arquivo dela sai do disco depois do COMMIT');

        $this->enviar('foto', $this->png(4000, 3001));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A foto é grande demais. Envie uma imagem menor.', $this->json($this->client)['mensagem']);

        $this->logarComTenant($this->client, $doB, $b);
        $this->client->request('GET', "/trabalho-externo/curriculos/$codigo/arquivos/curriculo");
        self::assertResponseStatusCodeSame(404);

        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::API . '/meu/excluir');
        self::assertSame([], $this->arquivosNoDisco());
    }

    #[TestDox('Equipe sem talentos vê e marca, mas não muda status, nota nem exporta (403); só o Master exclui o de outra pessoa; CSV com o cabeçalho do desenho')]
    public function testCentral(): void
    {
        $this->ligarTermo();
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $this->definirSede($sede);
        $master = $this->criarMaster($sede);
        $equipe = $this->criarUsuario($sede, nome: 'Equipe');
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $codigo = $this->postarJson($this->client, self::API . '/meu', self::curriculo() + ['insta' => 'ana', 'vol' => 'sim'])['curriculo']['codigo'];

        $this->logarComTenant($this->client, $master, $sede);
        $cv = $this->postarJson($this->client, self::API . "/$codigo/status", ['status' => 'entrevista'])['curriculo'];
        self::assertSame(['entrevista', 'Entrevista', '#5b3f9e'], [$cv['status'], $cv['statusRotulo'], $cv['statusCor']]);
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?filtro=ativos&busca=12345');
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertStringContainsString('attachment; filename=curriculos-bluejus.csv', (string) $resposta->headers->get('Content-Disposition'));
        $linhas = explode("\n", (string) $resposta->getContent());
        self::assertSame("\u{FEFF}" . '"Nome";"E-mail";"Instagram";"Telefone";"Situação";"OAB";"UF OAB";"Instituição";"Cidade";"UF";"Cargo";"Áreas";"Modalidade";"Anos exp.";"Pretensão";"Voluntário";"Termo";"Situação no banco";"Recebido"', $linhas[0]);
        self::assertStringStartsWith('"Ana Souza";"ana@exemplo.test";"@ana";"(61) 99999-0000";"Advogado(a)";"12345";"DF"', $linhas[1]);
        self::assertStringContainsString('"Sim";"Sem termo";"Entrevista";', $linhas[1]);

        $this->semTalentos();
        $this->logarComTenant($this->client, $equipe, $sede);
        self::assertSame($codigo, $this->detalhe($codigo)['codigo'], 'toda a Equipe vê');
        self::assertFalse($this->lista()['podeGerir']);
        foreach (["/$codigo/status" => ['status' => 'favorito'], "/$codigo/nota" => ['nota' => 'x'], "/$codigo/excluir" => []] as $rota => $corpo) {
            $this->postarJson($this->client, self::API . $rota, $corpo);
            self::assertResponseStatusCodeSame(403, $rota);
        }
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->postarJson($this->client, self::API . '/marcacoes', ['codigos' => [$codigo], 'op' => 'estrela'])['aplicados'], 'a marcação é pessoal');
        self::assertTrue($this->lista()['curriculos'][0]['estrela']);

        $this->logarComTenant($this->client, $master, $sede);
        $this->postarJson($this->client, self::API . "/$codigo/excluir");
        self::assertResponseIsSuccessful();
        $this->client->request('GET', self::API . "/$codigo");
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('prévia ?como= só lê; o Master não tem "Meu currículo"; CSRF errado é 403')]
    public function testPreviaECsrf(): void
    {
        [$sede, $a] = [$this->criarTenant('Sede'), $this->criarTenant('A')];
        $master = $this->criarMaster($sede);
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::API . '/meu', self::curriculo(), 'errado');
        self::assertResponseStatusCodeSame(403);
        $codigo = $this->postarJson($this->client, self::API . '/meu', self::curriculo())['curriculo']['codigo'];

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', self::API . '?como=equipe');
        $previa = $this->json($this->client);
        self::assertTrue($previa['interno']);
        self::assertFalse($previa['podeGerir'] || $previa['ehMaster'], 'na prévia nada se escreve');
        $this->client->request('GET', '/trabalho-externo/curriculos/exportar.csv?como=equipe');
        self::assertResponseStatusCodeSame(403);
        $this->postarJson($this->client, self::API . '/meu', self::curriculo());
        self::assertResponseStatusCodeSame(403, 'o interno não tem "Meu currículo"');
        $this->postarJson($this->client, self::API . '/marcacoes?como=equipe', ['codigos' => [$codigo], 'op' => 'estrela']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->lista('?como=equipe')['curriculos'][0]['estrela'], 'a prévia não lê nem grava marcação');
    }

    #[TestDox('documento: HTML com o nome e sem script; PDF pelo Dompdf; a pré-visualização mostra o que ainda não foi salvo')]
    public function testDocumento(): void
    {
        $a = $this->criarTenant('A');
        $doA = $this->criarUsuario($a);
        $this->logarComTenant($this->client, $doA, $a);
        $cv = $this->postarJson($this->client, self::API . '/meu', self::curriculo() + ['tituloP' => 'Advogada|Professora', 'exps' => [['cargo' => 'Associada', 'org' => 'Escritório X', 'ini' => '2019', 'fim' => 'Atual']]])['curriculo'];

        $this->client->request('GET', $cv['documentoUrl']);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<h1>Ana Souza</h1>', $html);
        self::assertStringContainsString('Advogada | Professora', $html);
        self::assertStringContainsString('2019 a Atual', $html);
        self::assertStringContainsString('@media screen{html,body{overflow-x:hidden;margin:0}.pg{width:100%;max-width:210mm;min-height:0;aspect-ratio:210/297;margin:0 auto}}@media screen and (max-width:640px){.pg{grid-template-columns:1fr}.sd{padding:8mm 6mm}.mn{padding:8mm 6mm}.hd h1{font-size:18pt}}@media print', $html, 'D7: a folha cabe no iframe da prévia');
        self::assertStringContainsString("default-src 'none'", (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        $this->client->request('GET', $cv['pdfUrl']);
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
        self::assertStringStartsWith('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->client->request('POST', self::API . '/previa', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], json_encode(['nome' => 'Nome <b>Novo</b>'] + self::curriculo(), JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nome &lt;b&gt;Novo&lt;/b&gt;', (string) $this->client->getResponse()->getContent());
        self::assertSame('Ana Souza', $this->lista()['meu']['nome'], 'a pré-visualização não grava');
    }

    #[TestDox('Word (D5): o dono baixa o MESMO documento com BOM, como application/msword e "Currículo - {nome}.doc"; outro usuário baixa só o dele; sem currículo e o Master: 404')]
    public function testWordDoMeuCurriculo(): void
    {
        [$sede, $a, $b] = [$this->criarTenant('Sede'), $this->criarTenant('A'), $this->criarTenant('B')];
        $master = $this->criarMaster($sede);
        [$doA, $doB] = [$this->criarUsuario($a), $this->criarUsuario($b)];
        $this->logarComTenant($this->client, $doA, $a);
        $this->postarJson($this->client, self::API . '/meu', self::curriculo());

        $this->client->request('GET', '/trabalho-externo/curriculos/meu/word');
        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        $conteudo = (string) $resposta->getContent();
        self::assertStringStartsWith("\u{FEFF}<!doctype html>", $conteudo, 'BOM e o HTML do documento');
        self::assertStringContainsString('<h1>Ana Souza</h1>', $conteudo);
        self::assertStringContainsString('@media screen and (max-width:640px)', $conteudo, 'o documento da tela (D7)');
        self::assertSame('application/msword', $resposta->headers->get('Content-Type'));
        $disposicao = (string) $resposta->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment', $disposicao);
        self::assertStringContainsString("filename*=utf-8''Curr%C3%ADculo%20-%20Ana%20Souza.doc", $disposicao);
        self::assertStringContainsString('filename="Curriculo - Ana Souza.doc"', $disposicao);
        self::assertStringContainsString('src="http://localhost/images/logo/bluejus-logo-nav.png"', $conteudo, 'fora do sistema: o logo com a URL absoluta');
        self::assertStringContainsString('src="http://localhost/images/logo/bluejus_simbolo.svg"', $conteudo);
        self::assertStringNotContainsString('src="/', $conteudo, 'nenhum caminho relativo');

        $this->enviar('foto', $this->png(600, 300));
        $this->client->request('GET', '/trabalho-externo/curriculos/meu/word');
        $conteudo = (string) $this->client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#<img class="ft" src="data:image/jpeg;base64,[A-Za-z0-9+/=]{100,}"#', $conteudo, 'a foto é privada (download pede login): vai embutida');
        self::assertStringNotContainsString('/arquivos/foto', $conteudo);

        $this->logarComTenant($this->client, $doB, $b);
        $this->client->request('GET', '/trabalho-externo/curriculos/meu/word');
        self::assertResponseStatusCodeSame(404, 'sem currículo próprio, nada (o de outra pessoa não sai)');
        $this->postarJson($this->client, self::API . '/meu', ['nome' => 'Bruno Lima'] + self::curriculo());
        $this->client->request('GET', '/trabalho-externo/curriculos/meu/word');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('<h1>Bruno Lima</h1>', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString('Ana Souza', (string) $this->client->getResponse()->getContent());

        $this->logarComTenant($this->client, $master, $sede);
        $this->client->request('GET', '/trabalho-externo/curriculos/meu/word?como=solicitante');
        self::assertResponseStatusCodeSame(404, 'o Master não tem "Meu currículo", nem pela prévia');
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->instalarCsrf($this->client);
    }

    /** @return array<string, mixed> */
    private function lista(string $query = ''): array
    {
        $this->client->request('GET', self::API . $query);
        self::assertResponseIsSuccessful();

        return $this->json($this->client);
    }

    /** @return array<string, mixed> */
    private function detalhe(string $codigo): array
    {
        $this->client->request('GET', self::API . '/' . $codigo);
        self::assertResponseIsSuccessful();

        return $this->json($this->client)['curriculo'];
    }

    /** @return array<string, mixed> */
    private function enviar(string $tipo, UploadedFile $arquivo): array
    {
        $this->client->request('POST', self::API . "/meu/arquivos/$tipo", [], ['arquivo' => $arquivo], ['HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax']);

        return $this->json($this->client);
    }

    private function semTalentos(): void
    {
        $repo = static::getContainer()->get(ConfiguracaoCentralRepository::class);
        $cfg = $repo->atual();
        $cfg->definirEquipe('talentos', false);
        $repo->salvar($cfg, flush: true);
    }

    /**
     * Os arquivos que ESTE teste gravou e que ainda estão no disco (o diretório é compartilhado com os
     * outros testes do processo). As chaves vistas no banco ficam guardadas para conferir depois da
     * exclusão, quando o registro já não existe.
     *
     * @return list<string>
     */
    private function arquivosNoDisco(): array
    {
        $sql = "SELECT j.value->>'chave' FROM trabalho_externo_curriculo c, json_each(c.arquivos) j";
        foreach ($this->em()->getConnection()->fetchFirstColumn($sql) as $chave) {
            $this->chavesVistas[(string) $chave] = true;
        }
        $dir = static::getContainer()->getParameter('trabalho_externo_uploads_dir') . '/curriculos';

        return array_values(array_filter(array_keys($this->chavesVistas), static fn (string $c): bool => is_file($dir . '/' . $c)));
    }

    private function png(int $largura, int $altura): UploadedFile
    {
        $img = imagecreatetruecolor($largura, $altura);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 10, 111, 160));
        $caminho = (string) tempnam(sys_get_temp_dir(), 'cv');
        imagepng($img, $caminho);

        return new UploadedFile($caminho, 'eu.png', 'image/png', null, true);
    }

    private function arquivo(string $nome, string $conteudo): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'cv');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, 'application/pdf', null, true);
    }

    /** @return array<string, mixed> */
    private static function curriculo(): array
    {
        return [
            'nome' => 'Ana Souza', 'email' => 'ana@exemplo.test', 'tel' => '(61) 99999-0000', 'situacao' => 'adv',
            'oabNum' => '12345', 'oabUf' => 'DF', 'cargo' => 'Advogado(a)', 'cidade' => 'Brasília', 'uf' => 'DF',
            'areas' => ['Cível'], 'resumo' => 'Atuação cível.', 'lgpd' => true,
        ];
    }
}
