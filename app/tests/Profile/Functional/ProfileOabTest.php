<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(ProfileController::class)]
#[CoversClass(\App\Profile\Controller\InscricoesOabDoPerfilController::class)]
final class ProfileOabTest extends JusPrimeWebTestCase
{
    private ?\Symfony\Bundle\FrameworkBundle\KernelBrowser $cliente = null;

    #[TestDox('POST /perfil/oab sem autenticação redireciona para login')]
    public function testPostOabSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/oab');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /perfil/oab retorna 405 (só POST)')]
    public function testGetOabRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/oab');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('primeira inscrição nasce principal e Pendente, e vai para o espelho do User')]
    public function testPrimeiraInscricao(): void
    {
        [$client, $user] = $this->logado();
        $this->salvarOab($client, ['numero' => '12345', 'uf' => 'SP', 'tipo' => 'suplementar']);

        self::assertResponseRedirects('/perfil');
        $recarregado = $this->recarregar($user);
        self::assertSame('12345', $recarregado->getOabNumero());
        self::assertSame('SP', $recarregado->getOabUf());
        self::assertSame(StatusOab::NaoVerificada, $recarregado->getOabStatus());
        self::assertSame([['12345', 'SP', 'principal', 'nao_verificada']], $this->inscricoes($user));
    }

    #[TestDox('suplementar entra sem mexer no espelho; a tela lista as duas com "2 inscrições · 1 suplementar(es)"')]
    public function testSuplementar(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::Confirmada);
        $this->salvarOab($client, ['numero' => '700', 'uf' => 'GO', 'tipo' => 'suplementar']);

        self::assertSame([['49613', 'DF', 'principal', 'confirmada'], ['700', 'GO', 'suplementar', 'nao_verificada']], $this->inscricoes($user));
        self::assertSame('49613', $this->recarregar($user)->getOabNumero());

        $tela = $client->request('GET', '/perfil');
        self::assertSame('2 inscrições · 1 suplementar(es)', trim($tela->filter('.mp-card-oab .mp-card-topo .mp-card-sub')->text()));
        $linhas = $tela->filter('.mp-card-oab > button.mp-oab-linha');
        self::assertSame(['PRINCIPAL', 'SUPLEMENTAR'], $linhas->each(static fn ($l): string => trim($l->filter('.mp-oab-tipo')->text())));
        self::assertSame('Confirmada', trim($linhas->eq(0)->filter('.mp-oab-situacao')->text()));
        self::assertSame('Pendente', trim($linhas->eq(1)->filter('.mp-oab-situacao')->text()));
        self::assertSame('1', $linhas->eq(1)->attr('data-pode-remover'));
        self::assertSame('0', $linhas->eq(0)->attr('data-pode-remover'));
    }

    #[TestDox('escolher a suplementar como principal rebaixa a antiga e troca o espelho')]
    public function testTrocarPrincipal(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::Confirmada);
        $this->salvarOab($client, ['numero' => '700', 'uf' => 'GO', 'tipo' => 'suplementar']);
        $idGo = $this->idDa($user, 'GO');
        $this->salvarOab($client, ['id' => (string) $idGo, 'numero' => '700', 'uf' => 'GO', 'tipo' => 'principal']);

        self::assertSame([['700', 'GO', 'principal', 'nao_verificada'], ['49613', 'DF', 'suplementar', 'confirmada']], $this->inscricoes($user));
        self::assertSame('GO', $this->recarregar($user)->getOabUf());
    }

    #[TestDox('regras: UF repetida, número com letra e deixar sem principal são recusados com a mensagem do desenho')]
    public function testRegras(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::Confirmada);
        $this->salvarOab($client, ['numero' => '1', 'uf' => 'DF', 'tipo' => 'suplementar']);
        self::assertStringContainsString('Já existe uma inscrição na OAB/DF', $this->flashDeErro($client));

        $this->salvarOab($client, ['numero' => '12A', 'uf' => 'SP', 'tipo' => 'suplementar']);
        self::assertSame([['49613', 'DF', 'principal', 'confirmada']], $this->inscricoes($user), 'nada foi gravado');

        $this->salvarOab($client, ['id' => (string) $this->idDa($user, 'DF'), 'numero' => '49613', 'uf' => 'DF', 'tipo' => 'suplementar']);
        self::assertStringContainsString('É preciso ter uma inscrição principal', $this->flashDeErro($client));
    }

    #[TestDox('editar o número de uma inscrição Confirmada volta a Pendente, no espelho também')]
    public function testEditarDiferenteReseta(): void
    {
        [$client, $user] = $this->logado(oabNumero: '111', oabUf: 'SP', status: StatusOab::Confirmada);
        $client->request('GET', '/perfil'); // a principal nasce do espelho na primeira abertura
        $this->salvarOab($client, ['id' => (string) $this->idDa($user, 'SP'), 'numero' => '222', 'uf' => 'SP', 'tipo' => 'principal']);

        $recarregado = $this->recarregar($user);
        self::assertSame('222', $recarregado->getOabNumero());
        self::assertSame(StatusOab::NaoVerificada, $recarregado->getOabStatus());
    }

    #[TestDox('remover: a suplementar sai; a principal não sai; a de outra pessoa dá "não encontrada"')]
    public function testRemover(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::Confirmada);
        $this->salvarOab($client, ['numero' => '700', 'uf' => 'GO', 'tipo' => 'suplementar']);

        $client->request('POST', '/perfil/oab/' . $this->idDa($user, 'DF') . '/remover', ['_token' => $this->tokenRemover($client)]);
        self::assertStringContainsString('principal não pode ser removida', $this->flashDeErro($client));

        $client->request('POST', '/perfil/oab/' . $this->idDa($user, 'GO') . '/remover', ['_token' => $this->tokenRemover($client)]);
        self::assertSame([['49613', 'DF', 'principal', 'confirmada']], $this->inscricoes($user));

        [, $outro] = $this->logado(oabNumero: '555', oabUf: 'RJ', status: StatusOab::NaoVerificada);
        $client->request('GET', '/perfil');
        $this->logarComTenant($client, $user, $this->tenantDe($user));
        $client->request('POST', '/perfil/oab/' . $this->idDa($outro, 'RJ') . '/remover', ['_token' => $this->tokenRemover($client)]);
        self::assertStringContainsString('Inscrição não encontrada', $this->flashDeErro($client));
        self::assertCount(1, $this->inscricoes($outro));
    }

    #[TestDox('quem só tinha a OAB no User ganha a principal na primeira abertura do perfil')]
    public function testOabAntigaViraPrincipal(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::Confirmada);
        $this->limparInscricoes($user);

        $tela = $client->request('GET', '/perfil');

        self::assertSame('49613/DF', preg_replace('/\s+/', '', $tela->filter('.mp-card-oab .mp-oab-num > span')->first()->text()));
        self::assertSame([['49613', 'DF', 'principal', 'confirmada']], $this->inscricoes($user));
    }

    #[TestDox('revisão do Master por User: confirmar também confirma a inscrição principal')]
    public function testMasterConfirmaPrincipal(): void
    {
        [$client, $user] = $this->logado(oabNumero: '49613', oabUf: 'DF', status: StatusOab::NaoVerificada);
        $client->request('GET', '/perfil');

        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $master = new User();
        $master->setEmail('master_' . uniqid() . '@test.com');
        $master->setFullName('Master OAB');
        $master->setRoles(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $master->setIsActive(true);
        $master->setPassword('x');
        $em->persist($master);
        $tenant = $this->tenantDe($user);
        $em->persist(new UserTenant($master, $tenant));
        $em->flush();
        $this->logarComTenant($client, $master, $tenant);
        $fila  = $client->request('GET', '/admin/platform/oab?status=todas&q=' . urlencode((string) $user->getEmail()));
        $token = (string) $fila->filter('form[action*="/admin/platform/oab/' . $user->getId() . '/confirmar"] input[name=_token]')->attr('value');
        $client->request('POST', '/admin/platform/oab/' . $user->getId() . '/confirmar', ['_token' => $token]);

        self::assertResponseRedirects();
        self::assertSame([['49613', 'DF', 'principal', 'confirmada']], $this->inscricoes($user));
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: User} */
    private function logado(?string $oabNumero = null, ?string $oabUf = null, ?StatusOab $status = null): array
    {
        $this->cliente ??= static::createClient();
        $client = $this->cliente;
        [$user, $tenant] = $this->criarUsuarioComTenant($oabNumero, $oabUf, $status);
        $this->logarComTenant($client, $user, $tenant);

        return [$client, $user];
    }

    /** @param array<string, string> $campos */
    private function salvarOab(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, array $campos): void
    {
        $token = (string) $client->request('GET', '/perfil')->filter('#formOab input[name=_token]')->attr('value');
        $client->request('POST', '/perfil/oab', $campos + ['id' => '', '_token' => $token]);
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> principal primeiro, depois por UF */
    private function inscricoes(User $user): array
    {
        $linhas = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllNumeric(
            "SELECT numero, uf, tipo, status FROM inscricao_oab WHERE user_id = ? ORDER BY CASE WHEN tipo = 'principal' THEN 0 ELSE 1 END, uf",
            [$user->getId()],
        );

        return array_map(static fn (array $l): array => array_map('strval', $l), $linhas);
    }

    private function idDa(User $user, string $uf): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT id FROM inscricao_oab WHERE user_id = ? AND uf = ?', [$user->getId(), $uf]);
    }

    private function limparInscricoes(User $user): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('DELETE FROM inscricao_oab WHERE user_id = ?', [$user->getId()]);
    }

    private function tenantDe(User $user): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $em->getRepository(UserTenant::class)->findOneBy(['user' => $user->getId()])->getTenant();
    }

    private function flashDeErro(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        return implode(' ', $client->getRequest()->getSession()->getFlashBag()->peek('error'));
    }

    private function tokenRemover(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/perfil')->filter('#formOabRemover input[name=_token]')->attr('value');
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioComTenant(
        ?string $oabNumero = null,
        ?string $oabUf = null,
        ?StatusOab $status = null,
    ): array {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('adv_' . uniqid() . '@test.com');
        $user->setFullName('João da Silva');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setOabNumero($oabNumero);
        $user->setOabUf($oabUf);
        $user->setOabStatus($status);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function recarregar(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $user->getId();
        $em->clear();

        return $em->getRepository(User::class)->find($id);
    }
}
