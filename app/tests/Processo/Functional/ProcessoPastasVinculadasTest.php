<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Processo\Controller\ProcessoController;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Aba "Pastas" do processo_show: as pastas em que o processo está vinculado.
 *
 * O que cada teste realmente prova:
 * - o CASAMENTO (a pasta irmã, do MESMO escritório e SEM o vínculo, não pode aparecer) — este é
 *   o único que prova o predicado `pp.processo = :processo`;
 * - o ISOLAMENTO entre escritórios — este prova que EXISTE barreira, não QUAL: o TenantFilter
 *   global já cobre toda entidade TenantAware, então ele ficaria verde mesmo sem o
 *   `p.tenant = :tenant` explícito do repositório (defesa em profundidade, e o filtro explícito
 *   é exigência da camada);
 * - a lápide, o selo Principal e o vazio, que são regra de exibição.
 *
 * Aparência (posição da aba, borda, cor do selo) segue invisível para o PHPUnit — isso é smoke.
 */
#[CoversClass(ProcessoController::class)]
final class ProcessoPastasVinculadasTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('a pasta vinculada aparece na aba Pastas, com link para a pasta e contagem na aba')]
    public function testPastaVinculadaAparece(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenant   = $this->criarTenant();
        $usuario  = $this->criarGestor($tenant);
        $processo = $this->criarProcesso($tenant);
        $pasta    = $this->criarPasta($tenant, $usuario, 'Cliente da Pasta', 'Execução de Título');
        $pasta->vincularProcesso($processo, $usuario);
        $em->flush();
        $em->clear();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#pastas');
        self::assertCount(1, $painel, 'a aba Pastas tem painel próprio');

        $link = $painel->filter('a[href="/pasta/' . $pasta->getId() . '"]');
        self::assertGreaterThan(0, $link->count(), 'a pasta vinculada precisa levar à própria pasta');
        self::assertStringContainsString($pasta->getNup(), $painel->text(), 'o NUP identifica a pasta');
        // O domínio grava em MAIÚSCULAS (Pasta::setNomeCliente/setNomeAcao) e a tela reflete o
        // dado como ele está no banco — a asserção segue o dado, não a digitação do teste.
        self::assertStringContainsString('CLIENTE DA PASTA', $painel->text());
        self::assertStringContainsString('EXECUÇÃO DE TÍTULO', $painel->text());

        self::assertSame(
            '1',
            trim($crawler->filter('#pastas-tab .badge')->text()),
            'a contagem da aba diz quantas pastas há sem precisar clicar'
        );
    }

    #[TestDox('pasta do MESMO escritório sem o vínculo não aparece (prova o casamento, não o tenant)')]
    public function testPastaIrmaSemVinculoNaoAparece(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenant   = $this->criarTenant();
        $usuario  = $this->criarGestor($tenant);
        $processo = $this->criarProcesso($tenant);

        $vinculada = $this->criarPasta($tenant, $usuario);
        $vinculada->vincularProcesso($processo, $usuario);

        // A irmã precisa ter vínculo — só que com OUTRO processo. Pasta sem vínculo nenhum não
        // prova nada aqui: o INNER JOIN em `p.pastaProcessos` já a descartaria sozinho, e o teste
        // ficaria verde mesmo sem o `pp.processo = :processo` (medido por reintrodução).
        $outroProcesso = $this->criarProcesso($tenant);
        $irma          = $this->criarPasta($tenant, $usuario);
        $irma->vincularProcesso($outroProcesso, $usuario);

        $em->flush();
        $em->clear();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#pastas');
        self::assertStringContainsString($vinculada->getNup(), $painel->text());
        self::assertStringNotContainsString(
            $irma->getNup(),
            $painel->text(),
            'pasta sem vínculo com este processo não pode entrar na lista'
        );
    }

    #[TestDox('pasta de OUTRO escritório vinculada ao mesmo processo não aparece')]
    public function testPastaDeOutroEscritorioNaoVaza(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenantA  = $this->criarTenant();
        $tenantB  = $this->criarTenant();
        $usuarioA = $this->criarGestor($tenantA);
        $usuarioB = $this->criarGestor($tenantB);

        $processo = $this->criarProcesso($tenantA);

        $pastaA = $this->criarPasta($tenantA, $usuarioA);
        $pastaA->vincularProcesso($processo, $usuarioA);

        $pastaB = $this->criarPasta($tenantB, $usuarioB);
        $pastaB->vincularProcesso($processo, $usuarioB);

        $em->flush();
        $em->clear();

        $this->logarComTenant($client, $usuarioA, $tenantA);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $painel = $crawler->filter('#pastas');
        self::assertStringContainsString($pastaA->getNup(), $painel->text());
        self::assertStringNotContainsString(
            $pastaB->getNup(),
            $painel->text(),
            'pasta de outro escritório não pode aparecer na tela do processo'
        );
        self::assertSame('1', trim($crawler->filter('#pastas-tab .badge')->text()));
    }

    #[TestDox('o selo Principal marca só a pasta em que ESTE processo é o principal')]
    public function testSeloPrincipal(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenant  = $this->criarTenant();
        $usuario = $this->criarGestor($tenant);

        $processo = $this->criarProcesso($tenant);
        $outro    = $this->criarProcesso($tenant);

        // Na pasta 1 este processo entrou primeiro → é o principal.
        $comoPrincipal = $this->criarPasta($tenant, $usuario);
        $comoPrincipal->vincularProcesso($processo, $usuario);

        // Na pasta 2 ele entrou depois de outro → é secundário.
        $comoSecundario = $this->criarPasta($tenant, $usuario);
        $comoSecundario->vincularProcesso($outro, $usuario);
        $comoSecundario->vincularProcesso($processo, $usuario);

        $em->flush();
        $em->clear();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $cartoes = $crawler->filter('#pastas .pasta-vinculada');
        self::assertCount(2, $cartoes, 'as duas pastas vinculadas aparecem');

        // Principal primeiro: a ordenação é `pp.principal DESC`.
        self::assertStringContainsString($comoPrincipal->getNup(), $cartoes->eq(0)->text());
        self::assertCount(1, $cartoes->eq(0)->filter('.badge.text-bg-primary'));

        self::assertStringContainsString($comoSecundario->getNup(), $cartoes->eq(1)->text());
        self::assertCount(
            0,
            $cartoes->eq(1)->filter('.badge.text-bg-primary'),
            'a pasta em que o processo NÃO é o principal não pode ganhar o selo'
        );
    }

    #[TestDox('pasta excluída continua na lista, marcada como lápide')]
    public function testPastaExcluidaContinuaVisivelComoLapide(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenant   = $this->criarTenant();
        $usuario  = $this->criarGestor($tenant);
        $processo = $this->criarProcesso($tenant);

        $pasta = $this->criarPasta($tenant, $usuario);
        $pasta->vincularProcesso($processo, $usuario);
        $pasta->marcarExcluida($usuario, new \DateTimeImmutable());

        $em->flush();
        $em->clear();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        $cartao = $crawler->filter('#pastas .pasta-vinculada');
        self::assertCount(1, $cartao, 'sumir com a pasta excluída esconderia um vínculo que existe');
        self::assertStringContainsString($pasta->getNup(), $cartao->text());
        self::assertCount(1, $cartao->filter('.pasta-badge-excluida'), 'a lápide é declarada na tela');
        self::assertStringContainsString('pasta-excluida', (string) $cartao->attr('class'));
    }

    #[TestDox('processo sem pasta: mensagem de vazio e aba sem contagem')]
    public function testProcessoSemPasta(): void
    {
        $client = static::createClient();
        $em     = $this->em();

        $tenant   = $this->criarTenant();
        $usuario  = $this->criarGestor($tenant);
        $processo = $this->criarProcesso($tenant);
        $em->clear();

        $this->logarComTenant($client, $usuario, $tenant);
        $crawler = $client->request('GET', '/processos/' . $processo->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#pastas .pasta-vinculada'));
        self::assertStringContainsString(
            'não está vinculado a nenhuma pasta',
            $crawler->filter('#pastas')->text()
        );
        self::assertCount(
            0,
            $crawler->filter('#pastas-tab .badge'),
            'contagem zero não vira selo — a aba fica limpa'
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $em     = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Tenant PASTAS ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('pastas_' . uniqid() . '@test.com');
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarProcesso(Tenant $tenant): Processo
    {
        $em = $this->em();
        $n  = ++$this->seq;

        $processo = new Processo();
        $processo->setNumeroProcesso('PAST' . $n . 'Z' . substr(uniqid(), -6));
        $processo->setSiglaTribunal('TRT2');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Reclamação');
        $processo->setAssuntoProcessual('Rescisão');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }

    private function criarPasta(
        Tenant $tenant,
        User $criadoPor,
        ?string $nomeCliente = null,
        ?string $nomeAcao = null,
    ): Pasta {
        $em    = $this->em();
        $pasta = new Pasta();
        $pasta->setNup('NUP-PAST-' . strtoupper(substr(uniqid(), -8)) . '-' . (++$this->seq));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criadoPor);

        if ($nomeCliente !== null) {
            $pasta->setNomeCliente($nomeCliente);
        }

        if ($nomeAcao !== null) {
            $pasta->setNomeAcao($nomeAcao);
        }

        $em->persist($pasta);

        return $pasta;
    }
}
