<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Central\Entity\ConfiguracaoDePresenca;
use App\Central\Repository\ConfiguracaoDePresencaRepository;
use App\Central\Twig\CentralExtension;
use App\Central\UseCase\AlterarAusenciaDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaCenarioDoCadeadoFuncionalTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Spec memoria-do-layout: `central_disponivel()` e `presenca_sou_primario()` leem o vínculo de quem está logado uma
 * vez por requisição; o limite de ausência do escritório também. Gravar o limite (pelo UseCase) e desativar o
 * vínculo na mesma requisição mudam a resposta seguinte. Consultas contadas no `doctrine.debug_data_holder`.
 */
#[CoversClass(CentralExtension::class)]
#[CoversClass(ConfiguracaoDePresencaRepository::class)]
final class MemoriaDaCentralNoLayoutTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    /** @return array{0: User, 1: User, 2: Tenant} primário, colaborador e escritório */
    private function cenario(): array
    {
        self::bootKernel();
        [$primario, $tenant] = $this->criarEscritorio();
        $colaborador = $this->criarColaborador($tenant, 'Ana Central');
        static::getContainer()->get(CentralExtension::class)->reset();
        static::getContainer()->get(ConfiguracaoDePresencaRepository::class)->reset();

        return [$primario, $colaborador, $tenant];
    }

    private function autenticar(User $user, Tenant $tenant): void
    {
        $container = static::getContainer();
        $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $session = new Session(new MockArraySessionStorage());
        $session->set('current_tenant_id', $tenant->getId());
        $request = new Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
    }

    private function extensao(): CentralExtension
    {
        return static::getContainer()->get(CentralExtension::class);
    }

    /** @return list<string> */
    private function consultasDurante(callable $acao): array
    {
        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();
        $acao();

        return array_column($coletor->getData()['default'] ?? [], 'sql');
    }

    /** @param list<string> $sqls */
    private function contar(array $sqls, string $tabela): int
    {
        return count(array_filter($sqls, static fn (string $sql): bool => (bool) preg_match('/^\s*SELECT\b.*\bFROM\s+' . $tabela . '\b/is', $sql)));
    }

    #[TestDox('N perguntas do layout: uma consulta de vínculo e uma do limite de ausência')]
    public function testUmaConsultaEmNChamadas(): void
    {
        [$primario, $colaborador, $tenant] = $this->cenario();
        $this->autenticar($colaborador, $tenant);
        $ext = $this->extensao();

        $respostas = [];
        $sqls = $this->consultasDurante(static function () use ($ext, &$respostas): void {
            for ($i = 0; $i < 9; ++$i) {
                $respostas[] = [$ext->disponivel(), $ext->souPrimario(), $ext->ausenteAposMin()];
            }
        });

        self::assertSame(array_fill(0, 9, [true, false, 60]), $respostas);
        self::assertSame(1, $this->contar($sqls, 'user_tenant'), implode("\n", $sqls));
        self::assertSame(1, $this->contar($sqls, 'central_configuracao_presenca'), implode("\n", $sqls));
    }

    #[TestDox('Primário: disponível e primário; reset() consulta de novo')]
    public function testPrimarioEReset(): void
    {
        [$primario, , $tenant] = $this->cenario();
        $this->autenticar($primario, $tenant);
        $ext = $this->extensao();
        self::assertTrue($ext->disponivel());
        self::assertTrue($ext->souPrimario());

        $sqls = $this->consultasDurante(static function () use ($ext): void {
            $ext->reset();
            $ext->disponivel();
            $ext->souPrimario();
        });
        self::assertSame(1, $this->contar($sqls, 'user_tenant'));
    }

    #[TestDox('Outra pessoa logada no mesmo serviço não herda o vínculo da anterior')]
    public function testOutraPessoaNaoHerda(): void
    {
        [$primario, $colaborador, $tenant] = $this->cenario();
        $this->autenticar($primario, $tenant);
        self::assertTrue($this->extensao()->souPrimario());

        $this->autenticar($colaborador, $tenant);
        self::assertFalse($this->extensao()->souPrimario());
        self::assertTrue($this->extensao()->disponivel());
    }

    #[TestDox('Vínculo desativado na mesma requisição: deixa de estar disponível')]
    public function testVinculoDesativadoNaMesmaRequisicao(): void
    {
        [, $colaborador, $tenant] = $this->cenario();
        $this->autenticar($colaborador, $tenant);
        $ext = $this->extensao();
        self::assertTrue($ext->disponivel());

        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $colaborador, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        $vinculo->alterarSituacao(SituacaoNaEquipe::Suspenso);
        $this->em()->flush();

        self::assertFalse($ext->disponivel());
        self::assertFalse($ext->souPrimario());
    }

    #[TestDox('Limite de ausência gravado pelo UseCase na mesma requisição: a leitura seguinte vê o novo')]
    public function testLimiteGravadoNaMesmaRequisicao(): void
    {
        [$primario, , $tenant] = $this->cenario();
        $this->autenticar($primario, $tenant);
        $ext = $this->extensao();
        self::assertSame(60, $ext->ausenteAposMin());

        $novo = 15;
        static::getContainer()->get(AlterarAusenciaDoEscritorioUseCase::class)->executar($primario, $tenant, $novo);

        self::assertSame($novo, $ext->ausenteAposMin());
        $config = static::getContainer()->get(ConfiguracaoDePresencaRepository::class)->doEscritorio($tenant);
        self::assertInstanceOf(ConfiguracaoDePresenca::class, $config);
    }
}
