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

/**
 * Importação VISUAL (Etapa 8C) via HTTP: fluxo upload → prévia (dry-run, não persiste) → confirmar
 * (persiste, idempotente). Prova segurança: gate de capacidade, IDOR entre tenants, CSRF na confirmação
 * e rejeição de arquivo inválido sem persistir. Reusa a fixture anonimizada TOPLIFE.
 */
#[CoversClass(ImportacaoController::class)]
final class ImportacaoVisualControllerTest extends CobrancaWebTestCase
{
    private const FIXTURE = __DIR__ . '/../../Fixtures/Cobranca/importacao/toplife_amostra.xlsx';

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
