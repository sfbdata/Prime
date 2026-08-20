<?php

declare(strict_types=1);

namespace App\Tests\Legal\Functional;

use App\Legal\Controller\PoliticaPrivacidadeController;
use App\Legal\PoliticaPrivacidadeVigente;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O que estes testes protegem, por ordem de importância:
 *
 * 1. **a página abrir sem conta.** O link mora no rodapé do login: se o `security.yaml`
 *    perder a entrada `PUBLIC_ACCESS`, o coringa `^/` manda tudo para /login e o documento
 *    fica ilegível justamente para quem ainda não é cliente. Redirect 302 aqui é defeito;
 * 2. **nenhum `[inserir]` sobreviver.** O .docx chegou como minuta editável, com 14 campos
 *    em branco — inclusive o Anexo I inteiro. Publicar uma política de privacidade com
 *    "[inserir país]" na tela é pior que o link morto que existia antes;
 * 3. **o Anexo I dizer o que foi medido**, e não o que o modelo trazia.
 *
 * O que NENHUM teste aqui vê: tipografia, espaçamento e como a tabela quebra no celular.
 * Isso é smoke na tela, do dono.
 */
#[CoversClass(PoliticaPrivacidadeController::class)]
final class PoliticaPrivacidadeControllerTest extends JusPrimeWebTestCase
{
    private const ROTA = '/politica-de-privacidade';

    #[TestDox('A página abre para quem não tem conta — é para isso que ela existe')]
    public function testAbreSemAutenticacao(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA);

