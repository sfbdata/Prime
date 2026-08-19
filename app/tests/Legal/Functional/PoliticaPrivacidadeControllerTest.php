<?php

declare(strict_types=1);

namespace App\Tests\Legal\Functional;

use App\Legal\Controller\PoliticaPrivacidadeController;
use App\Legal\PoliticaPrivacidadeVigente;
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

    #[TestDox('Versão e data saem da constante, não de texto solto no template')]
    public function testVersaoEDataVemDaConstante(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA);

        $html = (string) $client->getResponse()->getContent();
        $data = (new PoliticaPrivacidadeVigente())->getDataPublicacao()->format('d/m/Y');

        self::assertStringContainsString(PoliticaPrivacidadeVigente::VERSAO, $html);
        self::assertStringContainsString($data, $html);
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

    #[TestDox('O PDF sai do mesmo texto e responde como PDF de verdade')]
    public function testPdfRespondeComoPdf(): void
    {
        $client = static::createClient();
        $client->request('GET', self::ROTA . '.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }
}
