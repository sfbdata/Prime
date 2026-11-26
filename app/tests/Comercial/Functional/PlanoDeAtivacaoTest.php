<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Enum\Automacao;
use App\Comercial\Repository\CicloDaContaRepository;
use App\Comercial\Service\VarreduraDeAutomacoes;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * O plano de ativação de 7 dias contra o banco (pedido do Farlei de 26/11/2026): os dias 2 a 7 saem pela varredura,
 * um por dia, uma vez cada, só para quem confirmou depois que o passo foi ligado; o dia 6 só para escritório sem pasta.
 * O "já saiu" é o registro de envios do Carteiro real (transporte `comercial` em memória).
 */
#[CoversClass(VarreduraDeAutomacoes::class)]
#[CoversClass(CicloDaContaRepository::class)]
final class PlanoDeAtivacaoTest extends KernelTestCase
{
    use Factories;

    private Connection $conexao;
    private VarreduraDeAutomacoes $varredura;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
        $this->varredura = static::getContainer()->get(VarreduraDeAutomacoes::class);
        $this->agora = new \DateTimeImmutable('2026-11-26 10:00:00');
    }

    #[TestDox('Desligado, nenhum passo sai; ligado, o dia 2 sai uma vez para quem confirmou há 1 dia')]
    public function testDia2UmaVez(): void
    {
        [$user] = $this->confirmada('-1 day -1 hour');

        $this->varredura->mandarAtivacao($this->agora);
        self::assertSame(0, $this->envios($user), 'nasce desligado');

        $this->ligarTudo('-30 days');
        $this->varredura->mandarAtivacao($this->agora);
        $this->varredura->mandarAtivacao($this->agora);

        self::assertSame(['automacao:at2'], $this->origens($user));
    }

    #[TestDox('Cada dia manda o seu passo e nunca o anterior; com o worker parado mais que a tolerância, o passo é pulado')]
    public function testUmPassoPorDia(): void
    {
        $this->ligarTudo('-30 days');
        [$user] = $this->confirmada('-1 day -1 hour');
        $this->varredura->mandarAtivacao($this->agora);
        $this->varredura->mandarAtivacao($this->agora->modify('+1 day'));
        $this->varredura->mandarAtivacao($this->agora->modify('+1 day +1 hour'));

        self::assertSame(['automacao:at2', 'automacao:at3'], $this->origens($user), 'o dia 3 barra o 2 e vice-versa');

        [$atrasada] = $this->confirmada('-2 days -1 hour');
        $this->varredura->mandarAtivacao($this->agora);
        self::assertSame(['automacao:at3'], $this->origens($atrasada), 'no dia 3 sai só o dia 3, sem o 2 atrasado');

        [$antiga] = $this->confirmada('-10 days');
        $this->varredura->mandarAtivacao($this->agora);
        self::assertSame([], $this->origens($antiga), 'passou do dia 7 e da tolerância: nada');
    }

    #[TestDox('Só para quem confirmou depois que o passo foi ligado; passo desligado não sai e não barra os outros')]
    public function testCorteEPassoDesligado(): void
    {
        $this->ligarTudo('-1 hour');
        [$antes] = $this->confirmada('-1 day -1 hour');
        $this->varredura->mandarAtivacao($this->agora);
        self::assertSame([], $this->origens($antes), 'confirmou antes de ligar');

        $this->ligarTudo('-30 days');
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = false WHERE chave = 'at4'");
        [$user] = $this->confirmada('-1 day -1 hour');
        foreach (['+0 day', '+1 day', '+2 days', '+3 days'] as $dia) {
            $this->varredura->mandarAtivacao($this->agora->modify($dia));
        }

        self::assertSame(['automacao:at2', 'automacao:at3', 'automacao:at5'], $this->origens($user), 'o dia 4 desligado não sai; o 5 sai no dia dele');
    }

    #[TestDox('Dia 6: só para quem está num escritório que ainda não criou nenhuma pasta')]
    public function testDia6SoSemPasta(): void
    {
        $this->ligarTudo('-30 days');
        [$semPasta] = $this->confirmada('-5 days -1 hour');
        [$comPasta, $escritorio] = $this->confirmada('-5 days -1 hour');
        PastaFactory::createOne(['tenant' => $escritorio, 'criadoPor' => $comPasta]);

        $this->varredura->mandarAtivacao($this->agora);

        self::assertSame(['automacao:at6'], $this->origens($semPasta));
        self::assertNotContains('automacao:at6', $this->origens($comPasta));
    }

    #[TestDox('Os passos vão como lista (comercial_dicas, com o descadastro e a leitura), com o texto do dia')]
    public function testVaiComoLista(): void
    {
        $this->ligarTudo('-30 days');
        [$user] = $this->confirmada('-6 days -1 hour');

        $this->varredura->mandarAtivacao($this->agora);

        $envio = $this->conexao->fetchAssociative(
            "SELECT tipo, lista, assunto, situacao FROM comercial_envio WHERE origem = 'automacao:at7' AND destinatario = ?",
            [mb_strtolower((string) $user->getEmail())],
        );
        self::assertSame(['tipo' => 'lista', 'lista' => 'comercial_dicas', 'assunto' => 'Uma semana de BlueJus: conte o que achou', 'situacao' => 'enfileirado'], $envio);
    }

    /** Liga os 6 passos com o corte dado (a linha pode ainda não existir no banco de teste: a migration vem depois). */
    private function ligarTudo(string $corte): void
    {
        foreach (Automacao::daAtivacao() as $passo) {
            $this->conexao->executeStatement(
                'INSERT INTO comercial_automacao (chave, ligada, atraso, ligada_em) VALUES (:c, true, NULL, :em)
                 ON CONFLICT (chave) DO UPDATE SET ligada = true, ligada_em = EXCLUDED.ligada_em',
                ['c' => $passo->value, 'em' => $this->agora->modify($corte)->format('Y-m-d H:i:s')],
            );
        }
    }

    /** @return array{0: User, 1: Tenant} uma pessoa com a conta confirmada no próprio escritório, no momento dado */
    private function confirmada(string $quando): array
    {
        $tenant = TenantFactory::createOne()->_real();
        $user = UserFactory::createOne(['email' => 'Ativacao_' . uniqid() . '@Test.com', 'fullName' => 'Ana Ativação'])->_real();
        $this->conexao->insert('comercial_ciclo_conta', ['user_id' => $user->getId(), 'tenant_id' => $tenant->getId(), 'confirmado_em' => $this->agora->modify($quando)->format('Y-m-d H:i:s')]);

        return [$user, $tenant];
    }

    /** @return list<string> as origens dos envios da pessoa, em ordem */
    private function origens(User $user): array
    {
        return array_map('strval', $this->conexao->fetchFirstColumn(
            "SELECT origem FROM comercial_envio WHERE destinatario = ? AND origem LIKE 'automacao:at%' ORDER BY id",
            [mb_strtolower((string) $user->getEmail())],
        ));
    }

    private function envios(User $user): int
    {
        return \count($this->origens($user));
    }
}
