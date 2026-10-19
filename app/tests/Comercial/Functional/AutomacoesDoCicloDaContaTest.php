<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Event\CadastroConfirmado;
use App\Auth\Event\ConfirmacaoDeCadastroEnviada;
use App\Auth\Event\ConviteDeEscritorioAceito;
use App\Auth\Event\ConvitePlataformaAceito;
use App\Comercial\EventListener\CicloDaContaListener;
use App\Comercial\Service\VarreduraDeAutomacoes;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * As automações do ciclo da conta contra o banco (spec comercial-e-emails.md §3.4 e §3.5): uma boas-vindas por pessoa
 * e escritório, nada com a automação desligada, nada para o passado (corte `ligada_em`), lembrete uma vez com link
 * novo, dica uma vez, e o registro do `cad`. O Carteiro é o real; o transporte `comercial` é em memória.
 */
#[CoversClass(CicloDaContaListener::class)]
#[CoversClass(VarreduraDeAutomacoes::class)]
final class AutomacoesDoCicloDaContaTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conexao;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = static::getContainer()->get(Connection::class);
    }

    #[TestDox('Cadastro confirmado: boas-vindas (val) enfileirada uma vez, mesmo com o evento repetido')]
    public function testBoasVindasUmaVez(): void
    {
        [$user, $tenant] = $this->contaNova();
        $this->despachar(new CadastroConfirmado((int) $user->getId(), (int) $tenant->getId()));
        $this->despachar(new CadastroConfirmado((int) $user->getId(), (int) $tenant->getId()));

        self::assertSame(1, $this->envios('automacao:val', (string) $user->getEmail()));
        self::assertNotNull($this->conexao->fetchOne('SELECT confirmado_em FROM comercial_ciclo_conta WHERE user_id = ?', [$user->getId()]));
    }

    #[TestDox('Com a automação desligada, nada sai e a vez não é consumida')]
    public function testDesligadaNaoEnvia(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = false WHERE chave = 'val'");
        [$user, $tenant] = $this->contaNova();
        $this->despachar(new CadastroConfirmado((int) $user->getId(), (int) $tenant->getId()));

        self::assertSame(0, $this->envios('automacao:val', (string) $user->getEmail()));
        self::assertNull($this->conexao->fetchOne('SELECT boas_vindas_em FROM comercial_ciclo_conta WHERE user_id = ?', [$user->getId()]));
    }

    #[TestDox('Convite aceito (escritório e plataforma): boas-vindas (conv); a reativação no mesmo escritório não repete')]
    public function testConvites(): void
    {
        [$user, $tenant] = $this->contaNova();
        $this->despachar(new ConviteDeEscritorioAceito((int) $user->getId(), (int) $tenant->getId(), false));
        $this->despachar(new ConviteDeEscritorioAceito((int) $user->getId(), (int) $tenant->getId(), false));
        $this->despachar(new ConvitePlataformaAceito((int) $user->getId()));

        self::assertSame(2, $this->envios('automacao:conv', (string) $user->getEmail()), 'uma no escritório, uma na plataforma');
    }

    #[TestDox('Ouvinte nunca derruba o fluxo: usuário inexistente não lança nada')]
    public function testOuvinteProtegido(): void
    {
        $this->despachar(new CadastroConfirmado(999999999, 999999999));
        $this->addToAssertionCount(1);
    }

    #[TestDox('A confirmação do cadastro (cad) fica registrada como enviada, sem conteúdo')]
    public function testRegistroDoCad(): void
    {
        $this->despachar(new ConfirmacaoDeCadastroEnviada('nova@exemplo.com', '[BlueJus] Confirme seu cadastro no BlueJus'));

        self::assertSame('enviado', $this->conexao->fetchOne("SELECT situacao FROM comercial_envio WHERE origem = 'automacao:cad' AND destinatario = 'nova@exemplo.com'"));
    }

    #[TestDox('Lembrete (lemb): pendente de 25 h recebe link novo uma vez; o anterior ao corte não recebe')]
    public function testLembreteUmaVezSoDepoisDoCorte(): void
    {
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada_em = NOW() - INTERVAL '10 days' WHERE chave = 'lemb'");
        $recente = $this->pendente('-25 hours');
        $antigo = $this->pendente('-20 days');
        $tokenAntes = $recente->getToken();

        $varredura = static::getContainer()->get(VarreduraDeAutomacoes::class);
        $varredura->lembrarCadastrosNaoConfirmados(new \DateTimeImmutable());
        $varredura->lembrarCadastrosNaoConfirmados(new \DateTimeImmutable());

        self::assertSame(1, $this->envios('automacao:lemb', $recente->getEmail()));
        self::assertSame(0, $this->envios('automacao:lemb', $antigo->getEmail()), 'anterior ao corte: nada para o passado');
        $this->em->clear();
        $relido = $this->em->find(CadastroPendente::class, $recente->getId());
        self::assertNotSame($tokenAntes, $relido?->getToken(), 'link novo');
        self::assertNotNull($relido?->getLembreteEm());
        self::assertGreaterThan(new \DateTimeImmutable('+23 hours'), $relido?->getExpiresAt());
    }

    #[TestDox('Dica: desligada não sai; ligada, só para conta confirmada depois do corte e há 7 dias ou mais, uma vez')]
    public function testDica(): void
    {
        [$user, $tenant] = $this->contaNova();
        $this->conexao->insert('comercial_ciclo_conta', ['user_id' => $user->getId(), 'tenant_id' => $tenant->getId(), 'confirmado_em' => (new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:s')]);
        $varredura = static::getContainer()->get(VarreduraDeAutomacoes::class);

        $varredura->mandarDicas(new \DateTimeImmutable());
        self::assertSame(0, $this->envios('automacao:dica', (string) $user->getEmail()), 'nasce desligada');

        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = true, ligada_em = NOW() - INTERVAL '30 days' WHERE chave = 'dica'");
        $varredura->mandarDicas(new \DateTimeImmutable());
        $varredura->mandarDicas(new \DateTimeImmutable());
        self::assertSame(1, $this->envios('automacao:dica', (string) $user->getEmail()));

        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada_em = NOW() WHERE chave = 'dica'");
        [$outro, $outroTenant] = $this->contaNova();
        $this->conexao->insert('comercial_ciclo_conta', ['user_id' => $outro->getId(), 'tenant_id' => $outroTenant->getId(), 'confirmado_em' => (new \DateTimeImmutable('-8 days'))->format('Y-m-d H:i:s')]);
        $varredura->mandarDicas(new \DateTimeImmutable());
        self::assertSame(0, $this->envios('automacao:dica', (string) $outro->getEmail()), 'ligada depois: não alcança conta antiga');
    }

    private function despachar(object $evento): void
    {
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($evento);
    }

    private function envios(string $origem, string $email): int
    {
        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE origem = ? AND destinatario = ?', [$origem, mb_strtolower($email)]);
    }

    /** @return array{0: User, 1: Tenant} */
    private function contaNova(): array
    {
        $tenant = (new Tenant())->setName('Escritório Comercial ' . uniqid());
        $user = new User();
        $user->setEmail('ciclo_' . uniqid() . '@test.com');
        $user->setFullName('Ana Ciclo');
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($tenant);
        $this->em->persist($user);
        $this->em->flush();

        return [$user, $tenant];
    }

    private function pendente(string $criadoHa): CadastroPendente
    {
        $p = new CadastroPendente('pend_' . uniqid() . '@test.com', bin2hex(random_bytes(32)), 'Bia Pendente', 'Banca Bia', '12345', 'SP', 'HASH', '1.2.3.4', new \DateTimeImmutable($criadoHa . ' +24 hours'), 'UA', new \DateTimeImmutable($criadoHa));
        $this->em->persist($p);
        $this->em->flush();

        return $p;
    }
}
