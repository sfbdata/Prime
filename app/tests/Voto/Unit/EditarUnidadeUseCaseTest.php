<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\LeitorDaListaDeUnidades;
use App\Voto\Service\SerializacaoDaConfiguracao;
use App\Voto\UseCase\EditarUnidadeUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Editar o cadastro da unidade (menu ⋮ da lista de links): valida como a importação, mantém o link e respeita a trava. */
#[CoversClass(EditarUnidadeUseCase::class)]
final class EditarUnidadeUseCaseTest extends TestCase
{
    private const CAMPOS = [
        'identificacao' => '102', 'bloco' => 'B', 'situacao' => '', 'fracao' => '', 'responsavel' => 'Maria',
        'email' => 'maria@exemplo.com', 'telefone' => '(11) 99999-0000',
    ];

    #[TestDox('Grava os campos limpos, mantém o link e recalcula a aptidão quando a situação muda')]
    public function testEdita(): void
    {
        [$tenant, $assembleia, $unidade] = $this->cenario();
        $campos = self::CAMPOS;
        $campos['situacao'] = 'inadimplente';

        $this->caso($tenant, $assembleia, $unidade, expectSalvar: true)->executar($tenant, 7, 5, $campos);

        self::assertSame('102', $unidade->getIdentificacao());
        self::assertSame('B', $unidade->getBloco());
        self::assertSame('maria@exemplo.com', $unidade->getEmail());
        self::assertSame('11999990000', $unidade->getTelefone());
        self::assertFalse($unidade->isApta());
        self::assertSame('semente-antiga', $unidade->getSemente(), 'o link não muda');
    }

    #[TestDox('Sem mudar a situação, a decisão do conselho (apta) continua valendo')]
    public function testMantemAptidaoSemMudarSituacao(): void
    {
        [$tenant, $assembleia, $unidade] = $this->cenario('inadimplente');
        $unidade->tornarAptaPeloConselho();
        $campos = self::CAMPOS;
        $campos['situacao'] = 'inadimplente';

        $this->caso($tenant, $assembleia, $unidade, expectSalvar: true)->executar($tenant, 7, 5, $campos);

        self::assertTrue($unidade->isApta());
    }

    #[TestDox('Liberada pelo conselho e com a situação trocada por outra que não vota: continua apta')]
    public function testMantemDecisaoDoConselhoAoTrocarSituacao(): void
    {
        [$tenant, $assembleia, $unidade] = $this->cenario('inadimplente');
        $unidade->tornarAptaPeloConselho();
        $campos = self::CAMPOS;
        $campos['situacao'] = 'em acordo';

        $this->caso($tenant, $assembleia, $unidade, expectSalvar: true)->executar($tenant, 7, 5, $campos);

        self::assertTrue($unidade->isApta());
        self::assertSame('em acordo', $unidade->getSituacao());
    }

    #[TestDox('Identificação igual a um rótulo de cabeçalho ("Unidade", "Matrícula") é gravada, não pulada')]
    public function testIdentificacaoComNomeDeCabecalho(): void
    {
        foreach (['Unidade', 'Matrícula'] as $nome) {
            [$tenant, $assembleia, $unidade] = $this->cenario();
            $campos = self::CAMPOS;
            $campos['identificacao'] = $nome;

            $this->caso($tenant, $assembleia, $unidade, expectSalvar: true)->executar($tenant, 7, 5, $campos);

            self::assertSame($nome, $unidade->getIdentificacao());
        }
    }

    #[TestDox('E-mail inválido, fração faltando no peso por fração e unidade repetida são recusados sem gravar')]
    public function testRecusaInvalidos(): void
    {
        [$tenant, $assembleia, $unidade] = $this->cenario();
        new UnidadeDaAssembleia($assembleia, '102', 's2', 'h2', 'B');
        foreach ([['email' => 'sem-arroba'], ['identificacao' => ''], ['identificacao' => '102', 'bloco' => 'B']] as $troca) {
            try {
                $this->caso($tenant, $assembleia, $unidade, expectSalvar: false)->executar($tenant, 7, 5, [...self::CAMPOS, ...$troca] + ['x' => '']);
                self::fail('devia recusar ' . json_encode($troca));
            } catch (OperacaoInvalidaNaAssembleiaException) {
                self::assertSame('101', $unidade->getIdentificacao());
            }
        }
    }

    #[TestDox('Depois da abertura da primeira pauta a edição é bloqueada')]
    public function testBloqueadaDepoisDaAbertura(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new OpcaoDaPauta($pauta, 0, 'Abstenção', true);
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 'semente-antiga', 'hash', 'A');
        $assembleia->publicar();
        $pauta->abrir('Mesa', 1, null);

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        $this->caso($tenant, $assembleia, $unidade, expectSalvar: false)->executar($tenant, 7, 5, self::CAMPOS);
    }

    #[TestDox('Unidade que não é da assembleia: não encontrada')]
    public function testUnidadeAlheia(): void
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->willReturn($assembleia);
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('buscarDaAssembleia')->willReturn(null);

        $this->expectException(AssembleiaNaoEncontradaException::class);
        (new EditarUnidadeUseCase($assembleias, $unidades, new LeitorDaListaDeUnidades(), self::serializacao()))->executar($tenant, 7, 5, self::CAMPOS);
    }

    /** @return array{Tenant, Assembleia, UnidadeDaAssembleia} */
    private function cenario(?string $situacao = null): array
    {
        $tenant = new Tenant();
        $assembleia = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $unidade = new UnidadeDaAssembleia($assembleia, '101', 'semente-antiga', 'hash-antigo', 'A', $situacao);

        return [$tenant, $assembleia, $unidade];
    }

    private function caso(Tenant $tenant, Assembleia $assembleia, UnidadeDaAssembleia $unidade, bool $expectSalvar): EditarUnidadeUseCase
    {
        $assembleias = $this->createMock(AssembleiaRepository::class);
        $assembleias->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($assembleia);
        $unidades = $this->createMock(UnidadeDaAssembleiaRepository::class);
        $unidades->method('buscarDaAssembleia')->with(5, $assembleia)->willReturn($unidade);
        $unidades->expects($expectSalvar ? self::once() : self::never())->method('salvar');

        return new EditarUnidadeUseCase($assembleias, $unidades, new LeitorDaListaDeUnidades(), self::serializacao());
    }

    private static function serializacao(): SerializacaoDaConfiguracao
    {
        return new SerializacaoDaConfiguracao(new LockFactory(new InMemoryStore()));
    }
}
