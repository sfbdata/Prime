<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Functional;

use App\AssinaturaEletronica\Controller\ValidacaoPublicaController;
use App\AssinaturaEletronica\Service\ProjecaoPublicaDaValidacao;
use App\AssinaturaEletronica\UseCase\ValidarCodigoUseCase;
use App\Tests\AssinaturaEletronica\Support\NaAssinaturaEnviada;
use App\Tests\AssinaturaEletronica\Support\NaTelaDeAssinatura;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;

/**
 * A validação pública do código BJA pela HTTP (etapa 3, bloco 2; inventário A7 e A8): a página sem código e com código
 * (a do QR do PDF), a consulta em JSON da aba Validar, o filtro de escritório desligado (a aba logada num escritório
 * acha o código de outro), a Lixeira, o que a resposta NÃO traz (CPF, e-mail), o limitador por IP e o arranjo e os
 * textos do desenho (dc:159-163 e dc:275) por combinador de filho direto.
 */
#[CoversClass(ValidacaoPublicaController::class)]
#[CoversClass(ValidarCodigoUseCase::class)]
#[CoversClass(ProjecaoPublicaDaValidacao::class)]
final class ValidacaoPublicaControllerTest extends JusPrimeWebTestCase
{
    use NaTelaDeAssinatura;
    use NaAssinaturaEnviada;

