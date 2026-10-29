<?php

declare(strict_types=1);

namespace App\Tests\ChatIa\Unit;

use App\ChatIa\DTO\ConfirmarPropostaInput;
use App\ChatIa\DTO\ResultadoDoCadastro;
use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Enum\EstadoDaProposta;
use App\ChatIa\Enum\TipoDaProposta;
use App\ChatIa\Exception\CadastroJaExisteNaPropostaException;
use App\ChatIa\Exception\PastaSemelhanteNaPropostaException;
use App\ChatIa\Exception\PropostaEncerradaException;
use App\ChatIa\Exception\PropostaInvalidaException;
use App\ChatIa\Exception\PropostaNaoEncontradaException;
use App\ChatIa\Exception\SemPermissaoParaPropostaException;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\ExecutorDaProposta;
use App\ChatIa\Service\GuardaDasPropostas;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\ChatIa\Service\TransacaoDoChat;
use App\ChatIa\Service\ValidadorDaProposta;
use App\ChatIa\UseCase\CancelarPropostaDeCadastroUseCase;
use App\ChatIa\UseCase\ConfirmarPropostaDeCadastroUseCase;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePF;
use App\Cliente\Repository\ClientePFRepository;
use App\Cliente\Repository\ClientePJRepository;
use App\Cliente\Service\CamposDoCadastro;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Confirmar e Cancelar sem banco: as guardas na ordem da spec (posse, estado, permissão no clique, validação, duplicidade,
 * pasta parecida), a dupla confirmação e o que o executor recebe. O executor é um dublê: o que ele recebe é o que os
 * UseCases de Cliente e Pasta receberiam.
 */
