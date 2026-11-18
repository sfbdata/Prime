<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Voto\DTO\PainelDeUnidadesDaPauta;
use App\Voto\DTO\UnidadeNoPainel;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\OrdemDasCandidaturas;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\SemPermissaoParaVerAsUnidadesException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\Service\IdentificacaoDaUnidade;
use App\Voto\UseCase\MontarPainelDeUnidadesUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Painel de unidades": estados votou, nao_votou e inapta; bloco fora dos grupos da pauta não entra; ordem natural por
 * bloco e identificação; CPF mascarado; 403 sem permissão e 404 dos demais casos. Só a identificação sai.
 */
#[CoversClass(MontarPainelDeUnidadesUseCase::class)]
#[CoversClass(PainelDeUnidadesDaPauta::class)]
#[CoversClass(UnidadeNoPainel::class)]
#[CoversClass(IdentificacaoDaUnidade::class)]
final class MontarPainelDeUnidadesUseCaseTest extends TestCase
{
    #[TestDox('Votou, ainda não votou e inapta; as contagens fecham')]
    public function testEstados(): void
    {
        $tenant = new Tenant();
        $a = $this->assembleia($tenant);
        $pauta = $this->pauta($a);
        $votou = $this->unidade($a, 1, '101', 'A');
        $this->unidade($a, 2, '102', 'A');
        $this->unidade($a, 3, '103', 'A', 'inadimplente');

        $painel = $this->executar($tenant, $a, [new ParticipacaoNaPauta($votou, $pauta)]);

        self::assertSame(
            [['101', 'votou'], ['102', 'nao_votou'], ['103', 'inapta']],
            array_map(static fn (UnidadeNoPainel $u): array => [$u->identificacao, $u->situacao], $painel->unidades),
        );
        self::assertSame(1, $painel->contar('votou'));
        self::assertSame(1, $painel->contar('nao_votou'));
        self::assertSame(1, $painel->contar('inapta'));
        self::assertSame(2, $painel->aptos());
        self::assertSame('Pauta 1', $painel->tituloDaPauta);
        self::assertSame('AGO 2026', $painel->tituloDaAssembleia);
        self::assertSame('publicada', $painel->situacaoDaPauta);
        self::assertSame('normal', $painel->ciclo);
    }

    #[TestDox('Bloco fora dos grupos da pauta não entra')]
    public function testBlocoForaDaPautaNaoEntra(): void
    {
        $tenant = new Tenant();
        $a = $this->assembleia($tenant);
        $this->pauta($a, ['Bloco A']);
        $this->unidade($a, 1, '101', 'Bloco A');
        $this->unidade($a, 2, '201', 'Bloco B');

        $painel = $this->executar($tenant, $a, []);

        self::assertSame(['101'], array_map(static fn (UnidadeNoPainel $u): string => $u->identificacao, $painel->unidades));
    }

    #[TestDox('Ordem natural por bloco e depois identificação; o bloco só aparece quando a identificação se repete')]
    public function testOrdemEBlocoSoNaRepeticao(): void
    {
        $tenant = new Tenant();
        $a = $this->assembleia($tenant);
        $this->pauta($a);
        $this->unidade($a, 1, '10', 'B');
        $this->unidade($a, 2, '2', 'B');
        $this->unidade($a, 3, '1', 'A');
        $this->unidade($a, 4, '2', 'A');

        $painel = $this->executar($tenant, $a, []);

        self::assertSame(['1', 'A · 2', 'B · 2', '10'], array_map(static fn (UnidadeNoPainel $u): string => $u->identificacao, $painel->unidades));
    }

    #[TestDox('CPF de 11 dígitos sai mascarado, como na ata')]
    public function testCpfMascarado(): void
    {
        $tenant = new Tenant();
        $a = $this->assembleia($tenant, CenarioDaAssembleia::Associacao);
        $this->pauta($a);
        $this->unidade($a, 1, '12345678901', '');

        $painel = $this->executar($tenant, $a, []);

        self::assertSame('***.456.789-**', $painel->unidades[0]->identificacao);
    }

    #[TestDox('A regra de identificação compartilhada com a ata: CPF mascarado e bloco só quando repete')]
    public function testIdentificacaoDaUnidade(): void
    {
        $a = $this->assembleia(new Tenant());
        $u = $this->unidade($a, 1, '12345678901', 'A');
        $v = $this->unidade($a, 2, '101', 'Torre 1');
        $w = $this->unidade($a, 3, '102', '');

        self::assertSame('***.456.789-**', IdentificacaoDaUnidade::exibir($u, false));
        self::assertSame('A · ***.456.789-**', IdentificacaoDaUnidade::exibir($u, true));
        self::assertSame('101', IdentificacaoDaUnidade::exibir($v, false));
        self::assertSame('Torre 1 · 101', IdentificacaoDaUnidade::exibir($v, true));
        self::assertSame('102', IdentificacaoDaUnidade::exibir($w, true), 'sem bloco, nada na frente');
        self::assertSame(['101' => 2], IdentificacaoDaUnidade::repetidas([$v, $this->unidade($a, 4, '101', 'Torre 2'), $w]));
    }

    #[TestDox('Sem permissão para ver a relação: SemPermissaoParaVerAsUnidadesException (403)')]
    public function testSemPermissao(): void
    {
        $tenant = new Tenant();
        $a = $this->assembleia($tenant);
        $this->pauta($a);

        $this->expectException(SemPermissaoParaVerAsUnidadesException::class);
        $this->executar($tenant, $a, [], $this->pessoaSemPermissao());
    }

