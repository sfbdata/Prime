<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Controller\PastaResumoController;
use App\Pasta\Entity\PastaPagamento;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\GhostscriptDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Process\Process;

/**
 * EDT-002: "Baixar PDF" da janela de impressão — a folha gerada no SERVIDOR (Dompdf), baixada
 * como anexo `BlueJus <título> dd.mm.aaaa.pdf`, com o rodapé "BlueJus · página k de n" em cada
 * página. Mesmos guardas e mesmas opções da folha da tela.
 *
 * O texto do PDF é lido pelo Ghostscript (`txtwrite`), o mesmo binário que a aplicação já usa:
 * assim o teste prova o que está no ARQUIVO, e não só o HTML de entrada.
 */
#[CoversClass(PastaResumoController::class)]
#[Group('pasta')]
final class PastaResumoPdfControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    /** @param array<string, string> $query */
    private function url(int $pastaId, array $query = []): string
    {
        return "/pasta/{$pastaId}/resumo/pdf" . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /** O texto do PDF, página a página, como o Ghostscript o lê. */
    private function textoDoPdf(string $pdf): string
    {
        self::assertTrue(GhostscriptDeTeste::disponivel(), 'o Ghostscript da imagem lê o texto do PDF');
        $arquivo = (string) tempnam(sys_get_temp_dir(), 'resumo-pdf-');
        file_put_contents($arquivo, $pdf);

        try {
            $processo = new Process([
                GhostscriptDeTeste::binario(), '-q', '-dBATCH', '-dNOPAUSE', '-dSAFER',
                '-sDEVICE=txtwrite', '-sOutputFile=-', $arquivo,
            ]);
            $processo->mustRun();

            return (string) preg_replace('/[ \t]+/u', ' ', $processo->getOutput());
        } finally {
            @unlink($arquivo);
        }
    }

    #[TestDox('Quem vê a pasta baixa o PDF: application/pdf, anexo com o nome do desenho, resumo e rodapé "BlueJus · página k de n"')]
    public function testBaixaOPdfDoResumo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('CONDOMÍNIO DO PDF');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseIsSuccessful();
        $resposta = $client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $disposicao = (string) $resposta->headers->get('Content-Disposition');
        self::assertStringStartsWith('attachment;', $disposicao);
        $nome = 'BlueJus Resumo da pasta ' . $pasta->getNup() . ' ' . (new \DateTimeImmutable())->format('d.m.Y') . '.pdf';
        self::assertStringContainsString('filename="' . $nome . '"', $disposicao, 'o nome do arquivo do desenho (`pdfBaixar`)');

        $pdf = (string) $resposta->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);

        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('BLUEJUS', $texto);
        self::assertStringContainsString('Resumo da pasta ' . $pasta->getNup(), $texto);
        self::assertStringContainsString('CONDOMÍNIO DO PDF', $texto);
        self::assertStringContainsString('Documento interno do escritório · BlueJus', $texto);
        self::assertStringContainsString('BlueJus · página 1 de 1', $texto, 'o rodapé com a página, carimbado em cada página');
    }

    #[TestDox('O PDF obedece às opções da janela: sem cabeçalho não há BLUEJUS nem "Impresso em"; com financeiro, o bloco de lançamentos')]
    public function testPdfObedeceAsOpcoes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pagamento       = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao('Parcela do PDF');
        $pagamento->setValor('450.00');
        $pagamento->setVencimento(new \DateTimeImmutable('2026-09-10'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);

        $client->request('GET', $this->url((int) $pasta->getId(), ['cabecalho' => '0', 'financeiro' => '1']));
        self::assertResponseIsSuccessful();
        $texto = $this->textoDoPdf((string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('BLUEJUS', $texto);
        self::assertStringNotContainsString('Impresso em', $texto);
        self::assertStringContainsString('Parcela do PDF', $texto);
        self::assertStringContainsString('Lançamento', $texto);

        // Financeiro desligado (o padrão): o bloco de lançamentos não vai para o arquivo.
        $client->request('GET', $this->url((int) $pasta->getId()));
        self::assertStringNotContainsString('Parcela do PDF', $this->textoDoPdf((string) $client->getResponse()->getContent()));
    }

    #[TestDox('Quem não vê o financeiro baixa o PDF sem ele, mesmo pedindo financeiro=1')]
    public function testPdfSemFinanceiroParaQuemNaoVe(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setValorCausa('98765.00');
        $this->em()->flush();

        $naoVe = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $naoVe->method('podeVer')->willReturn(false);
        static::getContainer()->set(VisibilidadeDoFinanceiroDaPasta::class, $naoVe);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId(), ['financeiro' => '1']));

        self::assertResponseIsSuccessful();
        $texto = $this->textoDoPdf((string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('98.765,00', $texto);
        self::assertStringNotContainsString('Lançamento', $texto);
    }

    #[TestDox('PDF de pasta de OUTRO escritório dá 404 — e sem PDF nenhum')]
    public function testOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', $this->url((int) $pastaB->getId()));

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('%PDF-', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Usuário do MESMO escritório sem permissão de ver a pasta leva 403 no PDF')]
    public function testSemPermissaoDa403(): void
    {
        $client     = static::createClient();
        [, $tenant] = $this->criarAdmin();
        $semNada    = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta      = $this->criarPasta($tenant);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('A moldura (corpo da tela) e seção desconhecida não viram PDF: 404')]
    public function testMolduraNaoViraPdf(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'moldura']));
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'xyz']));
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Nome do arquivo: barra vira ponto e o que o sistema de arquivos recusa vira espaço')]
    public function testNomeDoArquivo(): void
    {
        $hoje = new \DateTimeImmutable('2026-10-07');

        self::assertSame('BlueJus Resumo da pasta 2024.015 07.10.2026.pdf', PastaResumoController::nomeDoArquivo('Resumo da pasta 2024/015', $hoje));
        self::assertSame('BlueJus Extrato de pagamentos 07.10.2026.pdf', PastaResumoController::nomeDoArquivo('Extrato de pagamentos', $hoje));
        self::assertSame('BlueJus a b c 07.10.2026.pdf', PastaResumoController::nomeDoArquivo('a:*?"<>|b · c', $hoje));
    }
}
