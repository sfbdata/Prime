<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\ValidacaoDoComprovanteController;
use App\Ponto\DTO\DadosDaFolhaEmitida;
use App\Ponto\Entity\FolhaEmitidaDoPonto;
use App\Ponto\Enum\SituacaoDoAceiteDaFolha;
use App\Ponto\Repository\FolhaEmitidaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\CodigoDaFolha;
use App\Ponto\Service\SeloDaFolhaHmac;
use App\Ponto\UseCase\ValidarCodigoDoPontoUseCase;
use App\Profile\Entity\UserProfile;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Validação pública da folha emitida em PDF (E16, P50 e decisão 17): o código `BJF-...` vale na mesma página do
 * comprovante (`/ponto/validar`, sem login), com a mesma caixa "Comprovante autêntico." (desenho L300) e o texto da
 * folha, "Folha de Ponto MM/AAAA de NOME em dd/mm/aaaa, HH:MM:SS." (L803). Qualquer caso não autêntico recebe a MESMA
 * resposta, "Código não localizado." (L301). Sem CPF, sem localização, sem a ressalva nem o resumo; `noindex`; o
 * limite por IP vale para os dois prefixos.
 */
#[Group('ponto')]
#[CoversClass(ValidacaoDoComprovanteController::class)]
#[CoversClass(ValidarCodigoDoPontoUseCase::class)]
final class ValidacaoDaFolhaTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const JSON = ['HTTP_ACCEPT' => 'application/json'];
    private const SEGREDO = 'segredo-de-teste-do-comprovante';
    private const TEXTO = 'Folha de Ponto 10/2026 de Maria da Silva Pereira em 03/11/2026, 09:00:00.';
    private const NAO_AUTENTICO = '{"autentico":false,"texto":null}';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    #[TestDox('Folha autêntica, sem login: a caixa "Comprovante autêntico." com o texto da folha; sem CPF, localização, ressalva nem resumo; noindex e sem cache')]
    public function testFolhaAutenticaSemLogin(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();

        $tela = $this->client->request('GET', '/ponto/validar/' . $folha->getCodigo());

        self::assertResponseIsSuccessful();
        $ok = $tela->filter('main .pv-ok[role="status"]');
        self::assertCount(1, $ok);
        self::assertSame('Comprovante autêntico.', $ok->filter('b')->text());
        self::assertMatchesRegularExpression('#^Comprovante autêntico\\. ?' . preg_quote(self::TEXTO, '#') . '$#u', trim($ok->text()));
        self::assertStringContainsString('Faltou a batida do dia 3.', $folha->getDadosAssinados(), 'a ressalva está no dado selado');
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['123.456.789-09', '12345678909', '-23.55', 'Sede Centro', 'Faltou a batida', 'ressalva', '160:00'] as $proibido) {
            self::assertStringNotContainsString($proibido, $html);
        }
        $this->assertSemIndiceNemCache();
        self::assertCount(1, $tela->filter('head > meta[name="robots"][content="noindex, nofollow"]'));
    }

    #[TestDox('A janela da tela consulta a mesma rota com Accept JSON (código em minúsculas): autêntico com o texto da folha')]
    public function testConsultaDaJanela(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();

        $this->client->request('GET', '/ponto/validar/' . strtolower($folha->getCodigo()), [], [], self::JSON);

        self::assertResponseIsSuccessful();
        self::assertSame(['autentico' => true, 'texto' => self::TEXTO], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertSemIndiceNemCache();
        $this->client->request('GET', '/ponto/validar?codigo=' . $folha->getCodigo(), [], [], self::JSON);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['autentico'], 'pelo campo do formulário');
    }

    #[TestDox('Folha de um escritório, consultada logado em OUTRO (filtro do escritório ligado): vale igual, a página é pública')]
    public function testFolhaDeOutroEscritorio(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();
        $visitante = $this->pessoa('de_outro_escritorio');
        $outro = $this->escritorio('Escritório do visitante');
        $this->vincularColaborador($visitante, $outro);

        $this->logarComTenant($this->client, $visitante, $outro);
        $tela = $this->client->request('GET', '/ponto/validar/' . $folha->getCodigo());
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('#^Comprovante autêntico\\. ?' . preg_quote(self::TEXTO, '#') . '$#u', trim($tela->filter('main .pv-ok[role="status"]')->text()));
        self::assertSame(self::TEXTO, json_decode($this->json($folha->getCodigo()), true)['texto']);
    }

    #[TestDox('HMAC trocado e código que não existe recebem a mesma resposta, byte a byte (página e JSON)')]
    public function testHmacTrocadoEInexistenteIguais(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();
        $codigo = $folha->getCodigo();
        $this->em()->getConnection()->executeStatement('UPDATE ponto_folha_emitida SET hmac = :h WHERE id = :id', ['h' => str_repeat('0', 64), 'id' => $folha->getId()]);
        $this->em()->clear();   // o banco como está: o HMAC trocado, não o guardado na memória

        $adulterado = [$this->pagina($codigo), $this->json($codigo)];
        $this->em()->getConnection()->executeStatement('DELETE FROM ponto_folha_emitida WHERE id = :id', ['id' => $folha->getId()]);
        $inexistente = [$this->pagina($codigo), $this->json($codigo)];

        self::assertSame($inexistente, $adulterado);
        self::assertStringContainsString('Código não localizado.', $inexistente[0]);
        self::assertStringNotContainsString('Maria', $inexistente[0]);
        self::assertSame(self::NAO_AUTENTICO, $inexistente[1]);
    }

    #[TestDox('Código errado (um caractere trocado), formato errado e prefixo desconhecido: a mesma caixa "Código não localizado." e o mesmo JSON')]
    public function testCodigoErradoMesmaResposta(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();
        $errado = substr($folha->getCodigo(), 0, -1) . (str_ends_with($folha->getCodigo(), 'A') ? 'B' : 'A');

        $caixas = [];
        foreach ([$errado, "BJF-ABCD' OR 1=1", 'BJF-ABCD-EFGH', 'XYZ-ABCD-EFGH-JK23'] as $digitado) {
            self::assertSame(self::NAO_AUTENTICO, $this->json($digitado), $digitado);
            $tela = $this->client->request('GET', '/ponto/validar?codigo=' . rawurlencode($digitado));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $tela->filter('.pv-ok'), $digitado);
            $caixas[] = $tela->filter('main .pv-erro[role="alert"]')->outerHtml();
        }

        self::assertCount(1, array_unique($caixas));
        self::assertStringContainsString('Código não localizado.', $caixas[0]);
    }

    #[TestDox('Sem o segredo do HMAC: mesmo a folha que existe, selada antes, responde "Código não localizado."')]
    public function testSemSegredo(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto(null));
        $folha = $this->emitir();

        self::assertSame(self::NAO_AUTENTICO, $this->json($folha->getCodigo()));
        $tela = $this->client->request('GET', '/ponto/validar/' . $folha->getCodigo());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('.pv-erro'));
        self::assertCount(0, $tela->filter('.pv-ok'));
    }

    #[TestDox('O limite ponto_validacao vale para os dois prefixos: cada consulta, BJF ou BJP, gasta a cota do IP de quem pede')]
    public function testLimiteParaOsDoisPrefixos(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.ponto_validacao', $espiao);

        foreach (['BJF-ABCD-EFGH-JK23', 'BJP-ABCD-EFGH-JK23'] as $codigo) {
            $this->client->request('GET', '/ponto/validar/' . $codigo, [], [], self::JSON + ['REMOTE_ADDR' => '203.0.113.9']);
            self::assertResponseIsSuccessful();
        }

        self::assertSame(['203.0.113.9', '203.0.113.9'], $espiao->chavesConsumidas);
    }

    #[TestDox('Cota estourada: a folha que existe não é conferida; 429 com o texto, na página e no JSON, ainda sem índice e sem cache')]
    public function testCotaEstourada(): void
    {
        $this->seloPronto();
        $folha = $this->emitir();
        static::getContainer()->set('limiter.ponto_validacao', new RateLimiterFactoryEspiao(aceita: false));

        $this->client->request('GET', '/ponto/validar/' . $folha->getCodigo(), [], [], self::JSON);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['autentico' => false, 'erro' => ValidacaoDoComprovanteController::MUITAS_TENTATIVAS], json_decode((string) $this->client->getResponse()->getContent(), true));
        $tela = $this->client->request('GET', '/ponto/validar/' . $folha->getCodigo());
        self::assertResponseStatusCodeSame(429);
        self::assertSame(ValidacaoDoComprovanteController::MUITAS_TENTATIVAS, $tela->filter('.pv-aviso')->text());
        self::assertStringNotContainsString('Folha de Ponto', (string) $this->client->getResponse()->getContent());
        $this->assertSemIndiceNemCache();
    }

    private function assertSemIndiceNemCache(): void
    {
        $cabecalhos = $this->client->getResponse()->headers;
        self::assertSame('noindex, nofollow', $cabecalhos->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $cabecalhos->get('Cache-Control'));
        self::assertSame('no-referrer', $cabecalhos->get('Referrer-Policy'));
    }

    private function pagina(string $codigo): string
    {
        $this->client->request('GET', '/ponto/validar/' . $codigo);
        self::assertResponseIsSuccessful();

        return $this->client->getResponse()->getStatusCode() . (string) $this->client->getResponse()->getContent();
    }

    private function json(string $codigo): string
    {
        $this->client->request('GET', '/ponto/validar/' . rawurlencode($codigo), [], [], self::JSON);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    /** O segredo do Ponto configurado (as chaves da E9): o selo da folha fica pronto. */
    private function seloPronto(): void
    {
        static::getContainer()->set(ChavesDoHmacDoPonto::class, new ChavesDoHmacDoPonto(self::SEGREDO));
    }

    /**
     * A folha de 10/2026 da Maria, emitida em 03/11/2026 às 09:00 e selada com o segredo de teste (como o motor da
     * emissão faz), com o aceite com ressalva impresso e um resumo com local e totais: nada disso vai à página aberta.
     */
    private function emitir(): FolhaEmitidaDoPonto
    {
        [$escritorio, $pessoa] = [$this->escritorio('Escritório da folha'), $this->pessoaComPerfil()];
        $codigo = (new CodigoDaFolha(static::getContainer()->get(FolhaEmitidaDoPontoRepository::class)))->novo();
        [$emitidaEm, $impressao] = [new \DateTimeImmutable('2026-11-03T09:00:00-03:00'), hash('sha256', 'resumo')];
        $resumo = ['dias' => [['dia' => '2026-10-03', 'obs' => 'Sede Centro -23.55000, -46.63000']], 'totais' => ['trabalhado' => '160:00']];
        $dados = $this->dados($codigo, $escritorio, $pessoa, $emitidaEm, $impressao, $resumo)->canonico();
        $selado = (new SeloDaFolhaHmac(new ChavesDoHmacDoPonto(self::SEGREDO)))->selar($dados);
        $folha = new FolhaEmitidaDoPonto($escritorio, $pessoa, $pessoa, 2026, 10, $codigo, $selado['hmac'], $selado['versao'], $emitidaEm, $impressao, $dados);
        $this->em()->persist($folha);
        $this->em()->flush();

        return $folha;
    }

    /** @param array<mixed> $resumo */
    private function dados(string $codigo, Tenant $escritorio, User $pessoa, \DateTimeImmutable $emitidaEm, string $impressao, array $resumo): DadosDaFolhaEmitida
    {
        $nome = trim((string) $pessoa->getFullName());

        return new DadosDaFolhaEmitida(
            $codigo,
            2026,
            10,
            (int) $escritorio->getId(),
            (int) $pessoa->getId(),
            $nome,
            (string) $escritorio->getName(),
            null,
            $emitidaEm,
            $pessoa->getId(),
            $nome,
            $impressao,
            $resumo,
            SituacaoDoAceiteDaFolha::Ressalva,
            new \DateTimeImmutable('2026-11-02T10:00:00-03:00'),
            'Faltou a batida do dia 3.',
        );
    }

    /** Pessoa com CPF no perfil: a página não pode mostrá-lo. */
    private function pessoaComPerfil(): User
    {
        $pessoa = $this->pessoa('validacao_folha');
        $pessoa->setFullName('Maria da Silva Pereira');
        $this->em()->persist((new UserProfile($pessoa))->setCpf('123.456.789-09'));
        $this->em()->flush();

        return $pessoa;
    }
}
