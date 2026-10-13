<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Controller\PastaResumoController;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaObservacaoDetalhes;
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
 *
 * C6-EDT-06: o registro (observação de Detalhes) e o resumo do processo também baixam PDF, com o
 * corpo montado pelo SERVIDOR a partir do id. O alvo tem de ser desta pasta e deste escritório
 * (404), depois dos guardas da pasta (404 de escritório, 403 de quem não vê).
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
        self::assertStringStartsNotWith('%PDF-', (string) $client->getResponse()->getContent());
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

    private const NUMERO        = '07011345720258070007';
    private const NUMERO_MASCARA = '0701134-57.2025.8.07.0007';

    private function criarObservacao(Pasta $pasta, User $autor, Tenant $tenant, string $conteudo): PastaObservacaoDetalhes
    {
        $obs = (new PastaObservacaoDetalhes())
            ->setPasta($pasta)
            ->setAutor($autor)
            ->setTenant($tenant)
            ->setConteudo($conteudo);
        $this->em()->persist($obs);
        $this->em()->flush();

        return $obs;
    }

    #[TestDox('C6-EDT-06: o registro baixa PDF montado pelo servidor — "Registro do expediente", autor · data · Atendimento e o texto')]
    public function testBaixaOPdfDaObservacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObservacao($pasta, $user, $tenant, '<p>Cliente ligou pedindo atualização do caso</p>');

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'observacao', 'obs' => (string) $obs->getId()]));

        self::assertResponseIsSuccessful();
        $resposta = $client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $nome = 'BlueJus Registro do expediente ' . (new \DateTimeImmutable())->format('d.m.Y') . '.pdf';
        self::assertStringContainsString('filename="' . $nome . '"', (string) $resposta->headers->get('Content-Disposition'));

        $texto = $this->textoDoPdf((string) $resposta->getContent());
        self::assertStringContainsString('Registro do expediente', $texto);
        self::assertStringContainsString((string) $user->getFullName(), $texto);
        self::assertStringContainsString($obs->getCriadaEm()->format('d/m/Y'), $texto);
        self::assertStringContainsString('Atendimento', $texto);
        self::assertStringContainsString('Cliente ligou pedindo atualização do caso', $texto);
        self::assertStringNotContainsString('<p>', $texto, 'o HTML do registro vira texto, não marcação crua');
    }

    #[TestDox('C6-EDT-06: o resumo do processo baixa PDF com as linhas do "Copiar resumo", montadas pelo servidor')]
    public function testBaixaOPdfDoProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $processo->setSiglaTribunal('TJDFT');
        $this->em()->flush();
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $user, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'processo', 'processo' => (string) $processo->getId()]));

        self::assertResponseIsSuccessful();
        $resposta = $client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        $nome = 'BlueJus Processo ' . self::NUMERO_MASCARA . ' ' . (new \DateTimeImmutable())->format('d.m.Y') . '.pdf';
        self::assertStringContainsString('filename="' . $nome . '"', (string) $resposta->headers->get('Content-Disposition'));

        $texto = $this->textoDoPdf((string) $resposta->getContent());
        self::assertStringContainsString('Processo ' . self::NUMERO_MASCARA, $texto);
        self::assertStringContainsString('Classe', $texto);
        self::assertStringContainsString($processo->getClasseProcessual(), $texto);
        self::assertStringContainsString('TJDFT', $texto);
        self::assertStringContainsString('Pasta ' . $pasta->getNup(), $texto);
    }

    #[TestDox('C6-EDT-06: registro de OUTRA pasta do mesmo escritório, de OUTRO escritório, id ausente ou texto → 404, sem PDF')]
    public function testObservacaoForaDaPastaDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaA            = $this->criarPasta($tenantA);
        $outraPastaA       = $this->criarPasta($tenantA);
        $pastaB            = $this->criarPasta($tenantB);
        $obsDaOutraPasta   = $this->criarObservacao($outraPastaA, $userA, $tenantA, 'REGISTRO DA OUTRA PASTA');
        $obsDoOutroTenant  = $this->criarObservacao($pastaB, $userB, $tenantB, 'REGISTRO DO ESCRITÓRIO B');

        $this->logarComTenant($client, $userA, $tenantA);

        foreach ([(string) $obsDaOutraPasta->getId(), (string) $obsDoOutroTenant->getId(), '', 'abc', '0'] as $id) {
            $client->request('GET', $this->url((int) $pastaA->getId(), ['secao' => 'observacao', 'obs' => $id]));
            self::assertResponseStatusCodeSame(404, 'obs=' . $id);
            $corpo = (string) $client->getResponse()->getContent();
            self::assertStringStartsNotWith('%PDF-', $corpo);
            self::assertStringNotContainsString('REGISTRO DA OUTRA PASTA', $corpo);
            self::assertStringNotContainsString('REGISTRO DO ESCRITÓRIO B', $corpo);
        }

        // A barreira provada acima é a do registro, não a da pasta: o registro da própria pasta A sai.
        // A requisição limpa o EntityManager: relê as entidades antes de pendurar o registro nelas.
        $pastaA  = $this->em()->find(Pasta::class, $pastaA->getId());
        $userA   = $this->em()->find(User::class, $userA->getId());
        $tenantA = $this->em()->find(Tenant::class, $tenantA->getId());
        $proprio = $this->criarObservacao($pastaA, $userA, $tenantA, 'Registro da pasta A');
        $client->request('GET', $this->url((int) $pastaA->getId(), ['secao' => 'observacao', 'obs' => (string) $proprio->getId()]));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('C6-EDT-06: processo não vinculado à pasta, de OUTRO escritório, ou id inválido → 404, sem PDF')]
    public function testProcessoForaDaPastaDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [, $tenantB]       = $this->criarAdmin();
        $pastaA            = $this->criarPasta($tenantA);
        $soltoA            = $this->criarProcesso($tenantA, '00000011120258070001');
        $pastaB            = $this->criarPasta($tenantB);
        $processoB         = $this->criarProcesso($tenantB, '00000022220258070001');
        $this->vincular($pastaB, $processoB);

        $this->logarComTenant($client, $userA, $tenantA);

        foreach ([(string) $soltoA->getId(), (string) $processoB->getId(), '', 'abc'] as $id) {
            $client->request('GET', $this->url((int) $pastaA->getId(), ['secao' => 'processo', 'processo' => $id]));
            self::assertResponseStatusCodeSame(404, 'processo=' . $id);
            self::assertStringStartsNotWith('%PDF-', (string) $client->getResponse()->getContent());
        }

        // A barreira provada acima é a do vínculo: o mesmo processo, vinculado à pasta A, sai.
        $this->vincular($this->em()->find(Pasta::class, $pastaA->getId()), $this->em()->find(\get_class($soltoA), $soltoA->getId()));
        $client->request('GET', $this->url((int) $pastaA->getId(), ['secao' => 'processo', 'processo' => (string) $soltoA->getId()]));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('C6-EDT-06: pasta de OUTRO escritório no PDF do registro e do processo → 404 (o guarda da pasta vem antes)')]
    public function testRegistroEProcessoDePastaDeOutroEscritorioDa404(): void
    {
        $client            = static::createClient();
        [$userA, $tenantA] = $this->criarAdmin();
        [$userB, $tenantB] = $this->criarAdmin();
        $pastaB            = $this->criarPasta($tenantB);
        $obsB              = $this->criarObservacao($pastaB, $userB, $tenantB, 'SEGREDO DE B');
        $processoB         = $this->criarProcesso($tenantB, self::NUMERO);
        $this->vincular($pastaB, $processoB);

        $this->logarComTenant($client, $userA, $tenantA);
        $client->request('GET', $this->url((int) $pastaB->getId(), ['secao' => 'observacao', 'obs' => (string) $obsB->getId()]));
        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsNotWith('%PDF-', (string) $client->getResponse()->getContent());
        $client->request('GET', $this->url((int) $pastaB->getId(), ['secao' => 'processo', 'processo' => (string) $processoB->getId()]));
        self::assertResponseStatusCodeSame(404);
        self::assertStringStartsNotWith('%PDF-', (string) $client->getResponse()->getContent());
    }

    #[TestDox('C6-EDT-06: quem não vê a pasta leva 403 no PDF do registro e do processo')]
    public function testRegistroEProcessoSemPermissaoDa403(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $semNada         = $this->criarUsuarioSemNenhumaPermissao($tenant);
        $pasta           = $this->criarPasta($tenant);
        $obs             = $this->criarObservacao($pasta, $user, $tenant, 'SEGREDO DO REGISTRO');
        $processo        = $this->criarProcesso($tenant, self::NUMERO);
        $this->vincular($pasta, $processo);

        $this->logarComTenant($client, $semNada, $tenant);
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'observacao', 'obs' => (string) $obs->getId()]));
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('SEGREDO DO REGISTRO', (string) $client->getResponse()->getContent());
        $client->request('GET', $this->url((int) $pasta->getId(), ['secao' => 'processo', 'processo' => (string) $processo->getId()]));
        self::assertResponseStatusCodeSame(403);
    }
}
