<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Auth\Entity\InscricaoOab;
use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\UseCase\ConferirInscricaoOabPeloCnaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Controller\RevisaoOabDoMasterController;
use App\Master\Entity\ConfiguracaoGlobal;
use App\Master\UseCase\AlterarValidacaoAutomaticaOabUseCase;
use App\Master\UseCase\ConsultarRevisaoOabUseCase;
use App\Master\UseCase\ValidarFormatoDasInscricoesOabUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A Revisão de OAB do Painel do Master (desenho 05 - Perfis 1.2 de 31/10): lista de todos os escritórios com
 * contagens por aba, confirmar e conferir pelo nome no CNA (com a proteção "a inscrição mudou"), validação automática
 * só "Ao cadastrar" e "Validar agora" conferindo só o formato. Só o Master; CSRF em todo POST.
 */
#[CoversClass(RevisaoOabDoMasterController::class)]
#[CoversClass(ConsultarRevisaoOabUseCase::class)]
#[CoversClass(AlterarValidacaoAutomaticaOabUseCase::class)]
#[CoversClass(ValidarFormatoDasInscricoesOabUseCase::class)]
#[CoversClass(ConferirInscricaoOabPeloCnaUseCase::class)]
final class RevisaoOabDoMasterControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarTokensFalsos();
        $this->escritorio = (new Tenant())->setName('Escritório Revisão ' . uniqid());
        $this->em->persist($this->escritorio);
        $this->em->flush();
    }

    #[TestDox('o Master vê a inscrição de outro escritório com nome, escritório, OAB normalizada, principal e status; as contagens fecham')]
    public function testListar(): void
    {
        $i = $this->inscricao('Ana Revisada', '0012345', 'DF', TipoInscricaoOab::Principal);
        $this->entrarComoMaster();

        $dados = $this->listar();

        $linha = array_column($dados['linhas'], null, 'id')[$i->getId()];
        self::assertSame(['Ana Revisada', $this->escritorio->getName(), '12345/DF', true, null, 'nao_verificada', null], [$linha['nome'], $linha['escritorio'], $linha['inscricao'], $linha['principal'], $linha['nomeCna'], $linha['status'], $linha['verificadaEm']]);
        $c = $dados['contagens'];
        self::assertSame($c['todas'], \count($dados['linhas']));
        self::assertSame($c['todas'], $c['conf'] + $c['div'] + $c['nao']);
        self::assertSame($c['pend'], $c['div'] + $c['nao']);
        self::assertSame(['ligada' => false, 'frequencia' => 'cadastro', 'ultimaRodada' => null], $dados['auto']);
    }

    #[TestDox('quem não é Master não lista nem age')]
    public function testSoMaster(): void
    {
        $outro = $this->usuario(['ROLE_ADMIN'], 'Admin Comum');
        $this->em->persist(new UserTenant($outro, $this->escritorio));
        $this->em->flush();
        $this->logarComTenant($this->client, $outro, $this->escritorio);

        $this->client->request('GET', '/master/revisao-oab');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/revisao-oab/validar-agora', []);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('confirmar exige token e o número/UF que a tela mostrou; confirmado, espelha a principal')]
    public function testConfirmar(): void
    {
        $i = $this->inscricao('Bruno Confirmado', '700', 'GO', TipoInscricaoOab::Principal);
        $this->entrarComoMaster();
        $url = '/master/revisao-oab/' . $i->getId() . '/confirmar';

        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], '{}');
        self::assertResponseStatusCodeSame(403);
        $this->postar($url, ['numero' => '701', 'uf' => 'GO']);
        self::assertResponseStatusCodeSame(409);
        $this->postar('/master/revisao-oab/999999999/confirmar', ['numero' => '700', 'uf' => 'GO']);
        self::assertResponseStatusCodeSame(404);

        $this->postar($url, ['numero' => '700', 'uf' => 'GO']);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        $recarregada = $this->em->find(InscricaoOab::class, $i->getId());
        self::assertSame(StatusOab::Confirmada, $recarregada?->getStatus());
        self::assertSame(StatusOab::Confirmada, $recarregada->getUsuario()->getOabStatus());
    }

    #[TestDox('conferir pelo nome no CNA: nome diferente vai para Divergente e o nome lido aparece na lista')]
    public function testConferir(): void
    {
        $i = $this->inscricao('Carla Conferida', '800', 'SP', TipoInscricaoOab::Suplementar);
        $this->entrarComoMaster();

        $this->postar('/master/revisao-oab/' . $i->getId() . '/conferir', ['numero' => '800', 'uf' => 'SP', 'nomeCna' => 'Outra Pessoa']);
        self::assertResponseIsSuccessful();
        self::assertSame('divergente', $this->resposta()['status']);

        $linha = array_column($this->listar()['linhas'], null, 'id')[$i->getId()];
        self::assertSame(['divergente', 'Outra Pessoa'], [$linha['status'], $linha['nomeCna']]);
        self::assertNotNull($linha['verificadaEm']);
    }

    #[TestDox('validação automática: "Ao cadastrar" liga e grava; Diária é recusada com a dica, sem travessão')]
    public function testValidacaoAutomatica(): void
    {
        $this->entrarComoMaster();

        $this->postar('/master/revisao-oab/validacao-automatica', ['ligada' => true, 'frequencia' => 'diaria']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Disponível quando a consulta ao CNA estiver ligada.', $this->resposta()['erro']);

        $this->postar('/master/revisao-oab/validacao-automatica', ['ligada' => true, 'frequencia' => 'cadastro']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->resposta()['auto']['ligada']);
        self::assertTrue($this->listar()['auto']['ligada']);
        $linha = $this->em->find(ConfiguracaoGlobal::class, ConfiguracaoGlobal::ID_UNICO);
        self::assertArrayNotHasKey('revisaoOab.ligada', $linha?->getValores() ?? [], 'não vaza para a tela de configurações');
    }

    #[TestDox('validar agora: formato inválido vai para Divergente, formato válido não é confirmado; grava a última rodada')]
    public function testValidarAgora(): void
    {
        $invalida = $this->inscricao('Davi Formato', '00012', 'DF', TipoInscricaoOab::Suplementar);
        $valida = $this->inscricao('Eva Formato', '4567', 'RJ', TipoInscricaoOab::Suplementar);
        $this->entrarComoMaster();

        $this->postar('/master/revisao-oab/validar-agora', []);
        self::assertResponseIsSuccessful();
        $r = $this->resposta();
        self::assertGreaterThanOrEqual(1, $r['invalidas']);
        self::assertGreaterThanOrEqual(1, $r['conferidas']);
        self::assertStringContainsString('CNA', $r['mensagem']);
        self::assertStringNotContainsString('—', $r['mensagem']);

        $linhas = array_column($this->listar()['linhas'], null, 'id');
        self::assertSame('divergente', $linhas[$invalida->getId()]['status']);
        self::assertSame('nao_verificada', $linhas[$valida->getId()]['status'], 'nunca confirma sem o CNA');
        self::assertNotNull($this->listar()['auto']['ultimaRodada']);
        $semEscritorio = (int) static::getContainer()->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%ConfiguracaoGlobal' AND tenant_id IS NULL",
        );
        self::assertGreaterThanOrEqual(1, $semEscritorio, 'a configuração da plataforma é auditada sem escritório');
    }

    private function inscricao(string $nome, string $numero, string $uf, TipoInscricaoOab $tipo): InscricaoOab
    {
        $user = $this->usuario(['ROLE_USER'], $nome);
        $this->em->persist(new UserTenant($user, $this->escritorio));
        $inscricao = new InscricaoOab($user, $numero, $uf, $tipo);
        $this->em->persist($inscricao);
        $this->em->flush();

        return $inscricao;
    }

    private function entrarComoMaster(): void
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'Master Revisão');
        $this->em->persist(new UserTenant($master, $this->escritorio));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $this->escritorio);
    }

    /** @return array<string, mixed> */
    private function listar(): array
    {
        $this->client->request('GET', '/master/revisao-oab');
        self::assertResponseIsSuccessful();

        return $this->resposta();
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_' . RevisaoOabDoMasterController::TOKEN], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, string $nome): User
    {
        $user = (new User())->setEmail('revoab_' . uniqid() . '@test.com')->setFullName($nome)->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function instalarTokensFalsos(): void
    {
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
}
