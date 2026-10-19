<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\MotivoDeNaoApoiarRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Repository\SugestaoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\VisaoDoMaster;
use App\Contribua\UseCase\MontarPaginaDoContribuaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

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

    #[TestDox('Uso baixo: cabeçalho calmo e o recomendado é o primeiro que dobra o uso')]
    public function testUsoBaixo(): void
    {
        $pagina = $this->pagina(2 * RetratoDoEspaco::BYTES_POR_GB);

        self::assertSame('Mais espaço quando o escritório crescer', $pagina['espaco']['titulo']);
        self::assertSame('#0a6fa0', $pagina['espaco']['cor']);
        self::assertSame(['essencial'], array_column(array_filter($pagina['pacotes'], static fn (array $p): bool => $p['recomendado']), 'codigo'));
    }

    private function pagina(int $usados): array
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
        $situacao = new SituacaoDoEspaco($consumo, $this->createStub(EspacoDoEscritorioRepository::class), $repositorioDePlanos, $configuracoes);
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
        );
        $escritorio = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, 7);

        return $useCase->executar(new Solicitante(new User(), $escritorio->setName('Escritório'), false, true));
    }
}
