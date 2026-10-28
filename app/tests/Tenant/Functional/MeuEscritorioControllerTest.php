<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Tenant\Armazenamento\ChavesDeTenant;
use App\Tenant\Controller\MeuEscritorioController;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use App\Tenant\Service\RegistroDeAcessoNegadoAoEscritorio;
use App\Tenant\UseCase\ConsultarMeuEscritorioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Tela Meu Escritório (spec docs/specs/meu-escritorio.md): salvar dados, logo e conferência da exclusão. D1: só o
 * primário e o Master; a tentativa do secundário é 403 e fica no audit_log sem o conteúdo. Isolamento entre escritórios
 * e CSRF no cabeçalho.
 */
#[CoversClass(MeuEscritorioController::class)]
#[CoversClass(RegistroDeAcessoNegadoAoEscritorio::class)]
final class MeuEscritorioControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
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

    protected function tearDown(): void
    {
        FalhaDeCommitArmavel::desarmar();
        parent::tearDown();
    }

    // ------------------------------------------------------------------------------------------------
    // Cenário
    // ------------------------------------------------------------------------------------------------

    private function conta(string $nome = 'Silva Advogados'): Tenant
    {
        $conta = (new Tenant())->setName($nome . ' ' . uniqid())->setIsActive(true);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    /** @param list<string> $papeis */
    private function pessoa(Tenant $conta, bool $primario, array $papeis = ['ROLE_USER'], ?string $nome = null): User
    {
        $user = (new User())
            ->setEmail('me_' . uniqid() . '@test.com')
            ->setFullName($nome ?? ($primario ? 'Ana Primária' : 'Bruno Equipe'))
            ->setRoles($papeis)
            ->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $papel = (new TenantRole())->setTenant($conta)->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid())->setIsSystem($primario);
        $this->em->persist($user);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $conta))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }

    private function master(): User
    {
        $user = (new User())->setEmail('mm_' . uniqid() . '@test.com')->setFullName('Mário Master')->setRoles(['ROLE_SUPER_ADMIN'])->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** O Master não tem escritório na sessão: a rota traz o escritório (com um na sessão e sem vínculo a barreira o derruba). */
    private function entrarComoMaster(User $master): void
    {
        $this->client->loginUser($master);
        $this->marcarTermosAceitos($this->client);
    }

    /** @return array<string, string> */
    private function dados(array $mudancas = []): array
    {
        return $mudancas + [
            'nome' => 'Silva & Associados',
            'doc' => '11.222.333/0001-81',
            'oab' => 'OAB/SP 123.456',
            'email' => 'contato@silva.adv.br',
            'telefone' => '(11) 98765-4321',
            'cep' => '01310-100',
            'endereco' => 'Av. Paulista, 1000, sala 12, Bela Vista',
            'cidadeUf' => 'São Paulo/sp',
        ];
    }

    private function postar(Tenant $conta, string $caminho, array $campos = [], array $arquivos = [], bool $comToken = true): void
    {
        $this->client->request(
            'POST',
            '/tenant/' . $conta->getId() . '/escritorio/' . $caminho,
            $campos,
            $arquivos,
            ($comToken ? ['HTTP_X_CSRF_TOKEN' => 'TOKEN_' . MeuEscritorioController::CSRF] : []) + ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function recarregar(Tenant $conta): Tenant
    {
        $this->em->clear();
        if ($this->em->getFilters()->isEnabled('tenant')) {
            $this->em->getFilters()->disable('tenant');
        }
        $recarregado = $this->em->find(Tenant::class, $conta->getId());
        self::assertNotNull($recarregado);

        return $recarregado;
    }

    /** @return list<AuditLog> */
    private function negados(Tenant $conta): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(['action' => RegistroDeAcessoNegadoAoEscritorio::ACAO, 'tenantId' => $conta->getId()]);
    }

    private function png(int $largura = 640, int $altura = 480): string
    {
        $imagem = imagecreatetruecolor($largura, $altura);
        imagefill($imagem, 0, 0, (int) imagecolorallocate($imagem, 10, 111, 160));
        ob_start();
        imagepng($imagem);

        return (string) ob_get_clean();
    }

    private function arquivo(string $conteudo, string $nome = 'logo.png', string $mime = 'image/png'): UploadedFile
    {
        $caminho = sys_get_temp_dir() . '/logo_' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, $mime, null, true);
    }

    // ------------------------------------------------------------------------------------------------
    // Salvar dados
    // ------------------------------------------------------------------------------------------------

    #[TestDox('o primário edita: grava os campos, carimba quem alterou e quando, e a resposta traz o escritório')]
    public function testPrimarioEditaECarimba(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'dados', $this->dados());

        self::assertResponseIsSuccessful();
        $corpo = $this->json();
        self::assertTrue($corpo['ok']);
        self::assertSame('Ana Primária', $corpo['escritorio']['alteradoPor']);
        self::assertSame('11.222.333/0001-81', $corpo['escritorio']['docFormatado']);
        self::assertSame('Av. Paulista, 1000, sala 12, Bela Vista', $corpo['escritorio']['endereco']);
        self::assertSame('São Paulo/SP', $corpo['escritorio']['cidadeUf']);
        $gravado = $this->recarregar($conta);
        self::assertSame('Silva & Associados', $gravado->getName());
        self::assertSame('11222333000181', $gravado->getCnpj());
        self::assertSame('OAB/SP 123.456', $gravado->getOab());
        self::assertSame('contato@silva.adv.br', $gravado->getEmailContato());
        self::assertSame('01310-100', $gravado->getCep());
        self::assertSame('SP', $gravado->getEstado());
        self::assertSame($primario->getId(), $gravado->getAlteradoPor()?->getId());
        self::assertNotNull($gravado->getAlteradoEm());
        self::assertSame([], $this->negados($conta));
    }

    #[TestDox('o Master edita mesmo sem vínculo com o escritório')]
    public function testMasterEdita(): void
    {
        $conta = $this->conta();
        $this->entrarComoMaster($this->master());

        $this->postar($conta, 'dados', $this->dados(['nome' => 'Nome do Master']));

        self::assertResponseIsSuccessful();
        self::assertSame('Nome do Master', $this->recarregar($conta)->getName());
    }

    #[TestDox('o secundário recebe 403 no POST, nada muda e a tentativa fica no audit_log sem o conteúdo enviado')]
    public function testSecundarioRecebe403EFicaNaTrilha(): void
    {
        $conta = $this->conta('Original');
        $nomeOriginal = $conta->getName();
        $secundario = $this->pessoa($conta, false);
        $this->logarComTenant($this->client, $secundario, $conta);

        $this->postar($conta, 'dados', $this->dados(['nome' => 'Invasão Secreta']));

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->json()['ok']);
        self::assertSame($nomeOriginal, $this->recarregar($conta)->getName());
        $negados = $this->negados($conta);
        self::assertCount(1, $negados);
        self::assertSame($secundario->getId(), $negados[0]->getActorUserId());
        self::assertSame('meu_escritorio_salvar', $negados[0]->getRoute());
        self::assertStringNotContainsString('Invasão Secreta', json_encode($negados[0]->getChanges(), \JSON_UNESCAPED_UNICODE) ?: '');
    }

    #[TestDox('sem o cabeçalho X-CSRF-Token o POST é 403 e nada muda')]
    public function testCsrfAusente(): void
    {
        $conta = $this->conta('Original');
        $nomeOriginal = $conta->getName();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'dados', $this->dados(['nome' => 'Sem Token']), [], comToken: false);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($nomeOriginal, $this->recarregar($conta)->getName());
    }

    #[TestDox('o primário do escritório A não altera o B (403, B intacto, tentativa no audit_log do B)')]
    public function testEscritorioANaoAlteraOB(): void
    {
        $a = $this->conta('A');
        $b = $this->conta('B');
        $nomeB = $b->getName();
        $primarioA = $this->pessoa($a, true);
        $this->logarComTenant($this->client, $primarioA, $a);

        $this->postar($b, 'dados', $this->dados(['nome' => 'Tomei o B']));

        self::assertResponseStatusCodeSame(403);
        self::assertSame($nomeB, $this->recarregar($b)->getName());
        self::assertCount(1, $this->negados($b));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function recusas(): iterable
    {
        yield 'nome vazio' => [['nome' => '   '], 'Informe o nome do escritório.'];
        yield 'documento com tamanho errado' => [['doc' => '123456'], 'CNPJ precisa ter 14 números (ou CPF, 11).'];
        yield 'CNPJ com dígito errado' => [['doc' => '11.222.333/0001-82'], 'CNPJ inválido: confira os dígitos.'];
        yield 'CPF com dígito errado' => [['doc' => '111.444.777-36'], 'CPF inválido: confira os dígitos.'];
        yield 'e-mail inválido' => [['email' => 'sem-arroba'], 'E-mail inválido.'];
        yield 'nome grande demais' => [['nome' => str_repeat('a', 256)], 'O nome passa de 255 caracteres.'];
        yield 'OAB grande demais' => [['oab' => str_repeat('1', 41)], 'O registro na OAB passa de 40 caracteres.'];
        yield 'telefone com letras' => [['telefone' => 'abc'], 'Telefone inválido: informe o DDD e o número.'];
        yield 'CEP curto' => [['cep' => '123'], 'CEP inválido: informe 8 números.'];
        yield 'UF com mais de duas letras' => [['cidadeUf' => 'Recife/PEE'], 'UF inválida: use duas letras (ex.: Belo Horizonte/MG).'];
    }

    /** @param array<string, string> $mudanca */
    #[DataProvider('recusas')]
    #[TestDox('recusa com a frase do desenho e não grava nada')]
    public function testRecusas(array $mudanca, string $frase): void
    {
        $conta = $this->conta('Original');
        $nomeOriginal = $conta->getName();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'dados', $this->dados($mudanca));

        self::assertResponseStatusCodeSame(422);
        self::assertSame($frase, $this->json()['erro']);
        $gravado = $this->recarregar($conta);
        self::assertSame($nomeOriginal, $gravado->getName());
        self::assertNull($gravado->getAlteradoEm());
    }

    #[TestDox('CPF válido de 11 dígitos é aceito; campo vazio vira null; HTML é descartado')]
    public function testCpfVazioEHtml(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'dados', $this->dados(['doc' => '111.444.777-35', 'oab' => '', 'email' => '', 'nome' => 'Banca <b>Forte</b><script>x()</script>']));

        self::assertResponseIsSuccessful();
        $gravado = $this->recarregar($conta);
        self::assertSame('11144477735', $gravado->getCnpj());
        self::assertNull($gravado->getOab());
        self::assertNull($gravado->getEmailContato());
        self::assertSame('Banca Fortex()', $gravado->getName());
    }

    #[TestDox('o endereço só zera número, complemento e bairro quando o texto mudou')]
    public function testEnderecoSoZeraQuandoMuda(): void
    {
        $conta = $this->conta();
        $conta->setLogradouro('Rua A')->setNumero('10')->setComplemento('apto 2')->setBairro('Centro');
        $this->em->flush();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'dados', $this->dados(['endereco' => 'Rua A, 10, apto 2, Centro']));
        $mantido = $this->recarregar($conta);
        self::assertSame(['Rua A', '10', 'apto 2', 'Centro'], [$mantido->getLogradouro(), $mantido->getNumero(), $mantido->getComplemento(), $mantido->getBairro()]);

        $this->postar($conta, 'dados', $this->dados(['endereco' => 'Rua B, 20']));
        $mudado = $this->recarregar($conta);
        self::assertSame(['Rua B, 20', null, null, null], [$mudado->getLogradouro(), $mudado->getNumero(), $mudado->getComplemento(), $mudado->getBairro()]);
    }

    // ------------------------------------------------------------------------------------------------
    // Código da conta
    // ------------------------------------------------------------------------------------------------

    #[TestDox('sem código, a primeira consulta gera um, a segunda devolve o mesmo, dois escritórios nunca repetem e a geração fica na trilha')]
    public function testCodigoDaContaSobDemanda(): void
    {
        $a = $this->conta('A');
        $b = $this->conta('B');
        $primarioA = $this->pessoa($a, true);
        $primarioB = $this->pessoa($b, true);
        self::assertNull($a->getCodigoConta());
        $consulta = static::getContainer()->get(ConsultarMeuEscritorioUseCase::class);

        $primeira = $consulta->executar($primarioA, $a)->codigo;
        $segunda = $consulta->executar($primarioA, $a)->codigo;
        $doB = $consulta->executar($primarioB, $b)->codigo;

        self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, $primeira);
        self::assertSame($primeira, $segunda);
        self::assertNotSame($primeira, $doB);
        self::assertSame($primeira, $this->recarregar($a)->getCodigoConta());
        $trilha = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => Tenant::class, 'entityId' => (string) $a->getId()]);
        self::assertStringContainsString($primeira, json_encode(array_map(static fn (AuditLog $l): ?array => $l->getChanges(), $trilha)) ?: '');
    }

    // ------------------------------------------------------------------------------------------------
    // Logo
    // ------------------------------------------------------------------------------------------------

    #[TestDox('envio válido: guarda por chave no escopo do escritório, reduz a 320 px, carimba e o escritório passa a ter logo')]
    public function testEnviarLogo(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'logo', [], ['logo' => $this->arquivo($this->png())]);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['escritorio']['temLogo']);
        $gravado = $this->recarregar($conta);
        $chave = ChavesDeTenant::logo($gravado);
        self::assertNotNull($chave);
        self::assertSame($conta->getId(), $chave->escopo->tenantIdOuNull());
        self::assertTrue($this->disco->memoria->existe($chave));
        $tamanho = getimagesizefromstring($this->disco->memoria->ler($chave));
        self::assertSame([320, 240], [$tamanho[0] ?? 0, $tamanho[1] ?? 0]);
        self::assertSame($primario->getId(), $gravado->getAlteradoPor()?->getId());
    }

    #[TestDox('arquivo que não é imagem (mesmo com nome .png) é recusado com 422 e nada é gravado')]
    public function testLogoTipoInvalido(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'logo', [], ['logo' => $this->arquivo('<?php echo 1;', 'logo.png', 'image/png')]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->recarregar($conta)->getLogoChave());
        self::assertCount(0, $this->disco->memoria->chavesGravadas);
    }

    #[TestDox('trocar a logo apaga o arquivo antigo depois do COMMIT e remover tira a logo e o arquivo')]
    public function testTrocarERemoverLogo(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);
        $this->postar($conta, 'logo', [], ['logo' => $this->arquivo($this->png())]);
        $antiga = ChavesDeTenant::logo($this->recarregar($conta));
        self::assertNotNull($antiga);

        $this->postar($conta, 'logo', [], ['logo' => $this->arquivo($this->png(100, 100))]);
        $nova = ChavesDeTenant::logo($this->recarregar($conta));
        self::assertNotNull($nova);
        self::assertFalse($this->disco->memoria->existe($antiga));
        self::assertTrue($this->disco->memoria->existe($nova));

        $this->postar($conta, 'logo/remover');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['escritorio']['temLogo']);
        self::assertNull($this->recarregar($conta)->getLogoChave());
        self::assertFalse($this->disco->memoria->existe($nova));
    }

    #[TestDox('COMMIT recusado na troca: o banco continua com a logo antiga e o arquivo antigo NÃO sai')]
    public function testArquivoAntigoSoSaiDepoisDoCommit(): void
    {
        $conta = $this->conta();
        $primario = $this->pessoa($conta, true);
        $conta->setLogoChave('antiga.png');
        $this->em->flush();
        $antiga = ChavesDeTenant::logo($conta);
        self::assertNotNull($antiga);
        $this->disco->memoria->semear($antiga);
        $container = static::getContainer();
        $container->set(TransacaoComArquivoNovo::class, new TransacaoComArquivoNovo(
            $container->get(EntityManagerInterface::class),
            new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada),
            $container->get(RemocaoAposTransacao::class),
            new NullLogger(),
        ));
        $this->logarComTenant($this->client, $primario, $conta);
        FalhaDeCommitArmavel::recusarProximoCommit();
        $this->client->catchExceptions(false);

        try {
            $this->postar($conta, 'logo', [], ['logo' => $this->arquivo($this->png(50, 50))]);
            self::fail('o COMMIT recusado deveria subir');
        } catch (\Throwable) {
            // esperado: a exceção original sobe
        }

        self::assertSame(1, FalhaDeCommitArmavel::$disparos);
        self::assertTrue($this->disco->memoria->existe($antiga), 'o arquivo antigo só sai depois do COMMIT');
        self::assertCount(1, $this->disco->memoria->chavesGravadas, 'só a nova foi gravada (a antiga foi semeada)');
        self::assertFalse($this->disco->memoria->existe($this->disco->memoria->ultimaGravada()), 'o arquivo novo sai: o COMMIT não aconteceu');
    }

    #[TestDox('o secundário não envia nem remove logo: 403, tentativa no audit_log e nada gravado')]
    public function testSecundarioNaoMexeNaLogo(): void
    {
        $conta = $this->conta();
        $secundario = $this->pessoa($conta, false);
        $this->logarComTenant($this->client, $secundario, $conta);

        $this->postar($conta, 'logo', [], ['logo' => $this->arquivo($this->png())]);
        self::assertResponseStatusCodeSame(403);
        $this->postar($conta, 'logo/remover');
        self::assertResponseStatusCodeSame(403);

        self::assertCount(0, $this->disco->memoria->chavesGravadas);
        $rotas = array_map(static fn (AuditLog $l): ?string => $l->getRoute(), $this->negados($conta));
        sort($rotas);
        self::assertSame(['meu_escritorio_logo_enviar', 'meu_escritorio_logo_remover'], $rotas);
    }

    #[TestDox('a logo é servida só ao próprio escritório: o do A lê a dele e recebe 403 ao pedir a do B')]
    public function testLeituraDaLogoIsolada(): void
    {
        $a = $this->conta('A');
        $b = $this->conta('B');
        $primarioA = $this->pessoa($a, true);
        $primarioB = $this->pessoa($b, true);
        $this->logarComTenant($this->client, $primarioB, $b);
        $this->postar($b, 'logo', [], ['logo' => $this->arquivo($this->png())]);
        $this->logarComTenant($this->client, $primarioA, $a);

        $this->client->request('GET', '/tenant/' . $b->getId() . '/escritorio/logo');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/tenant/' . $a->getId() . '/escritorio/logo');
        self::assertResponseStatusCodeSame(404, 'o A não tem logo');
    }

    #[TestDox('o navegador manda JSON: salvar e conferir a exclusão leem o corpo JSON, não só o formulário')]
    public function testCorpoJsonComoOJsDoNavegador(): void
    {
        $conta = $this->conta('Json');
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);
        $cabecalhos = ['HTTP_X_CSRF_TOKEN' => 'TOKEN_' . MeuEscritorioController::CSRF, 'CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];

        $this->client->request('POST', '/tenant/' . $conta->getId() . '/escritorio/dados', [], [], $cabecalhos, json_encode($this->dados(), JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertSame('Silva & Associados', $this->recarregar($conta)->getName());

        $this->client->request('POST', '/tenant/' . $conta->getId() . '/escritorio/exclusao/conferir', [], [], $cabecalhos, json_encode(['senha' => 'senha123', 'nome' => 'Silva & Associados'], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ok']);
    }

    #[TestDox('conferência: escritório da rota diferente do da sessão é recusado, porque o pedido de exclusão age no da sessão')]
    public function testConferirExclusaoSoDoEscritorioDaSessao(): void
    {
        $sessao = $this->conta('Da sessão');
        $outra = $this->conta('Outra');
        $primario = $this->pessoa($sessao, true);
        $papel = (new TenantRole())->setTenant($outra)->setName('Primário ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($primario, $outra))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $primario, $sessao);

        $this->postar($outra, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => $outra->getName()]);

        self::assertResponseStatusCodeSame(403);
        $this->postar($sessao, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => $sessao->getName()]);
        self::assertResponseIsSuccessful();
    }

    // ------------------------------------------------------------------------------------------------
    // Conferir a exclusão
    // ------------------------------------------------------------------------------------------------

    #[TestDox('conferência: senha certa e nome igual passam; senha errada e nome diferente voltam 422')]
    public function testConferirExclusao(): void
    {
        $conta = $this->conta('Exclusão');
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'exclusao/conferir', ['senha' => 'errada', 'nome' => $conta->getName()]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Senha incorreta.', $this->json()['erro']);

        $this->postar($conta, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => 'Outro nome']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('O nome digitado não é igual ao do escritório.', $this->json()['erro']);

        $this->postar($conta, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => '  ' . $conta->getName() . ' ']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['ok']);
        self::assertTrue($this->recarregar($conta)->isActive(), 'só confere: não cria pedido nem suspende');
    }

    #[TestDox('conferência: o secundário recebe 403 e fica na trilha')]
    public function testConferirExclusaoSoPrimario(): void
    {
        $conta = $this->conta('Exclusão');
        $secundario = $this->pessoa($conta, false);
        $this->logarComTenant($this->client, $secundario, $conta);
        $this->postar($conta, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => $conta->getName()]);
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->negados($conta));
    }

    #[TestDox('conferência: o Master sem vínculo com o escritório recebe 403 e fica na trilha')]
    public function testConferirExclusaoMasterSemVinculo(): void
    {
        $conta = $this->conta('Exclusão');
        $this->entrarComoMaster($this->master());

        $this->postar($conta, 'exclusao/conferir', ['senha' => 'x', 'nome' => $conta->getName()]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->negados($conta));
    }

    #[TestDox('conferência: conta criada por Master não tem exclusão (403)')]
    public function testContaDoMasterSemExclusao(): void
    {
        $master = $this->master();
        $conta = $this->conta('Do Master');
        $conta->setCriadoPor($master);
        $this->em->flush();
        $primario = $this->pessoa($conta, true);
        $this->logarComTenant($this->client, $primario, $conta);

        $this->postar($conta, 'exclusao/conferir', ['senha' => 'senha123', 'nome' => $conta->getName()]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Esta conta foi criada por um Master e não pode ser excluída.', $this->json()['erro']);
    }
}
