<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\AutorizarConexaoInput;
use App\Conector\DTO\PedidoDeAutorizacao;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\AutorizarConexaoUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Termo\Entity\AceiteTermo;
use App\Termo\Enum\OrigemDoAceite;
use App\Termo\TermoVigente;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A corrida da primeira conexão (dois navegadores da mesma pessoa autorizando o mesmo app no mesmo escritório, sem
 * consentimento ativo): sem a trava, os dois leem "nenhum consentimento", os dois gravam um, e o segundo esbarra no
 * `uniq_conector_ia_consentimento_ativo` (500). Com a trava da linha da pessoa, o segundo espera e reaproveita.
 *
 * Duas conexões de verdade, fora do DAMA (o modelo do `NumeroSequencialDoRegistroTest`): o que a conexão do DAMA grava
 * nunca é confirmado e outra conexão não o enxerga, então o cenário é gravado e confirmado por fora e apagado no fim de
 * cada teste ({@see noSavepoint()}).
 */
#[CoversClass(AutorizarConexaoUseCase::class)]
final class AutorizacaoConcorrenteTest extends KernelTestCase
{
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';
    private const DESAFIO = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    private const ESPERA_MS = 1500;

    /** @var array<string, mixed> */
    private array $parametros = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('corrida na primeira conexão: a segunda autorização espera a primeira e reaproveita o consentimento, sem 500')]
    public function testSegundaAutorizacaoEsperaEReaproveita(): void
    {
        $cenario = $this->cenarioConfirmadoPorFora();
        $this->noSavepoint($cenario, function () use ($cenario): void {
            $this->em()->getConnection()->executeStatement("SET LOCAL lock_timeout = '10s'");
            $primeiro = proc_open(
                [\PHP_BINARY, __DIR__ . '/../Support/primeira_autorizacao_concorrente.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $canais,
                null,
                array_merge(getenv(), ['CONECTOR_TRAVA' => json_encode($this->parametros + $cenario + ['espera' => self::ESPERA_MS], \JSON_THROW_ON_ERROR)]),
            );
            self::assertIsResource($primeiro);
            try {
                $consentimentoDoPrimeiro = (int) fgets($canais[1]);
                self::assertGreaterThan(0, $consentimentoDoPrimeiro, 'o primeiro navegador gravou e segura a trava');

                $inicio = microtime(true);
                $codigo = $this->autorizar($cenario);
                $esperou = (microtime(true) - $inicio) * 1000;
            } finally {
                $saidaDoPrimeiro = (string) stream_get_contents($canais[2]);
                $fimDoPrimeiro = proc_close($primeiro);
            }

            self::assertSame(0, $fimDoPrimeiro, 'o primeiro navegador confirmou: ' . $saidaDoPrimeiro);
            self::assertGreaterThan(self::ESPERA_MS / 2, $esperou, 'a segunda autorização esperou o primeiro confirmar');
            $gravado = $this->em()->getRepository(CodigoDoConector::class)->findOneBy(['identificadorHash' => hash('sha256', $codigo)]);
            self::assertInstanceOf(CodigoDoConector::class, $gravado);
            self::assertSame($consentimentoDoPrimeiro, $gravado->getConcessao()->getConsentimento()->getId(), 'reaproveitou o consentimento do primeiro');
            self::assertSame(1, $this->em()->getRepository(ConsentimentoDoConector::class)->count(['user' => $cenario['userId'], 'revogadoEm' => null]));
            self::assertSame(
                [['id' => $gravado->getConcessao()->getId(), 'motivo' => null]],
                $this->em()->getConnection()->fetchAllAssociative('SELECT id, motivo FROM conector_ia_concessao WHERE consentimento_id = ?', [$consentimentoDoPrimeiro]),
                'decisão 23: a substituição, no consentimento que o primeiro gravou, não derruba a concessão nova do segundo',
            );
        });
    }

    #[TestDox('a espera é na trava da linha da pessoa, pedida pela autorização (SELECT ... FOR UPDATE em "user")')]
    public function testAutorizacaoPedeATravaDaPessoa(): void
    {
        $cenario = $this->cenarioConfirmadoPorFora();
        $fora = DriverManager::getConnection($this->parametros);
        try {
            $this->noSavepoint($cenario, function () use ($cenario, $fora): void {
                $fora->beginTransaction();
                // FOR NO KEY UPDATE barra o FOR UPDATE da autorização, mas não o FOR KEY SHARE que a chave estrangeira do
                // INSERT do consentimento pede: sem a trava no caso de uso, a autorização passaria direto.
                $fora->fetchOne('SELECT id FROM "user" WHERE id = ? FOR NO KEY UPDATE', [$cenario['userId']]);
                $this->em()->getConnection()->executeStatement("SET LOCAL lock_timeout = '400ms'");
                try {
                    $this->autorizar($cenario);
                    self::fail('a autorização passou sem esperar a linha travada da pessoa');
                } catch (DriverException $erro) {
                    self::assertSame('55P03', $erro->getSQLState(), 'só o lock_not_available prova que a autorização esperou a linha');
                    self::assertStringContainsString('while locking tuple', $erro->getMessage());
                    self::assertStringContainsString('in relation "user"', $erro->getMessage());
                }
            }, $fora);
        } finally {
            $fora->close();
        }
    }

    /**
     * Roda `$prova` num savepoint da conexão do teste e o desfaz no fim: o DAMA só desfaz a transação dele no fim da
     * execução inteira, e até lá as linhas que o caso de uso tocou ficariam travadas, sem deixar apagar o cenário. O
     * rollback do savepoint solta essas travas e desfaz também o `SET LOCAL lock_timeout`.
     *
     * @param array{tenantId: int, userId: int, clienteId: int} $cenario
     */
    private function noSavepoint(array $cenario, callable $prova, ?Connection $fora = null): void
    {
        $doTeste = $this->em()->getConnection();
        $doTeste->beginTransaction();
        try {
            $prova();
        } finally {
            $doTeste->rollBack();
            if ($fora?->isTransactionActive() === true) {
                $fora->rollBack();
            }
            $this->apagarPorFora($cenario);
        }
    }

    /** @param array{tenantId: int, userId: int, clienteId: int} $cenario */
    private function apagarPorFora(array $cenario): void
    {
        $conexao = DriverManager::getConnection($this->parametros);
        $conexao->executeStatement('DELETE FROM user_tenant WHERE tenant_id = ?', [$cenario['tenantId']]);
        $conexao->executeStatement('DELETE FROM tenant_role WHERE tenant_id = ?', [$cenario['tenantId']]);
        $conexao->executeStatement('DELETE FROM aceite_termo WHERE user_id = ?', [$cenario['userId']]);
        $conexao->executeStatement('DELETE FROM tenant WHERE id = ?', [$cenario['tenantId']]);
        $conexao->executeStatement('DELETE FROM "user" WHERE id = ?', [$cenario['userId']]);
        $conexao->executeStatement('DELETE FROM conector_ia_cliente WHERE id = ?', [$cenario['clienteId']]);
        $conexao->close();
    }

    /** @param array{tenantId: int, userId: int, clienteId: int, clientId: string} $cenario */
    private function autorizar(array $cenario): string
    {
        $pessoa = $this->em()->find(User::class, $cenario['userId']);
        self::assertInstanceOf(User::class, $pessoa);
        $input = new AutorizarConexaoInput();
        $input->tenantId = $cenario['tenantId'];
        $pedido = new PedidoDeAutorizacao($cenario['clientId'], self::REDIRECT, 'estado', self::DESAFIO, ServidorDeAutorizacao::RESOURCE, [ServidorDeAutorizacao::ESCOPO_LEITURA]);

        return static::getContainer()->get(AutorizarConexaoUseCase::class)->executar($input, $pedido, $pessoa);
    }

    /**
     * Escritório liberado e ligado, pessoa com vínculo e Termos aceitos, e o app com os metadados válidos, tudo
     * confirmado por uma conexão própria.
     *
     * @return array{tenantId: int, userId: int, clienteId: int, clientId: string}
     */
    private function cenarioConfirmadoPorFora(): array
    {
        $doTeste = $this->em();
        $this->parametros = array_intersect_key($doTeste->getConnection()->getParams(), array_flip(['driver', 'host', 'port', 'dbname', 'user', 'password', 'charset', 'serverVersion']));
        $conexao = DriverManager::getConnection($this->parametros);
        $em = new EntityManager($conexao, $doTeste->getConfiguration());

        $agora = new \DateTimeImmutable();
        $tenant = (new Tenant())->setName('Escritório corrida ' . uniqid())->setIsActive(true);
        $pessoa = (new User())->setEmail('corrida_' . uniqid() . '@teste.com')->setFullName('Pessoa da corrida')->setRoles(['ROLE_USER'])->setIsActive(true);
        $pessoa->setPassword('sem-uso');
        $perfil = (new TenantRole())->setTenant($tenant)->setName('Equipe ' . uniqid())->setIsSystem(false);
        $clientId = 'https://claude.ai/oauth/' . uniqid() . '/client.json';
        $cliente = new ClienteDoConector($clientId, TipoDeClienteDoConector::Cimd, 'App', [self::REDIRECT], $agora);
        $cliente->renovarMetadados('App', [self::REDIRECT], $agora, $agora->modify('+1 day'));
        $escritorio = new EscritorioDoConector($tenant);
        $escritorio->liberarPeloMaster();
        $escritorio->ligar($pessoa, $agora);
        foreach ([
            $tenant, $pessoa, $perfil, $cliente, $escritorio,
            new AceiteTermo($pessoa, TermoVigente::VERSAO, '127.0.0.1', null, null, OrigemDoAceite::Reaceite),
            (new UserTenant($pessoa, $tenant))->setTenantRole($perfil),
        ] as $entidade) {
            $em->persist($entidade);
        }
        $em->flush();
        $conexao->close();

        $ids = ['tenantId' => (int) $tenant->getId(), 'userId' => (int) $pessoa->getId(), 'clienteId' => (int) $cliente->getId()];

        return $ids + ['clientId' => $clientId];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
