<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\CodigoPixDaContaEstatico;
use App\Contribua\Entity\BonusDeEspaco;
use App\Indicacao\Entity\Indicacao;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\VisaoDoMaster;
use App\Contribua\UseCase\MontarPaginaDoContribuaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(MontarPaginaDoContribuaUseCase::class)]
final class PacotesDaPaginaTest extends TestCase
{
    #[TestDox('O caso do print do desenho: 9,3 GB de 10 GB no Free')]
    public function testCasoDoPrint(): void
    {
        $pagina = $this->pagina((int) (9.3 * RetratoDoEspaco::BYTES_POR_GB));

        self::assertSame('Seu escritório está com 93% do espaço ocupado', $pagina['espaco']['titulo']);
        self::assertSame('#c08a12', $pagina['espaco']['cor']);
        self::assertSame('9,3 GB', $pagina['espaco']['uso']);
        $pacotes = array_column($pagina['pacotes'], null, 'codigo');
        self::assertSame(['Seu plano atual', 'Quero +10 GB', 'Quero +40 GB', 'Quero +90 GB'], array_column($pagina['pacotes'], 'botao'));
        self::assertTrue($pacotes['essencial']['recomendado']);
        self::assertSame('menos de R$ 0,67 por dia', $pacotes['essencial']['porDia']);
        self::assertSame('R$ 19,90', $pacotes['essencial']['preco']);
        self::assertSame('Grátis', $pacotes['free']['preco']);
        self::assertSame('seu plano atual', $pacotes['free']['periodo']);
        self::assertTrue($pacotes['free']['desabilitado']);
    }

    #[TestDox('Com bônus vigente os pacotes comparam com o limite base: o plano logo acima da base continua pedível e o ganho é sobre a base')]
    public function testBonusNaoEscondePlanoPago(): void
    {
        $comBonus = array_column($this->pagina(2 * RetratoDoEspaco::BYTES_POR_GB, limiteDoMaster: 18, comBonus: true)['pacotes'], null, 'codigo');
        $semBonus = array_column($this->pagina(2 * RetratoDoEspaco::BYTES_POR_GB, limiteDoMaster: 18)['pacotes'], null, 'codigo');

        self::assertSame('Quero +2 GB', $comBonus['essencial']['botao']);
        self::assertFalse($comBonus['essencial']['desabilitado']);
        self::assertSame('+2 GB', $comBonus['essencial']['ganho']);
        self::assertSame($semBonus['essencial'], $comBonus['essencial'], 'o bônus não muda o cartão');
    }

    #[TestDox('Uso baixo: cabeçalho calmo e o recomendado é o primeiro que dobra o uso')]
    public function testUsoBaixo(): void
    {
        $pagina = $this->pagina(2 * RetratoDoEspaco::BYTES_POR_GB);

        self::assertSame('Mais espaço quando o escritório crescer', $pagina['espaco']['titulo']);
        self::assertSame('#0a6fa0', $pagina['espaco']['cor']);
        self::assertSame(['essencial'], array_column(array_filter($pagina['pacotes'], static fn (array $p): bool => $p['recomendado']), 'codigo'));
    }

    #[TestDox('Acima de 100% com o bloqueio desligado: vermelho do desenho com o texto de uso alto, sem "pausados"')]
    public function testAcimaDeCemSemBloqueio(): void
    {
        $pagina = $this->pagina((int) (24.3 * RetratoDoEspaco::BYTES_POR_GB));

        self::assertSame('Seu escritório está com 243% do espaço ocupado', $pagina['espaco']['titulo']);
        self::assertSame('#b02a37', $pagina['espaco']['cor']);
        self::assertSame('#fdf1f2', $pagina['espaco']['fundo']);
        self::assertSame('100%', $pagina['espaco']['largura']);
    }

    #[TestDox('Selo do convênio OAB só no plano gratuito')]
    public function testSeloOabSoNoGratuito(): void
    {
        $pacotes = array_column($this->pagina((int) (9.3 * RetratoDoEspaco::BYTES_POR_GB))['pacotes'], 'oab', 'codigo');

        self::assertSame(['free' => true, 'essencial' => false, 'profissional' => false, 'avancado' => false], $pacotes);
    }