    #[TestDox('404: cenário sem lista, rascunho, arquivada, pauta retirada, pauta de outra assembleia e assembleia de outro escritório')]
    public function testNaoEncontrada(): void
    {
        $tenant = new Tenant();

        $semLista = $this->assembleia($tenant, CenarioDaAssembleia::Classe);
        $this->pauta($semLista);
        $this->assertNaoEncontrada($tenant, $semLista, 'cenário sem lista');

        $rascunho = $this->assembleia($tenant);
        $this->pauta($rascunho);
        (new \ReflectionProperty(Assembleia::class, 'estado'))->setValue($rascunho, EstadoDaAssembleia::Rascunho);
        $this->assertNaoEncontrada($tenant, $rascunho, 'rascunho');

        $arquivada = $this->assembleia($tenant);
        $this->pauta($arquivada);
        $arquivada->arquivar('Encerrada', 'Teste');
        $this->assertNaoEncontrada($tenant, $arquivada, 'arquivada');

        $comRetirada = $this->assembleia($tenant);
        $this->pauta($comRetirada)->retirarDePauta('Assunto resolvido antes', 'Mesa');
        $this->assertNaoEncontrada($tenant, $comRetirada, 'pauta retirada');

        $outra = $this->assembleia($tenant);
        $this->pauta($outra);
        $this->assertNaoEncontrada($tenant, $outra, 'pauta de outra assembleia', 999);

        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn(null);
        $this->expectException(AssembleiaNaoEncontradaException::class);
        (new MontarPainelDeUnidadesUseCase($repo, $this->createMock(UnidadeDaAssembleiaRepository::class), $this->createMock(ParticipacaoNaPautaRepository::class), $this->acesso()))
            ->executar($tenant, 7, 100, $this->pessoaMaster());
    }

    #[TestDox('A saída não carrega nome, voto nem hora: só identificação e situação')]
    public function testSemDadoSensivel(): void
    {
        $propriedades = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(UnidadeNoPainel::class))->getProperties());

        self::assertSame(['identificacao', 'situacao'], $propriedades);
    }

    private function assertNaoEncontrada(Tenant $tenant, Assembleia $a, string $caso, int $pautaId = 100): void
    {
        try {
            $this->executar($tenant, $a, [], null, $pautaId);
            self::fail('Deveria dar AssembleiaNaoEncontradaException: ' . $caso);
        } catch (AssembleiaNaoEncontradaException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assembleia(Tenant $tenant, CenarioDaAssembleia $cenario = CenarioDaAssembleia::Condominio): Assembleia
    {
        $a = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora', $cenario);
        (new \ReflectionProperty(Assembleia::class, 'id'))->setValue($a, 7);

        return $a;
    }

    /** @param list<string> $grupos */
    private function pauta(Assembleia $a, array $grupos = []): PautaDaAssembleia
    {
        $p = new PautaDaAssembleia($a, 1, 'Pauta 1');
        new OpcaoDaPauta($p, 1, 'Sim');
        (new \ReflectionProperty(PautaDaAssembleia::class, 'id'))->setValue($p, 100);
        if ([] !== $grupos) {
            $p->definirParticipacao(null, null, $grupos, OrdemDasCandidaturas::Fixa, SigiloDoVoto::Secreto);
        }
        (new \ReflectionProperty(Assembleia::class, 'estado'))->setValue($a, EstadoDaAssembleia::Publicada);

        return $p;
    }

    private function unidade(Assembleia $a, int $id, string $ident, string $bloco, ?string $situacao = null): UnidadeDaAssembleia
    {
        $u = new UnidadeDaAssembleia($a, $ident, str_repeat('a', 32), str_repeat('b', 64), $bloco, $situacao);
        (new \ReflectionProperty(UnidadeDaAssembleia::class, 'id'))->setValue($u, $id);

        return $u;
    }

    /** @param list<ParticipacaoNaPauta> $participacoes */
    private function executar(Tenant $tenant, Assembleia $a, array $participacoes, ?User $ator = null, int $pautaId = 100): PainelDeUnidadesDaPauta
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($a);
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('listarDaAssembleia')->willReturn(array_values($a->getUnidades()->toArray()));
        $part = $this->createMock(ParticipacaoNaPautaRepository::class);
        $part->method('listarNominalDaPauta')->willReturn($participacoes);

        return (new MontarPainelDeUnidadesUseCase($repo, $unidades, $part, $this->acesso()))
            ->executar($tenant, 7, $pautaId, $ator ?? $this->pessoaMaster());
    }

    /** A Administração (Master) vê a relação de qualquer assembleia do escritório. */
    private function pessoaMaster(): User
    {
        return (new User())->setRoles(['ROLE_SUPER_ADMIN']);
    }

    /** Sem vínculo de primário e fora da equipe própria da assembleia: não vê a relação. */
    private function pessoaSemPermissao(): User
    {
        return (new User())->setRoles(['ROLE_USER']);
    }

    private function acesso(): AcessoAAssembleia
    {
        $membros = $this->createMock(MembroDaEquipeRepository::class);
        $membros->method('linhasDoLote')->willReturn([['assembleia' => 7, 'user' => 999, 'papel' => 'comissao']]);

        return new AcessoAAssembleia(new RegraDosPrimarios($this->createMock(UserTenantRepository::class)), $membros, $this->createMock(AssembleiaRepository::class));
    }
}