        self::assertResponseIsSuccessful();
    }

    #[TestDox('Nenhum campo [inserir] da minuta sobreviveu na página publicada')]
    public function testNaoRestaCampoPorPreencher(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA);

        $html = (string) $client->getResponse()->getContent();

        self::assertStringNotContainsString('[inserir', $html);
        self::assertStringNotContainsString('Nota de revisão', $html);
    }

    #[TestDox('Traz os 24 capítulos e os 3 anexos, cada um com âncora própria')]
    public function testTrazTodosOsCapitulosEAnexos(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', self::ROTA);

        for ($n = 1; $n <= 24; $n++) {
            self::assertCount(1, $crawler->filter('h2.pp-capitulo#cap-' . $n), "faltou o capítulo $n");
        }
        foreach (['i', 'ii', 'iii'] as $anexo) {
            self::assertCount(1, $crawler->filter('h2.pp-capitulo#anexo-' . $anexo), "faltou o Anexo $anexo");
        }

        // O sumário precisa cobrir os 27 — um item órfão é link que não leva a lugar nenhum.
        self::assertCount(27, $crawler->filter('.pp-sumario-lista li a'));
    }

    #[TestDox('Versão e data da constante chegam renderizadas nos dois lugares do documento')]
    public function testVersaoEDataChegamRenderizadas(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA);

        $html = (string) $client->getResponse()->getContent();
        $data = (new PoliticaPrivacidadeVigente())->getDataPublicacao()->format('d/m/Y');

        self::assertStringContainsString(PoliticaPrivacidadeVigente::VERSAO, $html);
        // Dois lugares: o cabeçalho e o Anexo III. Se um deles for para texto solto, some daqui.
        self::assertSame(2, substr_count($html, $data), 'a data deve sair no cabeçalho E no Anexo III');
    }

    #[TestDox('A data de publicação é literal e não está no futuro')]
    public function testDataDePublicacaoNaoEDerivada(): void
    {
        // Este teste é literal DE PROPÓSITO. A versão anterior derivava o esperado da própria
        // constante e passava com qualquer valor — não guardava nada. Escrito assim, mudar a
        // data obriga a mexer aqui também: vira um ato consciente, visível no diff da revisão.
        //
        // O que ele NÃO consegue fazer, e é honesto dizer: nenhum teste sabe o dia do deploy.
        // Se a frente for publicada semanas depois, a data continuará desatualizada sem quebrar
        // nada. Por isso trocá-la é item da lista de deploy, não só deste arquivo.
        self::assertSame('2026-08-20', PoliticaPrivacidadeVigente::DATA_PUBLICACAO);

        // Uma política não entra em vigor no futuro: "Vigência: a partir da publicação".
        self::assertLessThanOrEqual(
            new \DateTimeImmutable('today'),
            (new PoliticaPrivacidadeVigente())->getDataPublicacao(),
            'data de publicação no futuro contradiz a cláusula de vigência do documento',
        );
    }

    #[TestDox('O Anexo I lista os 3 suboperadores medidos, e nenhum dos que não existem')]
    public function testAnexoITrazApenasOsSuboperadoresReais(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', self::ROTA);

        $linhas = $crawler->filter('#anexo-i-suboperadores tbody tr');
        self::assertCount(3, $linhas);

        $tabela = $crawler->filter('#anexo-i-suboperadores')->html();

        self::assertStringContainsString('Hostinger', $tabela);
        self::assertStringContainsString('Brasil (São Paulo)', $tabela);
        self::assertStringContainsString('Google LLC (Gmail / SMTP)', $tabela);
        self::assertStringContainsString('Google LLC (Google Drive)', $tabela);

        // As três linhas do modelo saíram por decisão do dono: nenhum desses serviços existe
        // hoje. Se um deles for contratado sem atualizar o anexo, a política passa a mentir —
        // e é este teste que obriga a passar por aqui.
        self::assertStringNotContainsString('Processamento de pagamentos', $tabela);
        self::assertStringNotContainsString('Assinatura eletrônica de documentos', $tabela);
        self::assertStringNotContainsString('inteligência artificial', $tabela);
    }

    #[TestDox('A página é pública mas não indexável — decisão do dono')]
    public function testPaginaNaoEIndexavel(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', self::ROTA);

        $robots = $crawler->filter('meta[name="robots"]');
        self::assertCount(1, $robots);
        self::assertStringContainsString('noindex', (string) $robots->attr('content'));

        // Pública e não indexável não são a mesma coisa: `noindex` tira dos buscadores, não
        // tranca a porta. O rodapé do login continua levando qualquer visitante até aqui.
        self::assertResponseIsSuccessful();
    }

    #[TestDox('Com a cota estourada, o PDF devolve 429 em vez de renderizar')]
    public function testPdfRespeitaOLimitador(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('limiter.politica_privacidade_pdf', new RateLimiterFactoryEspiao(aceita: false));

        $client->request('GET', self::ROTA . '.pdf');

        self::assertResponseStatusCodeSame(429);
    }

    #[TestDox('A página HTML não gasta a cota do PDF — só o PDF custa CPU')]
    public function testPaginaHtmlNaoConsomeOLimitador(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $espiao = new RateLimiterFactoryEspiao(aceita: true);
        static::getContainer()->set('limiter.politica_privacidade_pdf', $espiao);

        $client->request('GET', self::ROTA);

        self::assertResponseIsSuccessful();
        // Limitar a leitura da página seria estrangular um documento legal por engano: o custo
        // que justifica o limitador é o do dompdf, e a página não roda dompdf.
        self::assertSame([], $espiao->chavesConsumidas);
    }

    #[TestDox('O PDF sai do mesmo texto e responde como PDF de verdade')]
    public function testPdfRespondeComoPdf(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA . '.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex');

        $pdf = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF', $pdf);

        // Cabeçalho e bytes iniciais não provam CONTEÚDO: apagando o include do texto no
        // template, a suíte seguia verde entregando um PDF em branco. O `/ToUnicode` identidade
        // do dompdf (ver o controller) impede asserção sobre o texto, mas não impede estas duas.
        self::assertGreaterThan(60_000, strlen($pdf), 'PDF pequeno demais para conter o documento');

        $paginas = substr_count($pdf, '/Type /Page') - substr_count($pdf, '/Type /Pages');
        self::assertGreaterThanOrEqual(10, $paginas, "o documento tem 16 páginas; vieram {$paginas}");
    }
}