    #[TestDox('Nuvem "+ Espaço" só nos pagos maiores que o plano atual, verde só no recomendado')]
    public function testNuvemNosPagosMaioresQueOAtual(): void
    {
        $pacotes = array_column($this->pagina((int) (9.3 * RetratoDoEspaco::BYTES_POR_GB))['pacotes'], null, 'codigo');

        self::assertSame(['free' => false, 'essencial' => true, 'profissional' => true, 'avancado' => true], array_column($pacotes, 'temNuvem', 'codigo'));
        self::assertSame(['free' => false, 'essencial' => true, 'profissional' => false, 'avancado' => false], array_column($pacotes, 'nuvemRecomendada', 'codigo'));
    }

    #[TestDox('No Profissional: sem nuvem no atual nem nos menores, nuvem verde no recomendado acima dele')]
    public function testNuvemComPlanoPagoAtual(): void
    {
        $pacotes = array_column($this->pagina(30 * RetratoDoEspaco::BYTES_POR_GB, 'profissional')['pacotes'], null, 'codigo');

        self::assertSame(['free' => false, 'essencial' => false, 'profissional' => false, 'avancado' => true], array_column($pacotes, 'temNuvem', 'codigo'));
        self::assertTrue($pacotes['profissional']['atual']);
        self::assertSame('Menor que o atual', $pacotes['essencial']['botao']);
        self::assertTrue($pacotes['avancado']['nuvemRecomendada']);
        self::assertFalse($pacotes['profissional']['nuvemRecomendada']);
    }

    private function pagina(int $usados, ?string $planoAtual = null, ?int $limiteDoMaster = null, bool $comBonus = false): array
    {
        $planos = [
            new Plano('free', 'Free', 10, 0, 'Todos os recursos do BlueJus, sem custo', 1),
            new Plano('essencial', 'Essencial', 20, 1990, 'Advogado autônomo ou escritório pequeno', 2),
            new Plano('profissional', 'Profissional', 50, 3990, 'Equipes que anexam processos e documentos todos os dias', 3),
            new Plano('avancado', 'Avançado', 100, 6990, 'Alto volume de processos e digitalizações', 4),
        ];
        $repositorioDePlanos = $this->createStub(PlanoRepository::class);
        $repositorioDePlanos->method('free')->willReturn($planos[0]);
        $repositorioDePlanos->method('ativosEmOrdem')->willReturn($planos);
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn($usados);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());
        $escritorio = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, 7);
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        if ($planoAtual !== null) {
            $plano = array_values(array_filter($planos, static fn (Plano $p): bool => $p->getCodigo() === $planoAtual))[0];
            $espacos->method('doTenant')->willReturn(new EspacoDoEscritorio($escritorio, $plano));
        }
        if ($limiteDoMaster !== null) {
            $espaco = new EspacoDoEscritorio($escritorio, $planos[0]);
            $espaco->definirLimite($limiteDoMaster, false, 'teste', null);
            $espacos->method('doTenant')->willReturn($espaco);
        }
        $bonus = $this->createStub(BonusDeEspacoRepository::class);
        if ($comBonus) {
            $bonus->method('vigentesDosTenants')->willReturn([7 => [new BonusDeEspaco($escritorio, (new \ReflectionClass(Indicacao::class))->newInstanceWithoutConstructor(), 5, new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+29 days'))]]);
        }
        $situacao = new SituacaoDoEspaco($consumo, $espacos, $repositorioDePlanos, $configuracoes, $bonus, new MockClock());
        $sugestoes = $this->createStub(SugestaoRepository::class);
        $sugestoes->method('doEscritorio')->willReturn([]);

        $useCase = new MontarPaginaDoContribuaUseCase(
            $situacao,
            $repositorioDePlanos,
            $sugestoes,
            $this->createStub(ApoioRepository::class),
            $this->createStub(MotivoDeNaoApoiarRepository::class),
            // Quem pede não é o Master: a visão de todos os escritórios nunca é usada aqui.
            (new \ReflectionClass(VisaoDoMaster::class))->newInstanceWithoutConstructor(),
            new CodigoPixDaContaEstatico(),
        );

        return $useCase->executar(new Solicitante(new User(), $escritorio->setName('Escritório'), false, true));
    }
}
