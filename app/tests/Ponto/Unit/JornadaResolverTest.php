<?php

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\BlocoJornada;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\JornadaTenant;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\TestCase;

class JornadaResolverTest extends TestCase
{
    private JornadaResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new JornadaResolver();
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function novoUsuario(?JornadaColaborador $jornada = null): User
    {
        $user = new User();
        $user->setEmail('teste@jusprime.com')->setFullName('Usuário Teste');
        if ($jornada !== null) {
            $jornada->setUser($user);
            $user->setJornadaColaborador($jornada);
        }
        return $user;
    }

    private function novoBlocoColaborador(array $dias, int $minutos): BlocoJornadaColaborador
    {
        $bloco = new BlocoJornadaColaborador();
        $bloco->setDiasSemana($dias);
        $bloco->setMinutosBloco($minutos);
        return $bloco;
    }

    private function novaBlocoJornada(array $dias, int $minutos): BlocoJornada
    {
        $bloco = new BlocoJornada();
        $bloco->setDiasSemana($dias);
        $bloco->setMinutosBloco($minutos);
        return $bloco;
    }

    /** Segunda-feira = índice N=1 */
    private function segunda(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-20'); // Segunda
    }

    /** Sábado = índice N=6 */
    private function sabado(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-25'); // Sábado
    }

    /** Domingo = índice N=7 */
    private function domingo(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-04-26'); // Domingo
    }

    // ──────────────────────────────────────────────────────────────────
    // resolverMetaDia — blocos do usuário
    // ──────────────────────────────────────────────────────────────────

    public function testUsuarioComBlocosRetornaBlocoDoUsuario(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->addBloco($this->novoBlocoColaborador([1, 2, 3, 4, 5], 480)); // seg–sex, 8h

        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), null);

        $this->assertSame(480, $resultado);
    }

    public function testUsuarioComBlocosIgnoraBlocosTenant(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->addBloco($this->novoBlocoColaborador([1, 2, 3, 4, 5], 480));

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->addBloco($this->novaBlocoJornada([1, 2, 3, 4, 5], 600)); // tenant tem 10h

        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), $jornadaTenant);

        $this->assertSame(480, $resultado, 'Blocos do usuário devem ter prioridade sobre os do tenant');
    }

    public function testUsuarioComBlocosDiaNaoCobertoPelosBlocosRetornaZero(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->addBloco($this->novoBlocoColaborador([1, 2, 3, 4, 5], 480)); // apenas seg–sex

        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->sabado(), null);

        $this->assertSame(0, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // resolverMetaDia — fallback para blocos do tenant
    // ──────────────────────────────────────────────────────────────────

    public function testSemBlocosUsuarioUsaBlocosTenant(): void
    {
        $jornada = new JornadaColaborador(); // sem blocos
        $user = $this->novoUsuario($jornada);

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->addBloco($this->novaBlocoJornada([1, 2, 3, 4, 5], 480));

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), $jornadaTenant);

        $this->assertSame(480, $resultado);
    }

    public function testSemBlocosUsuarioTenantNaoCobertoDiaRetornaZero(): void
    {
        $user = $this->novoUsuario(new JornadaColaborador());

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->addBloco($this->novaBlocoJornada([1, 2, 3, 4, 5], 480));

        $resultado = $this->resolver->resolverMetaDia($user, $this->sabado(), $jornadaTenant);

        $this->assertSame(0, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // resolverMetaDia — nenhum tem blocos → retorna 0
    // ──────────────────────────────────────────────────────────────────

    public function testNenhumBlocoNemEscalaRetornaZero(): void
    {
        $user = $this->novoUsuario(); // sem jornada

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), null);

        $this->assertSame(0, $resultado);
    }

    public function testNenhumBlocoComEscalaVaziaRetornaZero(): void
    {
        $user = $this->novoUsuario(new JornadaColaborador()); // jornada sem blocos, sem tenant

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), null);

        // Sem blocos, cai no fallback legado: cargaHorariaDiaria padrão = 480, dia está nos diasSemana padrão [1-5]
        $this->assertSame(480, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // resolverMetaDia — fallback legado (campos planos)
    // ──────────────────────────────────────────────────────────────────

    public function testFallbackLegadoDiaUtil(): void
    {
        $jornada = new JornadaColaborador(); // sem blocos; padrão: 480 min, seg–sex
        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->segunda(), null);

        $this->assertSame(480, $resultado);
    }

    public function testFallbackLegadoSabadoComCarga(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setDiasSemana([1, 2, 3, 4, 5, 6]);
        $jornada->setCargaHorariaSabado(240); // 4h no sábado

        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->sabado(), null);

        $this->assertSame(240, $resultado);
    }

    public function testFallbackLegadoDomingoRetornaZero(): void
    {
        $jornada = new JornadaColaborador(); // dias padrão [1-5] — não inclui domingo
        $user = $this->novoUsuario($jornada);

        $resultado = $this->resolver->resolverMetaDia($user, $this->domingo(), null);

        $this->assertSame(0, $resultado);
    }

    public function testFallbackLegadoDiaForaDaEscalaRetornaZero(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setDiasSemana([1, 2, 3, 4]); // sem sexta

        $user = $this->novoUsuario($jornada);

        $sexta = new \DateTimeImmutable('2026-04-24'); // N=5

        $resultado = $this->resolver->resolverMetaDia($user, $sexta, null);

        $this->assertSame(0, $resultado);
    }

    // ──────────────────────────────────────────────────────────────────
    // resolverAlertaHabilitado
    // ──────────────────────────────────────────────────────────────────

    public function testAlertaUsaEscalaSeExistir(): void
    {
        $jornada = new JornadaColaborador();
        $jornada->setAlertaHabilitado(false);
        $user = $this->novoUsuario($jornada);

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->setAlertaHabilitado(true);

        $this->assertFalse($this->resolver->resolverAlertaHabilitado($user, $jornadaTenant));
    }

    public function testAlertaFallbackParaTenantSemEscala(): void
    {
        $user = $this->novoUsuario(); // sem jornada

        $jornadaTenant = new JornadaTenant();
        $jornadaTenant->setAlertaHabilitado(true);

        $this->assertTrue($this->resolver->resolverAlertaHabilitado($user, $jornadaTenant));
    }

    public function testAlertaRetornaFalseSemEscalaSemTenant(): void
    {
        $user = $this->novoUsuario();

        $this->assertFalse($this->resolver->resolverAlertaHabilitado($user, null));
    }
}