#[CoversClass(ConfirmarPropostaDeCadastroUseCase::class)]
#[CoversClass(CancelarPropostaDeCadastroUseCase::class)]
final class UseCasesDaPropostaTest extends TestCase
{
    private Tenant $tenant;
    private User $eu;
    private User $colega;
    private MockClock $relogio;
    private bool $podeCliente = true;
    private bool $podePasta = true;
    private ?Cliente $existente = null;
    /** @var list<Pasta> */
    private array $parecidas = [];
    /** @var list<array{TipoDaProposta, array<string, mixed>, ?Cliente}> */
    private array $execucoes = [];
    /** @var array<int, PropostaDeCadastro> */
    private array $banco = [];

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        DefineId::em($this->tenant, 1);
        $this->eu = new User();
        DefineId::em($this->eu, 10);
        $this->colega = new User();
        DefineId::em($this->colega, 11);
        $this->relogio = new MockClock('2026-10-29 10:30:00');
        $this->podeCliente = $this->podePasta = true;
        $this->existente = null;
        $this->parecidas = [];
        $this->execucoes = [];
        $this->banco = [];
    }

    /** @param array<string, mixed> $dados */
    private function proposta(int $id, TipoDaProposta $tipo, array $dados, ?User $dono = null, string $criada = '2026-10-29 10:00:00'): PropostaDeCadastro
    {
        $dono ??= $this->eu;
        $criadaEm = new \DateTimeImmutable($criada);
        $proposta = new PropostaDeCadastro($this->tenant, $dono, new ConversaDeChat($this->tenant, $dono, ModeloDeIa::PADRAO, $criadaEm), $tipo, $dados, $criadaEm);
        DefineId::em($proposta, $id);
        $this->banco[$id] = $proposta;

        return $proposta;
    }

    /** @return array<string, mixed> */
    private function dadosDeCliente(): array
    {
        return ['tipoPessoa' => 'pf', 'campos' => ['nome' => 'Maria da Silva', 'cpf' => '529.982.247-25']];
    }

    /** @return array<string, mixed> */
    private function dadosDePasta(): array
    {
        return ['nomeCliente' => 'Maria da Silva', 'nomeAcao' => 'Divórcio', 'nup' => '', 'cliente' => ['tipoPessoa' => 'pf', 'campos' => ['nome' => 'Maria da Silva', 'cpf' => '529.982.247-25']]];
    }

    private function repositorio(): PropostaDeCadastroRepository
    {
        $repo = $this->createStub(PropostaDeCadastroRepository::class);
        $acha = function (int $id, Tenant $tenant, User $dono): ?PropostaDeCadastro {
            $p = $this->banco[$id] ?? null;

            return $p !== null && $p->getTenant() === $tenant && $p->getUsuario() === $dono ? $p : null;
        };
        $repo->method('doDono')->willReturnCallback($acha);
        $repo->method('doDonoComTrava')->willReturnCallback($acha);

        return $repo;
    }

    private function transacao(): TransacaoDoChat
    {
        return new class () implements TransacaoDoChat {
            public function executar(callable $trabalho): mixed
            {
                return $trabalho();
            }
        };
    }

    private function acesso(): AcessoAoChat
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturn(true);
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAccessModule')->willReturn(true);

        return new AcessoAoChat($vinculos, $permissoes, $this->createStub(ConversaDeChatRepository::class));
    }

    private function confirmar(): ConfirmarPropostaDeCadastroUseCase
    {
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('hasPermission')->willReturnCallback(fn (): bool => $this->podeCliente);
        $permissoes->method('canAccessModule')->willReturnCallback(fn (): bool => $this->podePasta);
        $pf = $this->createStub(ClientePFRepository::class);
        $pf->method('findOneByCpfDoTenant')->willReturnCallback(fn (): ?ClientePF => $this->existente instanceof ClientePF ? $this->existente : null);
        $validador = new ValidadorDaProposta(new CamposDoCadastro($pf, $this->createStub(ClientePJRepository::class)));
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findSemelhantesPorClienteEAcao')->willReturnCallback(fn (): array => $this->parecidas);
        $pastas->method('contarSemelhantesPorClienteEAcao')->willReturnCallback(fn (): int => \count($this->parecidas));
        $executor = new class ($this) implements ExecutorDaProposta {
            public function __construct(private readonly UseCasesDaPropostaTest $teste)
            {
            }

            public function executar(TipoDaProposta $tipo, array $dados, User $eu, Tenant $tenant, ?Cliente $existente): ResultadoDoCadastro
            {
                $this->teste->registrarExecucao($tipo, $dados, $existente);

                return new ResultadoDoCadastro($tipo->value, 321);
            }
        };

        return new ConfirmarPropostaDeCadastroUseCase(
            $this->acesso(),
            $this->repositorio(),
            new GuardaDasPropostas($permissoes),
            $validador,
            $executor,
            $pastas,
            new MontadorDoCartaoDaProposta($validador, $pastas),
            $this->transacao(),
            $this->relogio,
        );
    }

    /** @param array<string, mixed> $dados */
    public function registrarExecucao(TipoDaProposta $tipo, array $dados, ?Cliente $existente): void
    {
        $this->execucoes[] = [$tipo, $dados, $existente];
    }

    private function cancelar(): CancelarPropostaDeCadastroUseCase
    {
        $pastas = $this->createStub(PastaRepository::class);
        $pf = $this->createStub(ClientePFRepository::class);

        return new CancelarPropostaDeCadastroUseCase(
            $this->acesso(),
            $this->repositorio(),
            new MontadorDoCartaoDaProposta(new ValidadorDaProposta(new CamposDoCadastro($pf, $this->createStub(ClientePJRepository::class))), $pastas),
            $this->transacao(),
            $this->relogio,
        );
    }

    private function existente(int $id = 77): ClientePF
    {
        $c = new ClientePF();
        $c->setNomeCompleto('Maria Antiga');
        DefineId::em($c, $id);

        return $c;
    }

    #[TestDox('Confirmar cliente: executa uma vez com os dados da proposta, marca confirmada com tipo e id, e zera os dados')]
    public function testConfirmaCliente(): void
    {
        $proposta = $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());

        $confirmacao = $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());

        self::assertCount(1, $this->execucoes);
        self::assertSame(TipoDaProposta::Cliente, $this->execucoes[0][0]);
        self::assertSame($this->dadosDeCliente(), $this->execucoes[0][1]);
        self::assertSame(EstadoDaProposta::Confirmada, $proposta->getEstado());
        self::assertSame('cliente', $proposta->getResultadoTipo());
        self::assertSame(321, $proposta->getResultadoId());
        self::assertNull($proposta->getDados());
        self::assertSame(['tipo' => 'cliente', 'id' => 321], $confirmacao->proposta->resultado);
        self::assertArrayNotHasKey('campos', $confirmacao->proposta->paraArray(), 'depois de confirmada o cartão não leva dado pessoal');
    }

    #[TestDox('Proposta alheia, de outro escritório ou inexistente: o mesmo 404, e nada executa')]
    public function testPosse(): void
    {
        $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente(), $this->colega);

        foreach ([5, 99, 0] as $id) {
            try {
                $this->confirmar()->executar($this->eu, $this->tenant, $id, new ConfirmarPropostaInput());
                self::fail('Deveria ser 404.');
            } catch (PropostaNaoEncontradaException) {
                self::addToAssertionCount(1);
            }
        }
        $outroEscritorio = new Tenant();
        DefineId::em($outroEscritorio, 2);
        $this->expectException(PropostaNaoEncontradaException::class);
        try {
            $this->confirmar()->executar($this->colega, $outroEscritorio, 5, new ConfirmarPropostaInput());
        } finally {
            self::assertSame([], $this->execucoes);
        }
    }

    #[TestDox('Vencida (24 h): 409, vira expirada, perde os dados e nada executa')]
    public function testVencida(): void
    {
        $proposta = $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente(), null, '2026-10-27 09:00:00');

        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
            self::fail('Deveria ser 409.');
        } catch (PropostaEncerradaException $e) {
            self::assertSame('proposta_vencida', $e->codigo());
        }

        self::assertSame(EstadoDaProposta::Expirada, $proposta->getEstado());
        self::assertNull($proposta->getDados());
        self::assertSame([], $this->execucoes);
    }

    #[TestDox('Dupla confirmação: a segunda recebe 409 e o cadastro é gravado uma única vez')]
    public function testDuplaConfirmacao(): void
    {
        $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());
        $caso = $this->confirmar();
        $caso->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());

        try {
            $caso->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
            self::fail('Deveria ser 409.');
        } catch (PropostaEncerradaException $e) {
            self::assertSame('proposta_encerrada', $e->codigo());
        }

        self::assertCount(1, $this->execucoes);
    }

    #[TestDox('Cancelada não confirma: 409 e nada executa')]
    public function testCanceladaNaoConfirma(): void
    {
        $proposta = $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());
        $cartao = $this->cancelar()->executar($this->eu, $this->tenant, 5);
        self::assertSame('cancelada', $cartao->estado);
        self::assertNull($proposta->getDados());

        $this->expectException(PropostaEncerradaException::class);
        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
        } finally {
            self::assertSame([], $this->execucoes);
        }
    }

    #[TestDox('Cancelar: só o dono (colega recebe 404), uma vez só (a segunda é 409) e sem executar nada')]
    public function testCancelar(): void
    {
        $proposta = $this->proposta(5, TipoDaProposta::Pasta, $this->dadosDePasta());
        try {
            $this->cancelar()->executar($this->colega, $this->tenant, 5);
            self::fail('Deveria ser 404.');
        } catch (PropostaNaoEncontradaException) {
            self::assertTrue($proposta->estaPendente());
        }

        $this->cancelar()->executar($this->eu, $this->tenant, 5);
        self::assertSame(EstadoDaProposta::Cancelada, $proposta->getEstado());
        self::assertSame([], $this->execucoes);
        $this->expectException(PropostaEncerradaException::class);
        $this->cancelar()->executar($this->eu, $this->tenant, 5);
    }

    #[TestDox('Permissão no clique: quem perdeu a permissão depois da proposta recebe 403 e a proposta segue pendente')]
    public function testPermissaoNoClique(): void
    {
        $proposta = $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());
        $this->podeCliente = false;

        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
            self::fail('Deveria ser 403.');
        } catch (SemPermissaoParaPropostaException) {
            self::assertTrue($proposta->estaPendente());
            self::assertSame([], $this->execucoes);
        }

        $pasta = $this->proposta(6, TipoDaProposta::Pasta, $this->dadosDePasta());
        $this->podePasta = false;
        $this->expectException(SemPermissaoParaPropostaException::class);
        $this->confirmar()->executar($this->eu, $this->tenant, 6, new ConfirmarPropostaInput());
        self::assertTrue($pasta->estaPendente());
    }

    #[TestDox('O que a pessoa editou no cartão vale (revalidado): campo trocado vai ao executor; CPF inválido volta 422 com o campo')]
    public function testEdicaoDoCartao(): void
    {
        $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());
        $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput(['nome' => 'Maria S. Silva', 'profissao' => 'Advogada']));
        self::assertSame(['nome' => 'Maria S. Silva', 'profissao' => 'Advogada'], $this->execucoes[0][1]['campos'], 'os campos enviados trocam todos os da proposta');

        $this->proposta(6, TipoDaProposta::Cliente, $this->dadosDeCliente());
        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 6, new ConfirmarPropostaInput(['nome' => 'Maria', 'cpf' => '111.111.111-11']));
            self::fail('Deveria ser 422.');
        } catch (PropostaInvalidaException $e) {
            self::assertSame(['campos.cpf'], array_keys($e->erros));
            self::assertTrue($this->banco[6]->estaPendente());
            self::assertCount(1, $this->execucoes, 'a inválida não executou');
        }
    }

    #[TestDox('Campo fora da lista no clique (ou de PJ numa PF) é 422 e não executa')]
    public function testCampoForaDaListaNoClique(): void
    {
        $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());

        foreach ([['tenant_id' => '9'], ['cnpj' => '11.222.333/0001-81']] as $campos) {
            try {
                $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput($campos));
                self::fail('Deveria ser 422.');
            } catch (PropostaInvalidaException) {
                self::assertSame([], $this->execucoes);
            }
        }
    }

    #[TestDox('CPF de cadastro existente: 409 duplicada com o existente; na pasta, `usarExistente` liga o existente e dispensa os campos do cliente')]
    public function testDuplicidade(): void
    {
        $this->existente = $this->existente();
        $this->proposta(5, TipoDaProposta::Cliente, $this->dadosDeCliente());
        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
            self::fail('Deveria ser 409.');
        } catch (CadastroJaExisteNaPropostaException $e) {
            self::assertSame('duplicada', $e->codigo());
            self::assertSame(['existente' => ['id' => 77, 'nome' => 'MARIA ANTIGA', 'documento' => 'CPF'], 'podeUsarExistente' => false], $e->dados());
            self::assertSame([], $this->execucoes);
        }

        $this->proposta(6, TipoDaProposta::Pasta, $this->dadosDePasta());
        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 6, new ConfirmarPropostaInput());
            self::fail('Deveria ser 409.');
        } catch (CadastroJaExisteNaPropostaException $e) {
            self::assertTrue($e->podeUsarExistente);
        }

        $this->confirmar()->executar($this->eu, $this->tenant, 6, new ConfirmarPropostaInput(usarExistente: true));
        self::assertCount(1, $this->execucoes);
        self::assertSame($this->existente, $this->execucoes[0][2]);
    }

    #[TestDox('`usarExistente` sem existente de verdade é 422; em proposta de cliente não vale (continua 409 duplicada)')]
    public function testUsarExistenteSoVale(): void
    {
        $this->proposta(5, TipoDaProposta::Pasta, $this->dadosDePasta());
        $this->expectException(PropostaInvalidaException::class);
        $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput(usarExistente: true));
    }

    #[TestDox('Pasta parecida: 409 pasta_semelhante com a lista e o total; `criarMesmoAssim` segue e executa')]
    public function testPastaSemelhante(): void
    {
        $outra = new Pasta();
        $outra->setNup('0042')->setNomeCliente('Maria da Silva')->setNomeAcao('Divórcio');
        DefineId::em($outra, 42);
        $this->parecidas = [$outra];
        $this->proposta(5, TipoDaProposta::Pasta, $this->dadosDePasta());

        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput());
            self::fail('Deveria ser 409.');
        } catch (PastaSemelhanteNaPropostaException $e) {
            self::assertSame(1, $e->total);
            self::assertSame([['id' => 42, 'nup' => '0042', 'nomeCliente' => 'MARIA DA SILVA', 'nomeAcao' => 'DIVÓRCIO']], $e->semelhantes);
            self::assertSame([], $this->execucoes);
        }

        $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput(criarMesmoAssim: true));

        self::assertCount(1, $this->execucoes);
        self::assertSame(TipoDaProposta::Pasta, $this->execucoes[0][0]);
    }

    #[TestDox('Editar pasta: nome e ação do clique valem, e vazio vira 422 com o campo da pasta')]
    public function testEdicaoDaPasta(): void
    {
        $this->proposta(5, TipoDaProposta::Pasta, $this->dadosDePasta());
        $this->confirmar()->executar($this->eu, $this->tenant, 5, new ConfirmarPropostaInput(pasta: ['nomeAcao' => 'Divórcio consensual']));
        self::assertSame('Divórcio consensual', $this->execucoes[0][1]['nomeAcao']);

        $this->proposta(6, TipoDaProposta::Pasta, $this->dadosDePasta());
        try {
            $this->confirmar()->executar($this->eu, $this->tenant, 6, new ConfirmarPropostaInput(pasta: ['nomeCliente' => '  ']));
            self::fail('Deveria ser 422.');
        } catch (PropostaInvalidaException $e) {
            self::assertSame(['pasta.nomeCliente'], array_keys($e->erros));
        }
    }
}
