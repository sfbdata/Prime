<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ComprovanteDoPontoController;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\SeloDoComprovante;
use App\Ponto\UseCase\RegistrarBatidaDaPessoaUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\SeloDoComprovanteDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E15 do Ponto na tela: a batida com NSR e comprovante (D2, D9), a aba Comprovantes (P48, desenho 16 L193 a L197) e o
 * download da imagem (P49) com a guarda de posse. Sem o segredo do HMAC (o selo de produção desta fase), a batida
 * grava com NSR, nada é emitido e a aba e o "Validar código" não aparecem.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
#[CoversClass(ComprovanteDoPontoController::class)]
final class ComprovanteDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const PAINEL = '.pe-page section.pe-abas > div#ponto-painel-comprovantes.pe-abas-painel[role="tabpanel"]';
    private const LINHAS = self::PAINEL . ' > div.pe-comp-topo + div#ponto-comprovantes-lista.pe-comp-lista > div.pe-comp';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Comprovante');
        $this->eu = $this->pessoa('comprovante');
        $this->eu->setFullName('Maria da Silva Pereira');
        $this->vincularColaborador($this->eu, $this->escritorio);
        $this->homeOffice($this->eu, $this->escritorio);
    }

    #[TestDox('Sem o segredo do HMAC: a batida grava com NSR, nada é emitido, e a aba e o "Validar código" não aparecem')]
    public function testSeloIndisponivel(): void
    {
        $resposta = $this->bater();

        self::assertSame('Ponto (home office) registrado com sucesso!', $resposta['message'], 'sem o aviso do comprovante');
        $batidas = $this->emSemFiltro()->getRepository(RegistroPonto::class)->findBy(['user' => $this->eu->getId()]);
        self::assertCount(1, $batidas);
        self::assertSame(1, $batidas[0]->getNsr(), 'o NSR sobe mesmo sem comprovante (D2)');
        self::assertSame(0, $this->emSemFiltro()->getRepository(ComprovanteDoPonto::class)->count(['tenant' => $this->escritorio->getId()]));

        $tela = $this->tela();
        self::assertCount(0, $tela->filter('#ponto-aba-comprovantes, #ponto-painel-comprovantes, #ponto-janela-validar, [data-validar-abrir]'));
        self::assertStringNotContainsString('Validar código', $tela->html());
        self::assertStringNotContainsString('ponto-comprovantes.js', $tela->html());
    }

    #[TestDox('Com o selo pronto: o aviso da batida aponta a aba, e a aba lista data e hora, tipo, método, código e "Baixar"')]
    public function testSeloProntoEmiteEMostraNaAba(): void
    {
        $this->seloPronto();

        $resposta = $this->bater();

        self::assertSame('Ponto (home office) registrado com sucesso! Comprovante na aba Comprovantes.', $resposta['message']);
        $comprovante = $this->emSemFiltro()->getRepository(ComprovanteDoPonto::class)->findOneBy(['tenant' => $this->escritorio->getId()]);
        self::assertNotNull($comprovante);
        self::assertSame(1, $comprovante->getNsr());

        $tela = $this->tela('?aba=comprovantes');
        self::assertCount(1, $tela->filter('.pe-abas-barra > button#ponto-aba-comprovantes[role="tab"][aria-selected="true"] > span.pe-aba-num'));
        self::assertCount(1, $tela->filter(self::PAINEL . ':not([hidden]) > div.pe-comp-topo:first-child > button.pe-comp-btn[data-validar-abrir]'));
        $linha = $tela->filter(self::LINHAS);
        self::assertCount(1, $linha);
        self::assertMatchesRegularExpression('#^\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2}$#', $linha->filter('b.pe-comp-dh')->text());
        self::assertSame(['Entrada', 'Sistema'], $linha->filter('div.pe-comp > span')->each(fn (Crawler $c): string => $c->text()));
        self::assertSame($comprovante->getCodigo(), $linha->filter('code.pe-comp-codigo')->text());
        $baixar = $linha->filter('a.pe-comp-btn--baixar:last-child');
        self::assertSame('/ponto/comprovantes/' . $comprovante->getId() . '/baixar', $baixar->attr('href'));
        self::assertSame('Baixar', trim($baixar->text()));
        self::assertCount(1, $tela->filter('#ponto-janela-validar[hidden] form.pe-janela[role="dialog"][method="get"] input[name="codigo"][placeholder="BJP-XXXX-XXXX-XXXX"]'));
    }

    #[TestDox('A aba lista só os comprovantes da própria pessoa no escritório em que está')]
    public function testListaSoDaPessoaNoEscritorio(): void
    {
        $this->seloPronto();
        $outroEscritorio = $this->escritorio('Outro escritório');
        $this->vincularColaborador($this->eu, $outroEscritorio);
        $colega = $this->pessoa('colega');
        $this->vincularColaborador($colega, $this->escritorio);
        $meu = $this->emitir($this->eu, $this->escritorio);
        $this->emitir($this->eu, $outroEscritorio);
        $this->emitir($colega, $this->escritorio);

        $codigos = $this->tela('?aba=comprovantes')->filter(self::LINHAS . ' > code')->each(fn (Crawler $c): string => $c->text());

        self::assertSame([$meu->getCodigo()], $codigos);
    }

    #[TestDox('Baixar: o dono recebe a imagem PNG em alta (2480 x 3670) com o nome do código')]
    public function testBaixarODono(): void
    {
        $this->seloPronto();
        $comprovante = $this->emitir($this->eu, $this->escritorio);
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);

        $this->client->request('GET', '/ponto/comprovantes/' . $comprovante->getId() . '/baixar');

        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame('image/png', $resposta->headers->get('Content-Type'));
        self::assertSame('attachment; filename=comprovante-ponto-' . $comprovante->getCodigo() . '.png', $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        $tamanho = getimagesizefromstring((string) $resposta->getContent());
        self::assertNotFalse($tamanho);
        self::assertSame([2480, 3670, IMAGETYPE_PNG], [$tamanho[0], $tamanho[1], $tamanho[2]]);
    }

    #[TestDox('Baixar comprovante de outra pessoa, de outro escritório ou que não existe: 404')]
    public function testBaixarDeOutroDa404(): void
    {
        $this->seloPronto();
        $colega = $this->pessoa('colega');
        $this->vincularColaborador($colega, $this->escritorio);
        $doColega = $this->emitir($colega, $this->escritorio);
        $outroEscritorio = $this->escritorio('Outro escritório');
        $this->vincularColaborador($this->eu, $outroEscritorio);
        $meuNoOutro = $this->emitir($this->eu, $outroEscritorio);

        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        foreach ([$doColega->getId(), $meuNoOutro->getId(), 999999999] as $id) {
            $this->client->request('GET', '/ponto/comprovantes/' . $id . '/baixar');
            self::assertResponseStatusCodeSame(404, 'comprovante ' . $id);
        }
    }

    #[TestDox('Sem o selo pronto, nem o comprovante que existe é baixado: 404')]
    public function testSemSeloNaoBaixa(): void
    {
        $batida = $this->batidaGravada($this->eu, $this->escritorio);
        $comprovante = new ComprovanteDoPonto($this->escritorio, $batida, 1, 'BJP-ABCD-EFGH-JK23', str_repeat('a', 64), 1, new \DateTimeImmutable(), '{}');
        $this->em()->persist($comprovante);
        $this->em()->flush();
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);

        $this->client->request('GET', '/ponto/comprovantes/' . $comprovante->getId() . '/baixar');

        self::assertResponseStatusCodeSame(404);
    }

    private function seloPronto(): void
    {
        static::getContainer()->set(SeloDoComprovante::class, new SeloDoComprovanteDeTeste());
    }

    /** @return array<string, mixed> */
    private function bater(): array
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $this->client->request('POST', '/ponto/batida', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_ajax'], (string) json_encode(['tipo' => 'entrada']));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function tela(string $consulta = ''): Crawler
    {
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $tela = $this->client->request('GET', '/ponto/' . $consulta);
        self::assertResponseIsSuccessful();

        return $tela;
    }

    private function emitir(User $pessoa, Tenant $escritorio): ComprovanteDoPonto
    {
        $batida = new RegistroPonto();
        $batida->setUser($pessoa);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime());
        $comprovante = static::getContainer()->get(RegistrarBatidaDaPessoaUseCase::class)->executar($batida);
        self::assertNotNull($comprovante);

        return $comprovante;
    }

    private function batidaGravada(User $pessoa, Tenant $escritorio): RegistroPonto
    {
        $batida = new RegistroPonto();
        $batida->setUser($pessoa);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime());
        $batida->setNsr(1);
        $this->em()->persist($batida);
        $this->em()->flush();

        return $batida;
    }

    private function homeOffice(User $pessoa, Tenant $escritorio): void
    {
        $config = new HomeOfficeConfig();
        $config->setUser($pessoa);
        $config->setTenant($escritorio);
        $config->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $config->setAtivo(true);
        $this->em()->persist($config);
        $this->em()->flush();
    }
}
