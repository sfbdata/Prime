<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Controller\ImportacaoController;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\ModoCarteira;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Importação VISUAL (Etapa 8C) via HTTP: fluxo upload → prévia (dry-run, não persiste) → confirmar
 * (persiste, idempotente). Prova segurança: gate de capacidade, IDOR entre tenants, CSRF na confirmação
 * e rejeição de arquivo inválido sem persistir. Reusa a fixture anonimizada TOPLIFE.
 */
#[CoversClass(ImportacaoController::class)]
final class ImportacaoVisualControllerTest extends CobrancaWebTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra.xlsx';

    /** Mesmo conteúdo da amostra, gravado em Zip64/store: o libmagic devolve `application/octet-stream`. */
    private const FIXTURE_MIME_INDETECTAVEL = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra_zip64.xlsx';

    /** @var list<string> arquivos criados pelo teste, apagados no tearDown */
    private array $temporariosDoTeste = [];

    #[TestDox('Fluxo completo: upload não persiste na prévia; confirmar importa os boletos')]
    public function testFluxoUploadPreviewConfirmar(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);

        // Passo 1: tela de upload.
        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        self::assertResponseIsSuccessful();

        // Passo 2: envia o arquivo → PRÉVIA. Nada é gravado ainda.
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload(self::FIXTURE);
        $crawler = $client->submit($form);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Prévia da importação', $html);
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]), 'a prévia NÃO persiste');

        // A linha só-encargos (sem principal) aparece rejeitada com motivo (decisão da E7 — intocável).
        self::assertStringContainsString('principal', $html);

        // Passo 3: confirma → importa de verdade.
        $confirmar = $crawler->filter('form[action*="confirmar"]')->form();
        $client->submit($confirmar);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Importação concluída', (string) $client->getResponse()->getContent());
        self::assertSame(6, $obrigacaoRepo->count(['tenant' => $tenant]), 'os 6 boletos importáveis foram gravados');
    }

    /**
     * Os dois avisos da chave NN+competência precisam aparecer NA TELA, não só na CLI.
     *
     * Eles existem no resultado desde que a chave foi corrigida, mas o Twig não os mostrava — e a tela é
     * o caminho que o gestor usa de verdade. O defeito que aquela spec corrigiu era exatamente o
     * silêncio: confirmar uma importação sem saber que uma dívida nova nasceu com um número repetido, ou
     * que um boleto foi reemitido com outra data.
     *
     * O cenário força os dois de uma vez, mexendo no banco entre as duas importações do MESMO arquivo:
     * uma obrigação tem a competência trocada (o mesmo NN passa a valer para outra dívida) e outra tem o
     * vencimento adiantado (boleto reemitido).
     */
    #[TestDox('A prévia mostra na TELA os avisos de NN reutilizado e de vencimento alterado')]
    public function testPreviaMostraAvisosDaChaveNaTela(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $obrigacaoRepo = $em->getRepository(Obrigacao::class);

        // 1ª importação: o estado "de antes".
        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload(self::FIXTURE);
        $crawler = $client->submit($form);
        $client->submit($crawler->filter('form[action*="confirmar"]')->form());

        $importadas = $obrigacaoRepo->findBy(['tenant' => $tenant], ['id' => 'ASC']);
        self::assertGreaterThanOrEqual(2, count($importadas), 'o cenário precisa de duas obrigações para mexer');

        // A primeira passa a ser de OUTRA competência: o NN do relatório vira "reutilizado".
        $importadas[0]->setCompetencia('01/2020');
        // A segunda mantém a competência e ganha vencimento antigo: o relatório traz data nova (reemissão).
        $importadas[1]->setVencimentoOriginal(new \DateTimeImmutable('2020-01-10'));
        $em->flush();

        // 2ª importação do MESMO arquivo: agora a prévia tem de avisar as duas coisas.
        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload(self::FIXTURE);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Nosso Número reutilizado', $html, 'sem isto o gestor confirma sem saber que nasce dívida nova');
        self::assertStringContainsString((string) $importadas[0]->getReferenciaExterna(), $html);
        self::assertStringContainsString('Vencimento alterado', $html, 'boleto reemitido tem de ser visível');
        self::assertStringContainsString((string) $importadas[1]->getReferenciaExterna(), $html);
    }

    #[TestDox('Sem a capacidade gerenciar, a importação nega acesso')]
    public function testSemCapacidadeNegaAcesso(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarOperadorSemCapacidade($client);
        $carteiraId = $this->semearCarteira($tenant);

        $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");

        self::assertResponseRedirects();
    }

    #[TestDox('Carteira de outro tenant retorna 404 (anti-IDOR)')]
    public function testCarteiraDeOutroTenantRetorna404(): void
    {
        $client = static::createClient();
        $this->criarAdminLogado($client);
        $carteiraOutra = $this->semearCarteira($this->tenantAvulso());

        $client->request('GET', "/cobrancas/carteiras/{$carteiraOutra}/importar");

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Confirmar com CSRF inválido não persiste')]
    public function testConfirmarComCsrfInvalidoNaoPersiste(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);

        // Prévia válida (popula a sessão) antes de tentar confirmar com token adulterado.
        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload(self::FIXTURE);
        $client->submit($form);

        $client->request('POST', "/cobrancas/carteiras/{$carteiraId}/importar/confirmar", ['_token' => 'invalido']);

        self::assertResponseRedirects();
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]), 'CSRF inválido barra a gravação');
    }

    #[TestDox('Confirmar sem prévia na sessão redireciona para o upload (sessão expirada)')]
    public function testConfirmarSemPreviaRedirecionaParaUpload(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);
        $this->instalarCsrfStorage();

        // CSRF VÁLIDO mas sem prévia → o ramo "sessão expirada" (não é o de CSRF) redireciona ao upload.
        $client->request('POST', "/cobrancas/carteiras/{$carteiraId}/importar/confirmar", ['_token' => 'TOKEN_importar_confirmar_' . $carteiraId]);

        self::assertResponseRedirects("/cobrancas/carteiras/{$carteiraId}/importar");
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]));
    }

    #[TestDox('Planilha cujo mime o libmagic não sabe nomear (Zip64/streaming) é aceita mesmo assim')]
    public function testPlanilhaComMimeIndetectavelEhAceita(): void
    {
        // REGRESSÃO (relatório real de 22/07): o libmagic reconhece "Microsoft OOXML" mas não consegue
        // nomear o subtipo quando o gerador grava em Zip64/streaming — devolve application/octet-stream.
        // A whitelist de mime recusava, com "Envie uma planilha do Excel", um arquivo que o PhpSpreadsheet
        // lê sem qualquer problema. Quem manda é a assinatura + a leitura, não o palpite do libmagic.
        self::assertSame(
            'application/octet-stream',
            (new \finfo(\FILEINFO_MIME_TYPE))->file(self::FIXTURE_MIME_INDETECTAVEL),
            'a fixture precisa continuar reproduzindo o mime indetectável, senão o teste não prova nada',
        );

        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);

        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload(self::FIXTURE_MIME_INDETECTAVEL);
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Prévia da importação', (string) $client->getResponse()->getContent());
    }

    /**
     * 🔑 A TELA também confere o recorte — achado ALTA da 1ª revisão do item 6.
     *
     * A primeira versão do validador só entrou nos 4 comandos, e esta tela continuava sendo a outra
     * porta de entrada da inadimplência: aceitava recorte errado em silêncio, que é exatamente a falha
     * que o item existe para fechar (um arquivo com filtro errado importa lindamente e o número fica
     * menor que a realidade). Spec: `docs/specs/cobranca-validador-rodape-filtros.md`.
     *
     * A prévia é onde a conferência acontece, e é o suficiente: o ponteiro do arquivo só entra na
     * sessão DEPOIS de a prévia passar, então nenhum caminho alcança o `confirmar` sem passar por aqui.
     */
    #[TestDox('🔑 tela: planilha com recorte errado é recusada na prévia, sem persistir')]
    public function testTelaRecusaRecorteErrado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);

        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload($this->fixtureComRecorteErrado());
        $client->submit($form);

        self::assertResponseRedirects();
        $client->followRedirect();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('recorte deste arquivo não serve', $html);
        self::assertStringContainsString('Competência', $html);
        self::assertStringNotContainsString('Prévia da importação', $html, 'não pode chegar à prévia');
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]), 'nada pode ser gravado');
    }

    /**
     * A mesma amostra, com UM campo do rodapé trocado por um recorte que a importação não aceita
     * (competência filtrada em vez de "Todas"). Reescreve só a string dentro do `.xlsx` — o resto do
     * arquivo continua idêntico, então a única diferença entre passar e ser recusado é o recorte.
     */
    private function fixtureComRecorteErrado(): string
    {
        $destino = tempnam(sys_get_temp_dir(), 'recorte') . '.xlsx';
        copy(self::FIXTURE, $destino);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($destino) === true);
        $alvo = 'xl/sharedStrings.xml';
        $xml = (string) $zip->getFromName($alvo);
        $trocado = str_replace('Competência: Todas', 'Competência: 07/2026', $xml);
        self::assertNotSame($xml, $trocado, 'a fixture precisa conter o campo que este teste corrompe');
        $zip->addFromString($alvo, $trocado);
        $zip->close();

        $this->temporariosDoTeste[] = $destino;

        return $destino;
    }

    #[TestDox('HTML renomeado para .xlsx continua rejeitado sem persistir')]
    public function testHtmlRenomeadoParaXlsxRejeitado(): void
    {
        // O contraponto do teste acima: afrouxar a validação não pode abrir a porta para o "xls" que
        // vários sistemas exportam como tabela HTML — sem assinatura de planilha, não entra.
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);

        $falso = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        file_put_contents($falso, "<html><body><table><tr><td>170,00</td></tr></table></body></html>");

        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload($falso);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]));

        @unlink($falso);
    }

    #[TestDox('Arquivo de tipo inválido é rejeitado sem persistir')]
    public function testArquivoInvalidoRejeitado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        $carteiraId = $this->semearCarteira($tenant);
        $obrigacaoRepo = static::getContainer()->get(EntityManagerInterface::class)->getRepository(Obrigacao::class);

        $txt = tempnam(sys_get_temp_dir(), 'imp') . '.txt';
        file_put_contents($txt, "isto nao e uma planilha\n");

        $crawler = $client->request('GET', "/cobrancas/carteiras/{$carteiraId}/importar");
        $form = $crawler->filter('form[action*="prever"]')->form();
        $form['importar_relatorio[arquivo]']->upload($txt);
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertSame(0, $obrigacaoRepo->count(['tenant' => $tenant]));

        @unlink($txt);
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function semearCarteira(Tenant $tenant): int
    {
        $carteira = CarteiraFactory::createOne([
            'tenant' => $tenant,
            'cliente' => ClientePFFactory::createOne(['tenant' => $tenant]),
            'modo' => ModoCarteira::Unico,
        ]);

        return (int) $carteira->getId();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporariosDoTeste as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }
        $this->temporariosDoTeste = [];

        // Remove os temporários de importação criados no disco de teste (var/uploads-test/cobrancas/import-tmp).
        $dir = __DIR__ . '/../../../var/uploads-test/cobrancas/import-tmp';
        if (is_dir($dir)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
        }

        parent::tearDown();
    }
}
