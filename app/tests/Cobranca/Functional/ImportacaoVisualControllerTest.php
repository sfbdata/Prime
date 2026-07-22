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
