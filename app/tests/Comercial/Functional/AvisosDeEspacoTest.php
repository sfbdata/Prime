<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\EventListener\AvisosDeEspacoListener;
use App\Comercial\Repository\AvisoDeEspacoRepository;
use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Event\EnvioRecusadoPorEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Event\EspacoFoiPedido;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Entity\Tenant\Tenant;
use App\Tests\Comercial\Support\CriaCampanhas;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Os avisos de espaço da fatia 1b contra o banco (spec comercial-e-emails.md §4 e §5): `e90` só no limite escolhido,
 * um por escritório e dia (inclusive descendo e subindo de novo), virada do dia em São Paulo, `e100` só com o bloqueio
 * ligado (pela faixa ou pela recusa), `ped` por plano, e só os primários ativos recebem. O Carteiro é o real.
 */
#[CoversClass(AvisosDeEspacoListener::class)]
#[CoversClass(AvisoDeEspacoRepository::class)]
final class AvisosDeEspacoTest extends KernelTestCase
{
    use CriaCampanhas;

    private Connection $conexao;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexao = static::getContainer()->get(Connection::class);
    }

    #[TestDox('e90: sai só ao cruzar o limite escolhido (80, 85 ou 90), para cada primário ativo, e nunca para secundário ou inativo')]
    public function testE90NoLimiteEscolhido(): void
    {
        [$tenant, $prim, $outroPrim, $sec, $inativo] = $this->escritorio();

        $this->despachar($this->subiu($tenant, 70, 85));
        self::assertSame(0, $this->envios('e90', $tenant), 'o padrão é 90: 85 não cruza');

        $this->despachar($this->subiu($tenant, 85, 95));
        self::assertSame(1, $this->envios('e90', $prim->getEmail()));
        self::assertSame(1, $this->envios('e90', $outroPrim->getEmail()));
        self::assertSame(0, $this->envios('e90', $sec->getEmail()));
        self::assertSame(0, $this->envios('e90', $inativo->getEmail()));
        self::assertStringContainsString('chegou a 90% do espaço', $this->titulo('e90', $prim->getEmail()));

        [$outro, $primDoOutro] = $this->escritorio();
        $this->conexao->executeStatement("UPDATE comercial_automacao SET atraso = '80' WHERE chave = 'e90'");
        $this->despachar($this->subiu($outro, 70, 85));
        self::assertSame(1, $this->envios('e90', $primDoOutro->getEmail()));
        self::assertStringContainsString('chegou a 80% do espaço', $this->titulo('e90', $primDoOutro->getEmail()), 'o limite escolhido, não o 90% do dc');
    }

    #[TestDox('e90: um por escritório e dia, mesmo descendo e subindo de novo; no dia seguinte (São Paulo) sai de novo')]
    public function testE90UmPorDia(): void
    {
        [$tenant, $prim] = $this->escritorio();
        $relogio = $this->relogio('2026-10-21 12:00:00');

        $this->despachar($this->subiu($tenant, 80, 91));
        $this->despachar($this->subiu($tenant, 70, 92));   // desceu (apagou arquivos) e subiu de novo no mesmo dia
        self::assertSame(1, $this->envios('e90', $prim->getEmail()));

        $relogio->modify('2026-10-22 02:30:00');   // 23h30 do dia 21 em São Paulo: ainda o mesmo dia
        $this->despachar($this->subiu($tenant, 80, 93));
        self::assertSame(1, $this->envios('e90', $prim->getEmail()));

        $relogio->modify('2026-10-22 03:30:00');   // 00h30 do dia 22 em São Paulo
        $this->despachar($this->subiu($tenant, 80, 94));
        self::assertSame(2, $this->envios('e90', $prim->getEmail()));
    }

    #[TestDox('e100: pela faixa Bloqueio só com o bloqueio ligado; pela recusa também; um por dia somando os dois caminhos')]
    public function testE100SoComBloqueioLigado(): void
    {
        [$tenant, $prim] = $this->escritorio();

        $this->despachar(new EspacoAtingiuFaixa((int) $tenant->getId(), FaixaDoEspaco::Bloqueio, FaixaDoEspaco::Critico, $this->retrato($tenant, 100, false)));
        self::assertSame(0, $this->envios('e100', $prim->getEmail()), 'sem o bloqueio ligado, "novos envios pausados" seria falso');

        $this->despachar(new EspacoAtingiuFaixa((int) $tenant->getId(), FaixaDoEspaco::Critico, FaixaDoEspaco::Alerta, $this->retrato($tenant, 95, true)));
        self::assertSame(0, $this->envios('e100', $prim->getEmail()), 'outra faixa não é esgotado');

        $this->despachar(new EspacoAtingiuFaixa((int) $tenant->getId(), FaixaDoEspaco::Bloqueio, FaixaDoEspaco::Critico, $this->retrato($tenant, 100, true)));
        self::assertSame(1, $this->envios('e100', $prim->getEmail()));

        $this->recusar($tenant);
        self::assertSame(1, $this->envios('e100', $prim->getEmail()), 'a recusa no mesmo dia não repete');

        [$outro, $primDoOutro] = $this->escritorio();
        $this->recusar($outro);
        self::assertSame(1, $this->envios('e100', $primDoOutro->getEmail()), 'a primeira recusa dispara o e100 (Q6)');
    }

    #[TestDox('e100 pela recusa dentro de uma transação: espera o fim (do comando, aqui) para não sumir no rollback')]
    public function testRecusaDentroDeTransacaoEspera(): void
    {
        [$tenant, $prim] = $this->escritorio();
        $this->conexao->beginTransaction();
        $this->despachar(new EnvioRecusadoPorEspaco((int) $tenant->getId(), $this->retrato($tenant, 100, true)));
        $this->conexao->rollBack();
        self::assertSame(0, $this->envios('e100', $prim->getEmail()), 'nada foi gravado dentro da transação desfeita');

        $this->terminarComando();
        self::assertSame(1, $this->envios('e100', $prim->getEmail()));
    }

    #[TestDox('ped: para os primários, também quando o Master pediu; um por escritório, plano e dia')]
    public function testPedPorPlano(): void
    {
        [$tenant, $prim, $outroPrim, $sec] = $this->escritorio();

        $this->despachar(new EspacoFoiPedido((int) $tenant->getId(), 'plano_20', null));
        $this->despachar(new EspacoFoiPedido((int) $tenant->getId(), 'plano_20', (int) $prim->getId()));
        $this->despachar(new EspacoFoiPedido((int) $tenant->getId(), 'plano_50', (int) $prim->getId()));

        self::assertSame(2, $this->envios('ped', $prim->getEmail()), 'dois planos, duas vezes');
        self::assertSame(2, $this->envios('ped', $outroPrim->getEmail()));
        self::assertSame(0, $this->envios('ped', $sec->getEmail()));
    }

    #[TestDox('Desligada, não sai nem consome a vez; ligada depois no mesmo dia, sai')]
    public function testDesligadaNaoConsomeAVez(): void
    {
        [$tenant, $prim] = $this->escritorio();
        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = false WHERE chave = 'ped'");
        $this->despachar(new EspacoFoiPedido((int) $tenant->getId(), 'plano_20', null));
        self::assertSame(0, $this->envios('ped', $prim->getEmail()));

        $this->conexao->executeStatement("UPDATE comercial_automacao SET ligada = true WHERE chave = 'ped'");
        $this->despachar(new EspacoFoiPedido((int) $tenant->getId(), 'plano_20', null));
        self::assertSame(1, $this->envios('ped', $prim->getEmail()));
    }

    #[TestDox('Ouvinte nunca derruba quem gravou: escritório inexistente não lança nada')]
    public function testOuvinteProtegido(): void
    {
        $this->despachar(new EspacoFoiPedido(999999999, 'plano_20', null));
        $this->addToAssertionCount(1);
    }

    /**
     * Um escritório com dois primários, um secundário e um primário de conta desativada.
     *
     * @return array{0: Tenant, 1: \App\Entity\Auth\User, 2: \App\Entity\Auth\User, 3: \App\Entity\Auth\User, 4: \App\Entity\Auth\User}
     */
    private function escritorio(): array
    {
        $prim = $this->usuario('prim', nome: 'Ana Primária');
        $tenant = $this->vincular($prim, true);
        $outroPrim = $this->usuario('prim2');
        $this->vincular($outroPrim, true, $tenant);
        $sec = $this->usuario('sec');
        $this->vincular($sec, false, $tenant);
        $inativo = $this->usuario('inativo', ativo: false);
        $this->vincular($inativo, true, $tenant);

        return [$tenant, $prim, $outroPrim, $sec, $inativo];
    }

    private function subiu(Tenant $tenant, int $antes, int $depois): UsoDoEspacoSubiu
    {
        return new UsoDoEspacoSubiu((int) $tenant->getId(), $antes, $depois, $this->retrato($tenant, $depois, true));
    }

    private function retrato(Tenant $tenant, int $percentual, bool $bloqueioAtivo): RetratoDoEspaco
    {
        return new RetratoDoEspaco(
            (int) $tenant->getId(), $percentual, 100, $percentual, FaixaDoEspaco::Livre, OrigemDoLimite::Plano,
            'plano_20', 'Plano 20', null, OrigemDoLimite::Plano, $bloqueioAtivo,
        );
    }

    private function recusar(Tenant $tenant): void
    {
        // O KernelTestCase roda dentro da transação do DAMA: a recusa espera o fim do comando, como na cópia da pasta.
        $this->despachar(new EnvioRecusadoPorEspaco((int) $tenant->getId(), $this->retrato($tenant, 100, true)));
        $this->terminarComando();
    }

    private function terminarComando(): void
    {
        static::getContainer()->get(AvisosDeEspacoListener::class)->aoTerminar();
    }

    private function relogio(string $agora): MockClock
    {
        $relogio = new MockClock(new \DateTimeImmutable($agora, new \DateTimeZone('UTC')));
        static::getContainer()->set(\Psr\Clock\ClockInterface::class, $relogio);

        return $relogio;
    }

    private function despachar(object $evento): void
    {
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch($evento);
    }

    private function envios(string $chave, Tenant|string|null $para): int
    {
        if ($para instanceof Tenant) {
            return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_aviso_espaco WHERE tenant_id = ? AND chave = ?', [$para->getId(), $chave]);
        }

        return (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM comercial_envio WHERE origem = ? AND destinatario = ?', ['automacao:' . $chave, mb_strtolower((string) $para)]);
    }

    private function titulo(string $chave, ?string $para): string
    {
        return (string) $this->conexao->fetchOne(
            'SELECT c.titulo FROM comercial_envio_conteudo c JOIN comercial_envio e ON e.id = c.envio_id WHERE e.origem = ? AND e.destinatario = ?',
            ['automacao:' . $chave, mb_strtolower((string) $para)],
        );
    }
}
