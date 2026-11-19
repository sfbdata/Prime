<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\FotoDaCandidaturaController;
use App\Voto\Controller\PautasDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\TipoDaPauta;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * Foto 3x4 da candidatura (desenho 18, `.vFotoU` e `.uFoto`; decisão C7), de ponta a ponta com banco e disco reais:
 * o gestor envia, troca e remove pelo "Salvar" da pauta (CSRF, escritório, liberação, só em rascunho, tipo e tamanho),
 * a tela mostra e serve a foto por chave, e a urna do eleitor (link da unidade, sem login) só entrega foto de
 * assembleia publicada daquele link, sem expor chave nem caminho.
 */
#[CoversClass(FotoDaCandidaturaController::class)]
#[CoversClass(PautasDaAssembleiaController::class)]
final class FotoDaCandidaturaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;
    private PautaDaAssembleia $eleicao;
    private string $dirTemp;

    /** @var list<string> */
    private array $gravadosNoTeste = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->dirTemp = sys_get_temp_dir() . '/voto-foto-func-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio das Fotos ' . uniqid()])->_real();
        $this->liberar($this->escritorio);
        $this->entrar();
        $this->assembleia = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $this->eleicao = PautaDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'ordem' => 1, 'titulo' => 'Síndico(a)', 'tipo' => TipoDaPauta::EleicaoDeCargo])->_real();
        OpcaoDaPautaFactory::createOne(['pauta' => $this->eleicao, 'numero' => 1, 'rotulo' => 'Ana Souza']);
        OpcaoDaPautaFactory::createOne(['pauta' => $this->eleicao, 'numero' => 2, 'rotulo' => 'Caio Lima']);
        OpcaoDaPautaFactory::new(['pauta' => $this->eleicao])->abstencao()->create();
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
        foreach (array_unique($this->gravadosNoTeste) as $caminho) {
            @unlink($caminho);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- gestor

    #[TestDox('Enviar: o "Salvar" da pauta grava a foto no escritório, a tela mostra .vFotoU com a imagem, "Trocar foto" e "Remover foto", e a rota entrega a imagem')]
    public function testEnviarEServir(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);

        self::assertResponseStatusCodeSame(200);
        $nome = $this->fotoDe(1);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.jpe?g$/', (string) $nome);
        self::assertFileExists($this->caminho((string) $nome));
        self::assertNull($this->fotoDe(2));

        $tela = $this->client->request('GET', $this->url());
        $ana = $tela->filter('form#pauta-' . $this->eleicao->getId() . ' > .voto-opcoes > .voto-opcao.vCandR')->eq(0);
        $quadro = $ana->filter('.vCandR > label.vFotoU');
        self::assertCount(1, $quadro, 'a foto vem antes do .vCandF, como filho direto da linha');
        self::assertSame('Foto da candidatura para a urna', $quadro->filter('input[type="file"]')->attr('aria-label'));
        self::assertSame('opcao_foto_arquivo[]', $quadro->filter('input[type="file"]')->attr('name'));
        self::assertSame($nome, $quadro->filter('input[type="hidden"][name="opcao_foto[]"]')->attr('value'));
        self::assertSame('/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . self::semExtensao($nome), $quadro->filter('img')->attr('src'));
        self::assertSame('Foto de Ana Souza', $quadro->filter('img')->attr('alt'));
        self::assertSame('Trocar foto', trim($quadro->filter('.vFotoT')->text()));
        self::assertNull($quadro->filter('.vFotoT')->attr('hidden'));
        self::assertNotNull($quadro->filter('span[data-voto-foto-sem][hidden]')->getNode(0));
        self::assertSame(['Descer', 'Remover foto', 'Remover candidatura'], $this->itensVisiveis($ana->filter('.vKm > button')), 'a primeira só desce');
        self::assertNull($ana->filter('.vKm > button[data-voto-foto-remover]')->attr('hidden'));

        $caio = $tela->filter('form#pauta-' . $this->eleicao->getId() . ' > .voto-opcoes > .voto-opcao')->eq(1);
        self::assertSame('Foto para a urna', trim($caio->filter('label.vFotoU > span[data-voto-foto-sem]')->eq(0)->text()));
        self::assertSame('formato 3x4', trim($caio->filter('label.vFotoU > .vFotoDica')->text()));
        self::assertNotNull($caio->filter('.vKm > button[data-voto-foto-remover]')->attr('hidden'), 'sem foto, não há o que remover');

        $this->client->request('GET', $this->url('/..' . '/fotos/' . self::semExtensao($nome)));
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . self::semExtensao($nome));
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[TestDox('Nenhuma URL de foto da tela das pautas termina em extensão de imagem: o nginx de produção responderia 404 sem passar pelo PHP')]
    public function testUrlDaFotoSemExtensao(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);
        $nome = (string) $this->fotoDe(1);

        $this->client->request('GET', $this->url());
        $html = (string) $this->client->getResponse()->getContent();

        $urlDaFoto = '/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . self::semExtensao($nome);
        self::assertStringContainsString('src="' . $urlDaFoto . '"', $html);
        self::assertDoesNotMatchRegularExpression('#/fotos/[0-9a-f]{32}\.(?:jpe?g|png|webp)#', $html);

        $this->client->request('GET', $urlDaFoto);
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    #[TestDox('Trocar apaga a foto antiga do disco; manter (nome devolvido, sem arquivo) não mexe; remover (nome vazio) apaga')]
    public function testTrocarManterERemover(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);
        $primeira = (string) $this->fotoDe(1);

        $this->salvar([$this->linha('1', 'Ana Souza', $this->png(), 'foto.png', $primeira), $this->linha('2', 'Caio Lima')]);
        $segunda = (string) $this->fotoDe(1);
        self::assertNotSame($primeira, $segunda);
        self::assertStringEndsWith('.png', $segunda);
        self::assertFileExists($this->caminho($segunda));
        self::assertFileDoesNotExist($this->caminho($primeira), 'a antiga saiu depois do COMMIT');

        $this->salvar([$this->linha('1', 'Ana Souza', null, 'foto.jpg', $segunda), $this->linha('2', 'Caio Lima')]);
        self::assertSame($segunda, $this->fotoDe(1));
        self::assertFileExists($this->caminho($segunda));

        $this->salvar([$this->linha('1', 'Ana Souza', null, 'foto.jpg', ''), $this->linha('2', 'Caio Lima')]);
        self::assertNull($this->fotoDe(1));
        self::assertFileDoesNotExist($this->caminho($segunda));
    }

    #[TestDox('Tipo fora de JPG/PNG/WebP e arquivo acima de 3 MB: 422 com a mensagem, nada gravado e a foto que existia continua')]
    public function testTipoETamanhoInvalidos(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);
        $atual = (string) $this->fotoDe(1);
        $antes = $this->arquivosDaPasta();

        $gif = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";
        $this->salvar([$this->linha('1', 'Ana Souza', $gif, 'foto.jpg', $atual), $this->linha('2', 'Caio Lima')]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('formato de imagem não permitido', $this->json()['mensagem']);

        $grande = str_pad($this->jpeg(), 3 * 1024 * 1024 + 1, "\0");
        $this->salvar([$this->linha('1', 'Ana Souza', $grande, 'foto.jpg', $atual), $this->linha('2', 'Caio Lima')]);
        self::assertResponseStatusCodeSame(422);
        // No CI o arquivo grande pode parar no limite de upload do PHP antes da validação ("não chegou inteira"):
        // as duas mensagens citam o limite de 3 MB, e em nenhum caso a foto troca.
        self::assertStringContainsString('3 MB', $this->json()['mensagem']);

        self::assertSame($atual, $this->fotoDe(1));
        self::assertSame($antes, $this->arquivosDaPasta(), 'nenhum arquivo novo ficou no disco');
    }

    #[TestDox('CSRF inválido: 403, nada gravado')]
    public function testCsrfInvalido(): void
    {
        $this->client->request('GET', $this->url());
        $antes = $this->arquivosDaPasta();

        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')], 'invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->fotoDe(1));
        self::assertSame($antes, $this->arquivosDaPasta());
    }

    #[TestDox('Publicada: enviar foto é recusado (422) e nada é gravado; as fotos já existentes continuam')]
    public function testTravaDepoisDePublicada(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);
        $atual = (string) $this->fotoDe(1);
        $token = $this->token();
        $this->em->getConnection()->executeStatement("UPDATE voto.assembleia SET estado = 'publicada' WHERE id = ?", [$this->assembleia->getId()]);
        $antes = $this->arquivosDaPasta();

        $this->salvar([$this->linha('1', 'Ana Souza', $this->png(), 'foto.png', $atual), $this->linha('2', 'Caio Lima')], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($atual, $this->fotoDe(1));
        self::assertSame($antes, $this->arquivosDaPasta());
        self::assertFileExists($this->caminho($atual));
    }

    #[TestDox('Outro escritório: pauta e foto de uma assembleia alheia dão 404; nome forjado de foto alheia não vale nem é apagado')]
    public function testOutroEscritorio(): void
    {
        [$alheia, $alheiaPauta, $nomeAlheio] = $this->cenarioAlheio();
        $this->client->request('GET', $this->url());
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/voto_assembleia_' . $alheia->getId(), 'token-de-teste');
        $sessao->save();

        // 1. Editar a pauta alheia com a foto: 404.
        $this->client->request('POST', '/voto/assembleias/' . $alheia->getId() . '/pautas/' . $alheiaPauta->getId() . '/editar', $this->corpo([$this->linha('1', 'Alheio', $this->jpeg())], 'token-de-teste'), $this->arquivos([$this->linha('1', 'Alheio', $this->jpeg())]));
        self::assertResponseStatusCodeSame(404);

        // 2. A foto alheia pela rota da minha assembleia e pela da alheia: 404.
        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . self::semExtensao($nomeAlheio));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/fotos/' . self::semExtensao($nomeAlheio));
        self::assertResponseStatusCodeSame(404);

        // 3. Nome forjado no meu "Salvar": não vira minha foto e o arquivo do vizinho continua.
        $this->salvar([$this->linha('1', 'Ana Souza', null, 'foto.jpg', $nomeAlheio), $this->linha('2', 'Caio Lima')]);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->fotoDe(1));
        self::assertFileExists($this->caminho($nomeAlheio));
    }

    #[TestDox('Sem liberação do Voto no escritório: a foto da equipe dá 403')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->salvar([$this->linha('1', 'Ana Souza', $this->jpeg()), $this->linha('2', 'Caio Lima')]);
        $nome = (string) $this->fotoDe(1);
        $this->em->getConnection()->executeStatement('UPDATE voto.liberacao SET liberado = false WHERE tenant_id = ?', [$this->escritorio->getId()]);

        $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . self::semExtensao($nome));

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Nome com caminho ou fora do formato da chave: 404, sem chegar ao armazenamento')]
    public function testNomeForaDoFormato(): void
    {
        foreach (['..%2F..%2Fsegredo.jpg', 'abc.jpg', bin2hex(random_bytes(16)) . '.gif', bin2hex(random_bytes(16)) . '.jpg', bin2hex(random_bytes(16))] as $nome) {
            $this->client->request('GET', '/voto/assembleias/' . $this->assembleia->getId() . '/fotos/' . $nome);
            self::assertResponseStatusCodeSame(404, $nome);
        }
    }

    // ---------------------------------------------------------------- urna

    #[TestDox('Urna: a cédula leva a rota da foto (nunca chave nem caminho) só da opção que tem foto; a rota entrega a imagem sem cache público')]
    public function testUrnaEntregaAFoto(): void
    {
        [$unidade, $opcaoComFoto, $opcaoSemFoto, $nome] = $this->cenarioDaUrna();
        $token = UnidadeDaAssembleiaFactory::tokenDe($unidade);

        $crawler = $this->client->request('GET', '/votar/' . $token);
        self::assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($nome, (string) $html, 'a chave não aparece na página da urna');
        self::assertStringNotContainsString('uploads', (string) $html);
        $dados = json_decode((string) $crawler->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, JSON_THROW_ON_ERROR);
        $opcoes = [];
        foreach ($dados['simulacao'][0]['opcoes'] as $o) {
            $opcoes[$o['rotulo']] = $o['foto'];
        }
        self::assertSame('/votar/' . $token . '/foto/' . $opcaoComFoto, $opcoes['Ana Souza']);
        self::assertNull($opcoes['Caio Lima']);
        self::assertSame('Foto de {nome}', $dados['textos']['foto_alt']);

        $this->client->request('GET', '/votar/' . $token . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('noindex', (string) $this->client->getResponse()->headers->get('X-Robots-Tag'));

        $this->client->request('GET', '/votar/' . $token . '/foto/' . $opcaoSemFoto);
        self::assertResponseStatusCodeSame(404, 'opção sem foto');
    }

    #[TestDox('Urna: link inválido, assembleia em rascunho, opção de outra assembleia ou de outro escritório e escritório sem liberação dão 404 e nenhum arquivo')]
    public function testUrnaNaoEntregaForaDoEscopo(): void
    {
        [$unidade, $opcaoComFoto] = $this->cenarioDaUrna();
        $token = UnidadeDaAssembleiaFactory::tokenDe($unidade);

        $this->client->request('GET', '/votar/' . str_repeat('a', 40) . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(404, 'link inexistente');

        [$alheiaUnidade, $alheiaOpcao] = $this->cenarioDaUrna(outroEscritorio: true);
        $this->client->request('GET', '/votar/' . $token . '/foto/' . $alheiaOpcao);
        self::assertResponseStatusCodeSame(404, 'opção de outro escritório pelo meu link');
        $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($alheiaUnidade) . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(404, 'minha opção pelo link do outro escritório');

        $outraAssembleia = AssembleiaFactory::new()->porFracao()->publicada()->create(['tenant' => $this->escritorio])->_real();
        $outraPauta = PautaDaAssembleiaFactory::new(['assembleia' => $outraAssembleia, 'tipo' => TipoDaPauta::EleicaoDeCargo])->aberta()->create()->_real();
        $outra = OpcaoDaPautaFactory::createOne(['pauta' => $outraPauta, 'numero' => 1, 'rotulo' => 'Dora'])->_real();
        $this->em->getConnection()->executeStatement('UPDATE voto.assembleia_opcao SET foto = ? WHERE id = ?', ['0123456789abcdef0123456789abcdef.jpg', $outra->getId()]);
        $this->client->request('GET', '/votar/' . $token . '/foto/' . $outra->getId());
        self::assertResponseStatusCodeSame(404, 'opção de outra assembleia do mesmo escritório');

        $this->em->getConnection()->executeStatement("UPDATE voto.assembleia SET estado = 'rascunho' WHERE id = ?", [$unidade->getAssembleia()->getId()]);
        $this->client->request('GET', '/votar/' . $token . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(404, 'rascunho não entrega foto');
        $this->em->getConnection()->executeStatement("UPDATE voto.assembleia SET estado = 'publicada' WHERE id = ?", [$unidade->getAssembleia()->getId()]);

        $this->client->request('GET', '/votar/' . $token . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(200);
        $this->em->getConnection()->executeStatement('UPDATE voto.liberacao SET liberado = false WHERE tenant_id = ?', [$this->escritorio->getId()]);
        $this->client->request('GET', '/votar/' . $token . '/foto/' . $opcaoComFoto);
        self::assertResponseStatusCodeSame(404, 'sem liberação o link se comporta como inexistente');
    }

    #[TestDox('Urna (CSS e JS do desenho): .uFoto com clamp(104px, 17vw, 168px) e 3/4, imagem com fade, silhueta SVG #b4bec4 a 74%, alt "Foto de {nome}"')]
    public function testValoresDoDesenhoDaUrna(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/voto-cabine.css');
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/voto-cabine.js');

        self::assertStringContainsString('.uFoto { align-self:flex-start; width:clamp(104px, 17vw, 168px); aspect-ratio:3/4; background:#dfe3e2; border:1px solid #666;', $css);
        self::assertStringContainsString('.uFoto img { width:100%; height:100%; object-fit:cover; }', $css);
        self::assertStringContainsString('.uSilhueta { width:74%; height:auto; margin-top:22%; }', $css);
        self::assertStringContainsString("el('img', 'uFade')", $js, 'o fade fica na imagem');
        self::assertStringContainsString("el('div', 'uFoto')", $js);
        self::assertStringContainsString("'M3 80c0-17 12-28 27-28s27 11 27 28z'", $js);
        self::assertSame(2, substr_count($js, "'#b4bec4'"), 'cabeça e ombros');
        self::assertStringContainsString("texto('foto_alt', {nome: achou.rotulo})", $js);
        self::assertStringContainsString('p.eleicao && !c.branco && !invalido', $js, 'visível ao digitar, menos no branco e no número errado');
    }

    #[TestDox('Gestor (CSS do desenho): .vFotoU 104px, 3/4, borda tracejada, faixa "Trocar foto"; abaixo de 560px a linha cheia e o menu à direita já valem')]
    public function testValoresDoDesenhoDoGestor(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../../public/css/voto.css');

        self::assertStringContainsString('.voto-tela .vFotoU { position: relative; width: 104px; aspect-ratio: 3/4; flex: none;', $css);
        self::assertStringContainsString('background: #f2f8fb; border: 1px dashed #0a6fa0;', $css);
        self::assertStringContainsString('.voto-tela .vFotoU:hover { border-color: #3b8fc2; background: #e7f2f9; }', $css);
        self::assertStringContainsString('.voto-tela .vFotoU .vFotoT { position: absolute; left: 0; right: 0; bottom: 0; padding: 3px 0; background: rgba(11,53,80,.72); color: #fff; font-size: 11px; }', $css);
        self::assertStringContainsString('@media (max-width: 560px) { .voto-tela .voto-opcao .vCandF { order: 3; flex-basis: 100%; } .voto-tela .voto-opcao > .vKw { margin-left: auto; } }', $css);
    }

    #[TestDox('Gestor (JS do desenho): a foto escolhida é reduzida no cliente a 480 px, JPEG 0,68, sem ampliar; falha deixa o original')]
    public function testReducaoNoCliente(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/voto-assembleia.js');

        self::assertStringContainsString('var LADO_DA_FOTO = 480;', $js);
        self::assertStringContainsString('Math.min(1, LADO_DA_FOTO / Math.max(imagem.width, imagem.height))', $js);
        self::assertStringContainsString("'image/jpeg', 0.68", $js);
        self::assertStringContainsString('reduzirFoto(campo);', $js);
        self::assertStringContainsString('fica o original', $js);
    }

    // ---------------------------------------------------------------- helpers

    private function url(string $fim = ''): string
    {
        return '/voto/assembleias/' . $this->assembleia->getId() . '/pautas' . $fim;
    }

    private function token(): string
    {
        $tela = $this->client->request('GET', $this->url());

        return (string) $tela->filter('form[data-voto-pauta-form="existente"] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @param list<array{numero: string, rotulo: string, conteudo: ?string, nome: string, foto: ?string}> $linhas
     */
    private function salvar(array $linhas, ?string $token = null): void
    {
        $token ??= $this->token();
        $this->client->request(
            'POST',
            $this->url('/' . $this->eleicao->getId() . '/editar'),
            $this->corpo($linhas, $token),
            $this->arquivos($linhas),
            ['HTTP_X-Requested-With' => 'XMLHttpRequest'],
        );
        $this->anotarGravados();
    }

    /**
     * @param list<array{numero: string, rotulo: string, conteudo: ?string, nome: string, foto: ?string}> $linhas
     *
     * @return array<string, mixed>
     */
    private function corpo(array $linhas, string $token): array
    {
        return [
            '_token' => $token, 'tipo' => 'eleicao_cargo', 'titulo' => 'Síndico(a)', 'descricao' => '',
            'opcao_numero' => array_column($linhas, 'numero'), 'opcao_rotulo' => array_column($linhas, 'rotulo'),
            'opcao_foto' => array_map(static fn (array $l): string => (string) $l['foto'], $linhas),
        ];
    }

    /**
     * @param list<array{numero: string, rotulo: string, conteudo: ?string, nome: string, foto: ?string}> $linhas
     *
     * @return array<string, array<int, UploadedFile|null>>
     */
    private function arquivos(array $linhas): array
    {
        $arquivos = [];
        foreach ($linhas as $i => $linha) {
            if (null === $linha['conteudo']) {
                continue;
            }
            $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
            file_put_contents($caminho, $linha['conteudo']);
            $arquivos[$i] = new UploadedFile($caminho, $linha['nome'], null, null, true);
        }

        return [] === $arquivos ? [] : ['opcao_foto_arquivo' => $arquivos];
    }

    /** @return array{numero: string, rotulo: string, conteudo: ?string, nome: string, foto: ?string} */
    private function linha(string $numero, string $rotulo, ?string $conteudo = null, string $nome = 'foto.jpg', ?string $foto = null): array
    {
        return ['numero' => $numero, 'rotulo' => $rotulo, 'conteudo' => $conteudo, 'nome' => $nome, 'foto' => $foto];
    }

    private function fotoDe(int $numero): ?string
    {
        $nome = $this->em->getConnection()->fetchOne('SELECT foto FROM voto.assembleia_opcao WHERE pauta_id = ? AND numero = ?', [$this->eleicao->getId(), $numero]);

        return \is_string($nome) ? $nome : null;
    }

    private function caminho(string $nome): string
    {
        return rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/voto-fotos/' . $nome;
    }

    /** @return list<string> */
    private function arquivosDaPasta(): array
    {
        $arquivos = array_map('basename', glob(\dirname($this->caminho('x')) . '/*') ?: []);
        sort($arquivos);

        return $arquivos;
    }

    private function anotarGravados(): void
    {
        foreach (glob(\dirname($this->caminho('x')) . '/*') ?: [] as $caminho) {
            $this->gravadosNoTeste[] = $caminho;
        }
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function itensVisiveis(Crawler $itens): array
    {
        return array_values(array_filter($itens->each(static fn (Crawler $c): string => null === $c->attr('hidden') ? trim($c->text()) : ''), static fn (string $t): bool => '' !== $t));
    }

    /**
     * Uma assembleia de OUTRO escritório, com uma opção que já tem foto gravada no disco.
     *
     * @return array{Assembleia, PautaDaAssembleia, string}
     */
    private function cenarioAlheio(): array
    {
        $outro = TenantFactory::createOne(['name' => 'Outro Condomínio ' . uniqid()])->_real();
        $assembleia = AssembleiaFactory::createOne(['tenant' => $outro])->_real();
        $pauta = PautaDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'ordem' => 1, 'titulo' => 'Alheia', 'tipo' => TipoDaPauta::EleicaoDeCargo])->_real();
        $opcao = OpcaoDaPautaFactory::createOne(['pauta' => $pauta, 'numero' => 1, 'rotulo' => 'Alheio'])->_real();
        $nome = $this->gravarFotoDe($opcao);

        return [$assembleia, $pauta, $nome];
    }

    /**
     * Assembleia publicada com a eleição aberta e uma opção com foto (arquivo real no disco).
     *
     * @return array{UnidadeDaAssembleia, int, int, string} a unidade, o id da opção com foto, o da sem foto e o nome da foto
     */
    private function cenarioDaUrna(bool $outroEscritorio = false): array
    {
        $tenant = $this->escritorio;
        if ($outroEscritorio) {
            $tenant = TenantFactory::createOne(['name' => 'Condomínio Vizinho ' . uniqid()])->_real();
            $this->liberar($tenant);
        }
        $assembleia = AssembleiaFactory::new()->porFracao()->publicada()->create(['tenant' => $tenant, 'titulo' => 'AGO da urna'])->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Síndico(a)', 'tipo' => TipoDaPauta::EleicaoDeCargo])->aberta()->create()->_real();
        $ana = OpcaoDaPautaFactory::createOne(['pauta' => $pauta, 'numero' => 1, 'rotulo' => 'Ana Souza'])->_real();
        $caio = OpcaoDaPautaFactory::createOne(['pauta' => $pauta, 'numero' => 2, 'rotulo' => 'Caio Lima'])->_real();
        OpcaoDaPautaFactory::new(['pauta' => $pauta])->abstencao()->create();
        $unidade = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia, 'bloco' => 'A', 'identificacao' => '101', 'fracao' => '0.025000'])->_real();
        $nome = $this->gravarFotoDe($ana);

        return [$unidade, (int) $ana->getId(), (int) $caio->getId(), $nome];
    }

    /** Grava um arquivo real na pasta de fotos, no nome de uma chave nova, e aponta a opção para ele. */
    private function gravarFotoDe(\App\Voto\Entity\OpcaoDaPauta $opcao): string
    {
        $nome = bin2hex(random_bytes(16)) . '.jpg';
        $caminho = $this->caminho($nome);
        if (!is_dir(\dirname($caminho))) {
            mkdir(\dirname($caminho), 0o775, true);
        }
        ob_start();
        imagejpeg(imagecreatetruecolor(6, 8), null, 88);
        file_put_contents($caminho, (string) ob_get_clean());
        $this->gravadosNoTeste[] = $caminho;
        $this->em->getConnection()->executeStatement('UPDATE voto.assembleia_opcao SET foto = ? WHERE id = ?', [$nome, $opcao->getId()]);
        $this->em->clear();

        return $nome;
    }

    private function jpeg(): string
    {
        ob_start();
        imagejpeg(imagecreatetruecolor(6, 8), null, 88);

        return (string) ob_get_clean();
    }

    private function png(): string
    {
        ob_start();
        imagepng(imagecreatetruecolor(6, 8));

        return (string) ob_get_clean();
    }

    private function liberar(Tenant $tenant): void
    {
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }

    /** A URL da foto leva o nome sem a extensão (o nginx de produção responde 404 a caminho terminado em .jpg/.png). */
    private static function semExtensao(string $nome): string
    {
        return pathinfo($nome, \PATHINFO_FILENAME);
    }
}
