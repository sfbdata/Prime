<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Indicacao\Entity\Indicacao;
use App\Master\Controller\RecompensasDoMasterController;
use App\Recompensa\Entity\LancamentoDeRecompensa;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Baixa manual das recompensas (F4; spec perfis-1.2-vs-25-10-recompensas-e-planos): dados de recebimento, "Dar baixa" com
 * comprovante, as recusas (não liberado, bloqueado, sem dados, acima do saldo, arquivo que não é do tipo da extensão), a
 * idempotência pela chave do diálogo, o download do comprovante só do Master e o CSV com a forma.
 */
#[CoversClass(RecompensasDoMasterController::class)]
final class BaixaDeRecompensaDoMasterTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . RecompensasDoMasterController::TOKEN;
    private const CPF = '529.982.247-25';
    private const CHAVE_A = 'aaaaaaaa-1111-4000-8000-000000000001';
    private const CHAVE_B = 'bbbbbbbb-1111-4000-8000-000000000002';
    /** PNG de 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
    }

    #[TestDox('dar baixa: grava o pagamento, desconta do saldo, guarda o comprovante e o extrato mostra forma e comprovante')]
    public function testDaBaixaComComprovante(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);

        $this->baixar($ana, '4,00', 'pix', self::CHAVE_A, $this->png());

        self::assertResponseIsSuccessful();
        $linha = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame([400, 600], [$linha['pagoCentavos'], $linha['saldoCentavos']]);
        $this->em->clear();
        $pago = $this->em->getRepository(LancamentoDeRecompensa::class)->findOneBy(['tipo' => 'pago']);
        self::assertNotNull($pago);
        self::assertSame(400, $pago->getValorCentavos());
        self::assertSame('pix', $pago->getForma()?->value);
        self::assertTrue($pago->temComprovante());
        self::assertSame('comprovante.png', $pago->getComprovanteNome());

        $this->client->request('GET', '/master/recompensas/extrato');
        $linhaDoExtrato = $this->json()['extrato'][0];
        self::assertSame(['pago', 400, 'pix', true], [$linhaDoExtrato['tipo'], $linhaDoExtrato['valorCentavos'], $linhaDoExtrato['forma'], $linhaDoExtrato['temComprovante']]);

        $this->client->request('GET', '/master/recompensas/lancamentos/' . $pago->getId() . '/comprovante');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    #[TestDox('dar baixa sem comprovante vale (o desenho o deixa opcional) e a soma de pagamentos nunca passa do saldo')]
    public function testSemComprovanteENuncaPassaDoSaldo(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);

        $this->baixar($ana, '6,00', 'transferencia', self::CHAVE_A);
        self::assertResponseIsSuccessful();
        $this->baixar($ana, '4,01', 'pix', self::CHAVE_B);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('até o saldo de R$ 4,00', $this->json()['erro']);
        $this->baixar($ana, '4,00', 'pix', self::CHAVE_B);
        self::assertResponseIsSuccessful();

        $linha = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame([1000, 0], [$linha['pagoCentavos'], $linha['saldoCentavos']]);
        self::assertSame(2, $this->totalDePagamentos());
    }

    #[TestDox('repetir a chave não paga de novo; a mesma chave com outro valor recusa')]
    public function testIdempotencia(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);

        $this->baixar($ana, '3,00', 'pix', self::CHAVE_A, $this->png());
        $this->baixar($ana, '3,00', 'pix', self::CHAVE_A, $this->png());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->totalDePagamentos());
        $this->baixar($ana, '5,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(1, $this->totalDePagamentos());
    }

    #[TestDox('repetir a mesma chave guarda um só arquivo de comprovante, mesmo com outro arquivo no replay')]
    public function testReplayNaoGuardaOutroArquivo(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);
        $antes = $this->arquivosDeComprovante();

        $this->baixar($ana, '3,00', 'pix', self::CHAVE_A, $this->png());
        self::assertResponseIsSuccessful();
        self::assertSame($antes + 1, $this->arquivosDeComprovante(), 'a primeira baixa guarda o arquivo');

        $this->baixar($ana, '3,00', 'pix', self::CHAVE_A, $this->png());
        self::assertResponseIsSuccessful();
        self::assertSame($antes + 1, $this->arquivosDeComprovante(), 'o replay não guarda o arquivo de novo');
        self::assertSame(1, $this->totalDePagamentos());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM lancamento_de_recompensa WHERE comprovante_chave IS NOT NULL'));
    }

    #[TestDox('recusas: sem dados de recebimento, não liberado, bloqueado e forma ou valor inválidos; nada é gravado')]
    public function testRecusas(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();

        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cadastre o nome e o CPF ou CNPJ', $this->json()['erro']);

        $this->cadastrarRecebimento($ana);
        $this->baixar($ana, '1,00', 'dinheiro', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        $this->baixar($ana, '0', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        $this->baixar($ana, '-1,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);

        $this->enviarJson('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio', ['bloqueado' => true]);
        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Recompensa bloqueada para este usuário.', $this->json()['erro']);
        $this->enviarJson('POST', '/master/recompensas/usuarios/' . $ana->getId() . '/bloqueio', ['bloqueado' => false]);

        $this->enviarJson('POST', '/master/recompensas/regras', $this->regras(minEscritorios: 5));
        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Ainda não liberado: precisa de 5 escritório(s)', $this->json()['erro']);

        self::assertSame(0, $this->totalDePagamentos());
    }

    #[TestDox('comprovante que não é do tipo da extensão ou passa do limite é recusado antes de gravar')]
    public function testComprovanteInvalido(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);

        $falso = tempnam(sys_get_temp_dir(), 'comp');
        file_put_contents($falso, '<html>não sou pdf</html>');
        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A, new UploadedFile($falso, 'comprovante.pdf', 'application/pdf', null, true));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('PDF, PNG, JPG ou WEBP', $this->json()['erro']);
        self::assertSame(0, $this->totalDePagamentos());
    }

    #[TestDox('quem não é Master e CSRF errado recebem 403, nas três rotas novas')]
    public function testProtegidas(): void
    {
        $ana = $this->indicadoraLiberada();
        $this->entrarComoPrimarioDeEscritorio();

        $this->client->request('GET', '/master/recompensas/usuarios/' . $ana->getId() . '/recebimento');
        self::assertResponseStatusCodeSame(403);
        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/master/recompensas/lancamentos/1/comprovante');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalDePagamentos());
    }

    #[TestDox('CSRF errado: 403 nas rotas que gravam e nada é pago')]
    public function testCsrfErrado(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();

        $this->baixar($ana, '1,00', 'pix', self::CHAVE_A, null, 'errado');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('PUT', '/master/recompensas/usuarios/' . $ana->getId() . '/recebimento', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], content: '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->totalDePagamentos());
    }

    #[TestDox('dados de recebimento: documento inválido recusa; a lista mostra só o documento mascarado e o GET devolve o completo')]
    public function testDadosDeRecebimento(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $url = '/master/recompensas/usuarios/' . $ana->getId() . '/recebimento';

        $this->enviarJson('PUT', $url, ['nome' => 'Ana Indicadora', 'documento' => '111.111.111-11', 'chavePix' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe um CPF ou CNPJ válido.', $this->json()['erro']);

        $this->enviarJson('PUT', $url, ['nome' => 'Ana Indicadora', 'documento' => self::CPF, 'chavePix' => 'ana@pix.com']);
        self::assertResponseIsSuccessful();
        $linha = $this->linhaDe($this->json(), (int) $ana->getId());
        self::assertSame(['nome' => 'Ana Indicadora', 'documentoMascarado' => '***.***.***-25', 'chavePix' => 'ana@pix.com'], $linha['recebimento']);
        self::assertStringNotContainsString('52998224725', (string) $this->client->getResponse()->getContent());

        $this->enviarJson('PUT', $url, ['nome' => 'Ana Souza', 'documento' => self::CPF, 'chavePix' => '']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM dados_de_recebimento_de_recompensa WHERE indicador_id = ?', [$ana->getId()]));

        $this->client->request('GET', $url);
        self::assertSame(['nome' => 'Ana Souza', 'documento' => '52998224725', 'chavePix' => null], $this->json());

        $trilha = (string) $this->em->getConnection()->fetchOne(
            'SELECT string_agg(changes::text, \' \') FROM audit_log WHERE entity_class LIKE ?',
            ['%DadosDeRecebimentoDeRecompensa'],
        );
        self::assertStringContainsString('Ana', $trilha, 'a trilha registra que os dados mudaram');
        self::assertStringNotContainsString('52998224725', $trilha, 'o CPF nunca vai para a trilha de auditoria');
        self::assertStringNotContainsString('ana@pix.com', $trilha, 'a chave PIX nunca vai para a trilha de auditoria');
    }

    #[TestDox('CSV do extrato: ganha a coluna Forma e o rótulo Pagamento')]
    public function testCsv(): void
    {
        $this->entrarComoMaster();
        $ana = $this->indicadoraLiberada();
        $this->cadastrarRecebimento($ana);
        $this->baixar($ana, '2,50', 'credito_de_espaco', self::CHAVE_A);

        $this->client->request('GET', '/master/recompensas/extrato.csv');

        $csv = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('"Forma"', $csv);
        self::assertStringContainsString('"Pagamento";"2,50";"Pago pelo app do banco";"Crédito de espaço"', $csv);
    }

    /** Programa com 1 escritório para liberar, sem carência nem exigência de pasta: cada conta ativa vale R$ 10,00. */
    private function indicadoraLiberada(): User
    {
        $this->entrarComoMasterSeNecessario();
        $this->enviarJson('POST', '/master/recompensas/regras', $this->regras(minEscritorios: 1));
        $ana = $this->usuario(['ROLE_USER']);
        $nova = $this->usuario(['ROLE_USER'], 'conta_nova_' . uniqid() . '@indicado-teste.com.br');
        $nova->setCreatedAt(new \DateTimeImmutable('-60 days'));
        $this->em->persist(Indicacao::porEmail($ana, null, $nova->getEmail(), null, new \DateTimeImmutable('-70 days')));
        $this->em->flush();

        return $ana;
    }

    private bool $masterEntrou = false;

    private function entrarComoMasterSeNecessario(): void
    {
        if (!$this->masterEntrou) {
            $this->entrarComoMaster();
        }
    }

    /** @return array<string, mixed> */
    private function regras(int $minEscritorios): array
    {
        return ['ativo' => true, 'valor' => '10,00', 'minEscritorios' => $minEscritorios, 'carenciaDias' => 0, 'alertaQtd' => 50, 'exigePasta' => false];
    }

    private function cadastrarRecebimento(User $ana): void
    {
        $this->enviarJson('PUT', '/master/recompensas/usuarios/' . $ana->getId() . '/recebimento', ['nome' => 'Ana Indicadora', 'documento' => self::CPF, 'chavePix' => 'ana@pix.com']);
        self::assertResponseIsSuccessful();
    }

    private function baixar(User $ana, string $valor, string $forma, string $chave, ?UploadedFile $comprovante = null, string $token = self::TOKEN): void
    {
        $this->client->request(
            'POST',
            '/master/recompensas/usuarios/' . $ana->getId() . '/baixa',
            ['valor' => $valor, 'forma' => $forma, 'observacao' => 'Pago pelo app do banco', 'chave' => $chave],
            $comprovante === null ? [] : ['comprovante' => $comprovante],
            ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
        );
    }

    private function png(): UploadedFile
    {
        $caminho = tempnam(sys_get_temp_dir(), 'comp');
        file_put_contents($caminho, (string) base64_decode(self::PNG, true));

        return new UploadedFile($caminho, 'comprovante.png', 'image/png', null, true);
    }

    /** Quantos arquivos há na pasta dos comprovantes do ambiente de teste (a sentinela do bootstrap não conta). */
    private function arquivosDeComprovante(): int
    {
        $pasta = (string) static::getContainer()->getParameter('recompensa_comprovantes_dir');
        if (!is_dir($pasta)) {
            return 0;
        }
        $total = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($pasta, \FilesystemIterator::SKIP_DOTS)) as $arquivo) {
            if ($arquivo->isFile() && !str_starts_with($arquivo->getFilename(), '.')) {
                ++$total;
            }
        }

        return $total;
    }

    private function totalDePagamentos(): int
    {
        return (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM lancamento_de_recompensa WHERE tipo = 'pago'");
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function linhaDe(array $dados, int $userId): array
    {
        foreach ($dados['indicadores'] as $linha) {
            if ($linha['userId'] === $userId) {
                return $linha;
            }
        }
        self::fail('indicador ' . $userId . ' fora da lista');
    }

    private function entrarComoMaster(): User
    {
        $this->masterEntrou = true;
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);

        return $master;
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = (new Tenant())->setName('Escritório da baixa ' . uniqid());
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($papel));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, ?string $email = null): User
    {
        $u = new User();
        $u->setEmail($email ?? 'baixa_' . uniqid() . '@teste.com');
        $u->setFullName('Usuária da Baixa');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed>|null $corpo */
    private function enviarJson(string $metodo, string $url, ?array $corpo = null): void
    {
        $this->client->request($metodo, $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => self::TOKEN,
        ], content: $corpo === null ? '' : (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 12, \JSON_THROW_ON_ERROR);
    }
}