    private const CONSULTA = '/assinar/validar/consulta?codigo=';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->csrfPrevisivel();
        $this->chaveDoMasterLigada();
    }

    #[TestDox('página sem código: cabeçalho do signatário e o componente no cartão, sem procura nem cota gasta')]
    public function testPaginaSemCodigo(): void
    {
        $limitador = $this->espiarOLimitador();
        $crawler = $this->client->request('GET', '/assinar/validar');

        self::assertResponseIsSuccessful();
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertCount(1, $crawler->filter('body > .bjaPub > header.bjaPubCab[data-bjt-cabecalho] > img.bjaPubMarca[alt="BlueJus"]'));
        self::assertCount(1, $crawler->filter('body > .bjaPub > header.bjaPubCab + main.bjaPubMain > section.bjaCard.bjaValCartao > .bjaVal[data-bja-validar]'));
        $raiz = $crawler->filter('.bjaVal');
        self::assertNull($raiz->attr('data-resultado'), 'sem código, nada a desenhar');
        self::assertSame('/assinar/validar/consulta', $raiz->attr('data-url-consulta'));
        self::assertSame('', $crawler->filter('.bjaVal input.bjaValCod')->attr('value'));
        self::assertSame([], $limitador->chavesConsumidas);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('main-header', $html, 'sem a moldura do sistema');
        self::assertStringNotContainsString('Link inválido', $html, 'a rota vence a reserva /assinar/{resto}');
        self::assertStringContainsString('<script src="/js/vendor/jsqr/jsQR.js"></script>', $html);
        self::assertStringContainsString('<script src="/js/assinatura-validar.js"></script>', $html);
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href*="css/assinatura-validar.css"]'));
    }

    #[TestDox('arranjo e textos do desenho (dc:159-163 e dc:275), na página pública e na aba Validar da tela')]
    public function testArranjoETextosDoDesenho(): void
    {
        self::assertComponenteDoDesenho($this->client->request('GET', '/assinar/validar')->filter('main.bjaPubMain > section.bjaValCartao > .bjaVal'));

        $this->entrar(true);
        $crawler = $this->client->request('GET', '/assinatura');
        self::assertResponseIsSuccessful();
        self::assertSame('Validar', trim($crawler->filter('#bjaLista > .bjaAbas > button.bjaAba#bjaAbaBtVal[data-bja-aba="val"][aria-controls="bjaAbaVal"]')->text()));
        self::assertCount(1, $crawler->filter('#bjaLista > .bjaAbas > button.bjaAba:nth-child(3) > i.bi.bi-shield-check'));
        self::assertCount(1, $crawler->filter('#bjaLista > #bjaAbaVal.bjaAbaCorpo[role="tabpanel"][aria-labelledby="bjaAbaBtVal"][hidden]'));
        self::assertComponenteDoDesenho($crawler->filter('#bjaLista > #bjaAbaVal > .bjaVal'));
        self::assertSame('/assinar/validar/consulta', $crawler->filter('#bjaAbaVal > .bjaVal')->attr('data-url-consulta'));
        self::assertNull($crawler->filter('#bjaAbaVal > .bjaVal')->attr('data-resultado'));
    }

    #[TestDox('o catálogo da página traz os textos do resultado, da câmera e da foto, como no desenho')]
    public function testCatalogoDaPagina(): void
    {
        $crawler = $this->client->request('GET', '/assinar/validar');

        $catalogo = json_decode($crawler->filter('script[type="application/json"][data-bj-t]')->text(), true, 8, \JSON_THROW_ON_ERROR);
        $chave = static fn (string $k): string => $catalogo['assinatura_eletronica_tela.js.validar.' . $k] ?? '(falta ' . $k . ')';
        self::assertSame('Registro localizado: {nome}', $chave('localizado'));
        self::assertSame('Situação: {situacao}', $chave('situacao'));
        self::assertSame('Hash do documento:', $chave('hash'));
        self::assertSame('{nome}: assinado em {data}', $chave('assinado_em'));
        self::assertSame('{nome}: {situacao}', $chave('signatario_situacao'));
        self::assertSame('Código não localizado.', $chave('nao_localizado'));
        self::assertSame('Confira a digitação.', $chave('confira'));
        self::assertSame('Procurando o QR Code...', $chave('cam_procurando'));
        self::assertSame('QR Code lido, mas não é de um documento BlueJus.', $chave('cam_outro'));
        self::assertSame('Câmera indisponível aqui. Use Tirar foto.', $chave('cam_indisponivel'));
        self::assertSame('QR Code do documento não encontrado na imagem.', $chave('foto_sem_qr'));
        self::assertSame('Não foi possível ler a imagem.', $chave('foto_ilegivel'));
        foreach (array_keys($catalogo) as $k) {
            self::assertStringStartsWith('assinatura_eletronica_tela.js.validar.', (string) $k, 'a página pública leva só o bloco validar');
        }
    }

    #[TestDox('página com o código do QR: o código aparado e em maiúsculas acha o documento, com o resultado na raiz')]
    public function testPaginaComCodigoAchado(): void
    {
        [$codigo, $detalhe] = $this->enviadoComCodigo();
        $this->sair($this->client);
        $limitador = $this->espiarOLimitador();

        $crawler = $this->client->request('GET', '/assinar/validar/' . rawurlencode(' ' . strtolower($codigo) . ' '));

        self::assertResponseIsSuccessful();
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame($codigo, $crawler->filter('.bjaVal input.bjaValCod')->attr('value'));
        $resultado = json_decode((string) $crawler->filter('.bjaVal')->attr('data-resultado'), true, 8, \JSON_THROW_ON_ERROR);
        self::assertSame([
            'codigo' => $codigo,
            'nome' => 'Contrato de honorários',
            'situacao' => 'Aguardando',
            'hash' => $detalhe['hashEnviado'],
            'signatarios' => [
                ['nome' => 'Ana Lima', 'assinadoEm' => null, 'situacao' => 'Pendente'],
                ['nome' => 'Bia Souza', 'assinadoEm' => null, 'situacao' => 'Pendente'],
            ],
        ], $resultado);
        self::assertSemDadoPessoal((string) $this->client->getResponse()->getContent());
        self::assertSame(['127.0.0.1'], $limitador->chavesConsumidas, 'uma procura, pelo IP');
    }

    #[TestDox('código não localizado: a página e a consulta respondem 404, a página com o resultado nulo')]
    public function testNaoLocalizado(): void
    {
        $this->enviadoComCodigo();

        $crawler = $this->client->request('GET', '/assinar/validar/BJA-0000-0000-0000');
        self::assertResponseStatusCodeSame(404);
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame('null', $crawler->filter('.bjaVal')->attr('data-resultado'));
        self::assertSame('BJA-0000-0000-0000', $crawler->filter('.bjaVal input.bjaValCod')->attr('value'));

        foreach (['BJA-0000-0000-0000', '', '%20', 'qualquer'] as $digitado) {
            $this->client->request('GET', self::CONSULTA . $digitado);
            self::assertResponseStatusCodeSame(404, $digitado);
            self::assertSame(['documento' => null], $this->json());
            self::assertCabecalhosPublicos($this->client->getResponse());
        }
    }

    #[TestDox('consulta da aba logada num escritório acha o código de OUTRO escritório; a lista do escritório não muda')]
    public function testAbaDeOutroEscritorio(): void
    {
        [$codigo] = $this->enviadoComCodigo();
        [$outraAdvogada, $outroEscritorio] = $this->entrar(true);
        $this->rascunho(extra: ['nome' => 'Rascunho do outro escritório']);

        $crawler = $this->client->request('GET', '/assinatura');
        $this->client->request('GET', (string) $crawler->filter('#bjaAbaVal > .bjaVal')->attr('data-url-consulta') . '?codigo=' . rawurlencode(strtolower($codigo)));

        self::assertResponseIsSuccessful();
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertSame('Contrato de honorários', $this->json()['documento']['nome']);
        $corpo = (string) $this->client->getResponse()->getContent();
        foreach ([(string) $outroEscritorio->getName(), (string) $outraAdvogada->getEmail(), 'Rascunho do outro escritório'] as $proibido) {
            self::assertStringNotContainsString($proibido, $corpo, $proibido);
        }
        self::assertSame(['Rascunho do outro escritório'], array_column($this->listar()['documentos'], 'nome'), 'o filtro de escritório volta na rota interna');
    }

    #[TestDox('quem assinou aparece com o nome digitado e a data; documento na Lixeira continua valendo')]
    public function testAssinadoENaLixeira(): void
    {
        [$codigo, , $id] = $this->enviadoComCodigo();
        $this->conexao()->executeStatement(
            "UPDATE assinatura_eletronica_signatario SET situacao = 'assinado', assinado_em = ?, nome_digitado = 'Ana Maria Lima' WHERE documento_id = ? AND ordem = 0",
            ['2026-11-09 14:03:27', $id],
        );
        $this->esquecer();

        $this->client->request('GET', self::CONSULTA . $codigo);
        self::assertResponseIsSuccessful();
        $ana = $this->json()['documento']['signatarios'][0];
        self::assertSame('Ana Maria Lima', $ana['nome']);
        self::assertSame('Assinado', $ana['situacao']);
        self::assertSame('2026-11-09T14:03:27', substr((string) $ana['assinadoEm'], 0, 19));

        $this->postarJson('/assinatura/documentos/lixeira', ['ids' => [$id]]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', self::CONSULTA . $codigo);
        self::assertResponseIsSuccessful();
        self::assertSame('Cancelado', $this->json()['documento']['situacao']);
        self::assertSemDadoPessoal((string) $this->client->getResponse()->getContent());
    }

    #[TestDox('a resposta nunca traz CPF, e-mail, id, escritório nem link do signatário')]
    public function testSemCpfNemEmail(): void
    {
        [$codigo, $detalhe] = $this->enviadoComCodigo();

        $this->client->request('GET', self::CONSULTA . $codigo);

        self::assertResponseIsSuccessful();
        self::assertSemDadoPessoal((string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString((string) $detalhe['signatarios'][0]['link'], (string) $this->client->getResponse()->getContent());
        self::assertSame(['codigo', 'nome', 'situacao', 'hash', 'signatarios'], array_keys($this->json()['documento']));
        foreach ($this->json()['documento']['signatarios'] as $s) {
            self::assertSame(['nome', 'assinadoEm', 'situacao'], array_keys($s));
        }
    }

    #[TestDox('logado sem escritório e sem aceite dos termos: a validação abre igual (rota pública nos dois portões)')]
    public function testLogadoSemEscritorio(): void
    {
        [$codigo] = $this->enviadoComCodigo();
        [$advogada] = $this->entrar(true);
        $this->sair($this->client);
        $this->client->loginUser($advogada);

        $this->client->request('GET', '/assinar/validar/' . $codigo);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', self::CONSULTA . $codigo);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/assinar/validar');
        self::assertResponseIsSuccessful();
    }

    #[TestDox('limitador por IP estourado: 429 na consulta (JSON) e na página com código; a página sem código abre')]
    public function testLimitador(): void
    {
        [$codigo] = $this->enviadoComCodigo();
        $this->espiarOLimitador(false);

        $this->client->request('GET', self::CONSULTA . $codigo);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'], $this->json());
        self::assertCabecalhosPublicos($this->client->getResponse());

        $crawler = $this->client->request('GET', '/assinar/validar/' . $codigo);
        self::assertResponseStatusCodeSame(429);
        self::assertCabecalhosPublicos($this->client->getResponse());
        self::assertStringContainsString('Muitas tentativas. Aguarde alguns minutos e tente de novo.', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-bja-validar]'));
        self::assertCount(0, $crawler->filter('script[src*="assinatura-validar.js"]'));

        $this->client->request('GET', '/assinar/validar');
        self::assertResponseIsSuccessful();
    }

    /**
     * Um documento enviado (com código) por um escritório novo, logado.
     *
     * @return array{string, array<string, mixed>, int} o código, o `Detalhe` e o id
     */
    private function enviadoComCodigo(): array
    {
        $this->entrar(true);
        $id = $this->rascunho();
        $detalhe = $this->enviar($id);
        $codigo = (string) ($detalhe['codigo'] ?? '');
        self::assertMatchesRegularExpression('/^BJA-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo, 'o envio gera o código');

        return [$codigo, $detalhe, $id];
    }

    /** O limitador da validação trocado pelo espião (uma vez por teste: o container não deixa trocar de novo). */
    private function espiarOLimitador(bool $aceita = true): RateLimiterFactoryEspiao
    {
        $espiao = new RateLimiterFactoryEspiao($aceita);
        static::getContainer()->set('limiter.assinatura_validacao', $espiao);

        return $espiao;
    }

    /** Nem CPF nem e-mail (o valor e, no JSON, a chave), nem link de signatário. */
    private static function assertSemDadoPessoal(string $corpo): void
    {
        foreach ([self::CPF_ANA, 'ana@exemplo.com', 'bia@exemplo.com', '"email"', '"cpf"', '"id"'] as $proibido) {
            self::assertStringNotContainsString($proibido, $corpo, $proibido);
        }
        self::assertDoesNotMatchRegularExpression('#/assinar/[a-f0-9]{64}#', $corpo, 'nenhum link de signatário');
    }

    /** O componente do desenho, o mesmo na página e na aba: arranjo por filho direto e textos literais. */
    private static function assertComponenteDoDesenho(Crawler $raiz): void
    {
        self::assertCount(1, $raiz);
        self::assertSame(
            ['bjaValT', 'bjaValTx', 'bjaValLin', 'bjaValLin bjaValLin2', 'bjaValRes', 'bjaValCamM', 'bjaValAviso'],
            $raiz->children()->each(static fn (Crawler $n): string => (string) $n->attr('class')),
        );
        self::assertSame('Validar documento assinado', trim($raiz->filter('.bjaVal > b.bjaValT')->text()));
        self::assertSame('Informe o código impresso no PDF ou leia o QR Code. Só tem validade o documento com registro correspondente.', trim($raiz->filter('.bjaVal > .bjaValTx')->text()));

        $campo = $raiz->filter('.bjaVal > .bjaValLin:not(.bjaValLin2) > input.bjaF.bjaValCod');
        self::assertSame('Código de validação', $campo->attr('aria-label'));
        self::assertSame('BJA-XXXX-XXXX-XXXX', $campo->attr('placeholder'));
        $validar = $raiz->filter('.bjaVal > .bjaValLin:not(.bjaValLin2) > input.bjaValCod + button.bjaP[data-bja-val="validar"]');
        self::assertSame('Validar', trim($validar->text()));
        self::assertCount(1, $validar->filter('i.bi.bi-shield-check'));

        $camera = $raiz->filter('.bjaVal > .bjaValLin2 > button.bjaP.bjaValCam[data-bja-val="camera"]');
        self::assertSame('Ler QR Code com a câmera', trim($camera->text()));
        self::assertCount(1, $camera->filter('i.bi.bi-qr-code-scan'));
        $foto = $raiz->filter('.bjaVal > .bjaValLin2 > button.bjaValCam + label.bjaB.bjaValFoto');
        self::assertSame('Enviar foto do QR Code', trim($foto->text()));
        self::assertCount(1, $foto->filter('i.bi.bi-image'));
        self::assertCount(1, $foto->filter('label > input[type="file"][accept="image/*"][capture="environment"][data-sem-central="1"][data-bja-val="foto"]'));
        self::assertSame('polite', $raiz->filter('.bjaVal > .bjaValRes')->attr('aria-live'));
        self::assertSame('', trim($raiz->filter('.bjaVal > .bjaValRes')->html()), 'o resultado é desenhado pelo JS');

        $janela = $raiz->filter('.bjaVal > .bjaValCamM[role="dialog"][aria-modal="true"][hidden]');
        self::assertSame('Ler QR Code', $janela->attr('aria-label'));
        self::assertSame(
            ['bjaValCamT', 'bjaValCamQ', 'bjaValCamMsg', 'bjaValCamBts'],
            $janela->children()->each(static fn (Crawler $n): string => (string) $n->attr('class')),
        );
        self::assertSame('Aponte a câmera para o QR Code', trim($janela->filter('.bjaValCamM > .bjaValCamT')->text()));
        self::assertCount(1, $janela->filter('.bjaValCamM > .bjaValCamQ > video[autoplay][playsinline][muted] + span'));
        $tirar = $janela->filter('.bjaValCamM > .bjaValCamBts > label.bjaB.bjaValCamFoto');
        self::assertSame('Tirar foto', trim($tirar->text()));
        self::assertCount(1, $tirar->filter('i.bi.bi-camera'));
        self::assertCount(1, $tirar->filter('label > input[type="file"][accept="image/*"][capture="environment"][data-bja-val="foto"]'));
        self::assertSame('Cancelar', trim($janela->filter('.bjaValCamM > .bjaValCamBts > label.bjaValCamFoto + button.bjaB.bjaValCamSair[data-bja-val="fechar"]')->text()));

        self::assertCount(0, $raiz->filter('.card, .modal, .btn, .form-control'), 'só componentes do desenho');
    }
}
