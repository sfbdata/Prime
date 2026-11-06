<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\DTO\ConfigurarCadeadoInput;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\NivelNoCadeado;
use App\Pasta\UseCase\ConfigurarCadeadoDaPastaUseCase;
use App\Repository\UserTenantRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Spec memoria-do-layout, item do cadeado: o "Master ou primário?" do `NivelNoCadeado` reaproveita o vínculo guardado
 * e as pastas ocultas ficam guardadas por pessoa e escritório. A resposta tem de ser a MESMA de antes: compara a
 * memória com o serviço zerado (`reset()`) antes de cada pergunta, para o primário, para quem está dentro do
 * cadeado e para quem está fora. As consultas são contadas no `doctrine.debug_data_holder`.
 */
#[CoversClass(NivelNoCadeado::class)]
final class NivelNoCadeadoMemoriaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    /**
     * @return array{tenant: Tenant, pessoas: array<string, User>, pastas: array<string, Pasta>}
     */
    private function cenario(): array
    {
        self::bootKernel();
        [$primario, $tenant] = $this->criarEscritorio();
        $dentro = $this->criarColaborador($tenant, 'Ana Dentro');
        $fora   = $this->criarColaborador($tenant, 'Bia Fora');

        $porPessoa = $this->criarPasta($tenant, 'CLIENTE POR PESSOA');
        $this->trancar($porPessoa, $primario, [[$dentro, NivelDeAcesso::Visualizar]]);
        $todos = $this->criarPasta($tenant, 'CLIENTE TODOS');
        $this->trancar($todos, $primario);
        $livre = $this->criarPasta($tenant, 'CLIENTE LIVRE');

        return [
            'tenant'  => $tenant,
            'pessoas' => ['primario' => $primario, 'dentro' => $dentro, 'fora' => $fora],
            'pastas'  => ['porPessoa' => $porPessoa, 'todos' => $todos, 'livre' => $livre],
        ];
    }

    private function servico(): NivelNoCadeado
    {
        return static::getContainer()->get(NivelNoCadeado::class);
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
        return count(array_filter($sqls, static fn (string $sql): bool => (bool) preg_match('/\bFROM\s+' . $tabela . '\b/i', $sql)));
    }

    /**
     * O que o serviço responde para a pessoa, em todas as perguntas que usam a memória.
     *
     * @param array<string, Pasta> $pastas
     *
     * @return array<string, mixed>
     */
    private function respostas(NivelNoCadeado $servico, User $pessoa, Tenant $tenant, array $pastas, bool $zerarAntes): array
    {
        $perguntar = static function (callable $pergunta) use ($servico, $zerarAntes): mixed {
            if ($zerarAntes) {
                $servico->reset();
            }

            return $pergunta();
        };

        $respostas = [];
        for ($i = 0; $i < 3; ++$i) {
            $ocultas = $perguntar(static fn (): array => $servico->idsDasPastasOcultas($pessoa, $tenant));
            sort($ocultas);
            $respostas['ocultas'][] = $ocultas;
            $respostas['podeConfigurar'][] = $perguntar(static fn (): bool => $servico->podeConfigurar($pessoa, $tenant));
            $respostas['processosOcultos'][] = $perguntar(static fn (): array => $servico->numerosDeProcessoOcultos($pessoa, $tenant));
            foreach ($pastas as $nome => $pasta) {
                $respostas['nivel'][$nome][] = $perguntar(static fn (): NivelDeAcesso => $servico->nivel($pessoa, $pasta));
            }
        }

        return $respostas;
    }

    /** @return iterable<string, array{string}> */
    public static function pessoas(): iterable
    {
        yield 'primário' => ['primario'];
        yield 'colaborador dentro do cadeado' => ['dentro'];
        yield 'colaborador fora do cadeado' => ['fora'];
    }

    #[DataProvider('pessoas')]
    #[TestDox('Com memória, as respostas são as mesmas do serviço zerado antes de cada pergunta ($quem)')]
    public function testMemoriaEquivaleAoServicoZerado(string $quem): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $pessoa  = $c['pessoas'][$quem];

        $servico->reset();
        $comMemoria = $this->respostas($servico, $pessoa, $c['tenant'], $c['pastas'], false);
        $semMemoria = $this->respostas($servico, $pessoa, $c['tenant'], $c['pastas'], true);

        self::assertEquals($semMemoria, $comMemoria);

        // E o valor em si, para a equivalência não passar com as duas erradas.
        $esperadas = [
            'primario' => [],
            'dentro'   => [(int) $c['pastas']['todos']->getId()],
            'fora'     => [(int) $c['pastas']['porPessoa']->getId(), (int) $c['pastas']['todos']->getId()],
        ][$quem];
        sort($esperadas);
        self::assertSame($esperadas, $comMemoria['ocultas'][0]);
        self::assertSame($quem === 'primario', $comMemoria['podeConfigurar'][0]);
    }

    #[DataProvider('pessoas')]
    #[TestDox('Critério de "vê a pasta" em DQL: com memória é igual ao do serviço zerado ($quem)')]
    public function testCriterioEquivale(string $quem): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $pessoa  = $c['pessoas'][$quem];
        $sessao  = new Session(new MockArraySessionStorage());
        $sessao->set('current_tenant_id', $c['tenant']->getId());
        $request = new Request();
        $request->setSession($sessao);
        static::getContainer()->get('request_stack')->push($request);

        $visiveis = function (bool $zerarAntes) use ($servico, $pessoa): array {
            if ($zerarAntes) {
                $servico->reset();
            }
            $qb = $this->em()->createQueryBuilder()->select('p.id')->from(Pasta::class, 'p');
            $servico->restringirAoVisivel($qb, 'p', $pessoa);

            return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
        };

        $servico->reset();
        $comMemoria = [$visiveis(false), $visiveis(false)];
        $semMemoria = [$visiveis(true), $visiveis(true)];
        sort($comMemoria[0]);
        sort($comMemoria[1]);
        sort($semMemoria[0]);
        sort($semMemoria[1]);

        self::assertSame($semMemoria, $comMemoria);
    }

    #[TestDox('N perguntas: uma consulta de vínculo e uma de pastas ocultas por pessoa e escritório')]
    public function testUmaConsultaEmNChamadas(): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $servico->reset();
        $pessoa  = $c['pessoas']['fora'];

        $sqls = $this->consultasDurante(static function () use ($servico, $pessoa, $c): void {
            for ($i = 0; $i < 5; ++$i) {
                $servico->idsDasPastasOcultas($pessoa, $c['tenant']);
                $servico->podeConfigurar($pessoa, $c['tenant']);
                $servico->numerosDeProcessoOcultos($pessoa, $c['tenant']);
            }
        });

        self::assertSame(1, $this->contar($sqls, 'user_tenant'), implode("\n", $sqls));
        self::assertSame(1, $this->contar($sqls, 'pasta_cadeado'), implode("\n", $sqls));
    }

    #[TestDox('reset() esvazia a memória: consulta de novo')]
    public function testResetEsvazia(): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $pessoa  = $c['pessoas']['fora'];
        $servico->idsDasPastasOcultas($pessoa, $c['tenant']);

        $vinculos = static::getContainer()->get(UserTenantRepository::class);
        $sqls = $this->consultasDurante(static function () use ($servico, $vinculos, $pessoa, $c): void {
            // O kernel.reset zera as duas memórias: a do cadeado e a do vínculo ativo no repositório.
            $servico->reset();
            $vinculos->reset();
            $servico->idsDasPastasOcultas($pessoa, $c['tenant']);
        });

        self::assertSame(1, $this->contar($sqls, 'user_tenant'));
        self::assertSame(1, $this->contar($sqls, 'pasta_cadeado'));
    }

    #[TestDox('Cadeado gravado na mesma requisição (pelo UseCase): as pastas ocultas são lidas de novo')]
    public function testCadeadoGravadoNaMesmaRequisicao(): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $servico->reset();
        $dentro  = $c['pessoas']['dentro'];
        self::assertSame([(int) $c['pastas']['todos']->getId()], $servico->idsDasPastasOcultas($dentro, $c['tenant']));

        static::getContainer()->get(ConfigurarCadeadoDaPastaUseCase::class)->executar(
            new ConfigurarCadeadoInput($c['pastas']['livre'], $c['pessoas']['primario'], $c['tenant'], ModoDoCadeado::Todos),
        );

        $ocultas = $servico->idsDasPastasOcultas($dentro, $c['tenant']);
        sort($ocultas);
        $esperadas = [(int) $c['pastas']['todos']->getId(), (int) $c['pastas']['livre']->getId()];
        sort($esperadas);
        self::assertSame($esperadas, $ocultas);
    }

    #[TestDox('Cadeado gravado direto pela entidade (sem UseCase, como num comando ou teste): o ouvinte esquece a memória')]
    public function testCadeadoGravadoDiretoPelaEntidade(): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $servico->reset();
        $dentro  = $c['pessoas']['dentro'];
        self::assertSame([(int) $c['pastas']['todos']->getId()], $servico->idsDasPastasOcultas($dentro, $c['tenant']));
        self::assertTrue($servico->nivel($dentro, $c['pastas']['livre'])->podeVer());

        $this->trancar($c['pastas']['livre'], $c['pessoas']['primario']);

        $ocultas = $servico->idsDasPastasOcultas($dentro, $c['tenant']);
        sort($ocultas);
        $esperadas = [(int) $c['pastas']['todos']->getId(), (int) $c['pastas']['livre']->getId()];
        sort($esperadas);
        self::assertSame($esperadas, $ocultas, 'cadeado novo');
        self::assertFalse($servico->nivel($dentro, $c['pastas']['livre'])->podeVer(), 'nível da pasta trancada');

        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $cadeado = $servico->cadeadoDe($c['pastas']['livre']);
        self::assertNotNull($cadeado);
        $em->remove($cadeado);
        $em->flush();

        self::assertSame([(int) $c['pastas']['todos']->getId()], $servico->idsDasPastasOcultas($dentro, $c['tenant']), 'cadeado removido');
    }

    #[TestDox('Perfil trocado na mesma requisição vale na hora: o vínculo guardado é lido de novo a cada pergunta')]
    public function testPerfilTrocadoNaMesmaRequisicao(): void
    {
        $c       = $this->cenario();
        $servico = $this->servico();
        $servico->reset();
        $fora    = $c['pessoas']['fora'];
        self::assertFalse($servico->podeConfigurar($fora, $c['tenant']));
        self::assertNotSame([], $servico->idsDasPastasOcultas($fora, $c['tenant']));

        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $fora, 'tenant' => $c['tenant']]);
        self::assertNotNull($vinculo);
        $sistema = new TenantRole();
        $sistema->setTenant($c['tenant']);
        $sistema->setName('Administrador ' . uniqid());
        $sistema->setIsSystem(true);
        $this->em()->persist($sistema);
        $vinculo->setTenantRole($sistema);
        $this->em()->flush();

        self::assertTrue($servico->podeConfigurar($fora, $c['tenant']));
        self::assertSame([], $servico->idsDasPastasOcultas($fora, $c['tenant']));
    }
}
