<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Agenda\Service\CalendarioDoSistema;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\DiasUteisDoEscritorio;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\NomesDasEtapas;
use App\Tarefa\Service\QuemCuidaDaMeta;
use App\Tarefa\Service\RotaDaPassagem;
use App\Tarefa\UseCase\MontarEtapaDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Trilho "ETAPA DA PASTA", ações da lateral e dados do modal (desenho 04B L43-44, L78-82, L138; 04 L195-197).
 */
#[CoversClass(MontarEtapaDaMetaUseCase::class)]
#[CoversClass(RotaDaPassagem::class)]
#[Group('tarefa')]
final class MontarEtapaDaMetaUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $criador;
    private User $responsavel;
    private Tarefa $meta;
    /** @var array<int, string|null> foto do perfil por usuário, como o `UserRepository` devolve */
    private array $fotos = [];

    protected function setUp(): void
    {
        $this->escritorio = self::comId((new Tenant())->setEstado('AL'), 7);
        $this->criador = self::pessoa(1, 'THÂMARA THAYS SILVA CARVALHO');
        $this->responsavel = self::pessoa(2, 'Rui Prado');
        $pasta = self::comId((new Pasta())->setNup('828')->setTenant($this->escritorio), 40);
        $this->meta = self::comId(new Tarefa(), 30)
            ->setTitulo('Análise de pasta')
            ->setDescricao('')
            ->setPasta($pasta)
            ->setTenant($this->escritorio)
            ->setCriadoPor($this->criador)
            ->setStatus(Tarefa::STATUS_PENDENTE)
            ->setEtapa(EtapaDaPasta::Juridico)
            ->setTipoDeDemanda(TipoDeDemanda::Judicial)
            ->setEtapasSaltadas([EtapaDaPasta::Documentacao]);
        $this->meta->addResponsavel($this->responsavel);
    }

    #[TestDox('Trilho: passadas, saltada, atual, próxima e adiante, com ícone e dica do desenho, e a linha das saltadas')]
    public function testTrilho(): void
    {
        $saida = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio);

        self::assertSame(
            ['passada', 'passada', 'passada', 'saltada', 'atual', 'proxima', 'adiante', 'adiante'],
            array_column($saida->trilho, 'estado'),
        );
        self::assertSame(
            ['bi-check-lg', 'bi-check-lg', 'bi-check-lg', 'bi-skip-forward', 'bi-record-circle', 'bi-arrow-right', 'bi-activity', 'bi-check2-circle'],
            array_column($saida->trilho, 'icone'),
        );
        self::assertSame('Documentação · Secretaria · etapa saltada, sem registro', $saida->trilho[3]['dica']);
        self::assertSame('Comercial · metas.etapa.comercial.setor · concluída', $saida->trilho[0]['dica']);
        self::assertSame(['Documentação'], $saida->saltadas);
        self::assertSame('Protocolo', $saida->proxima);
    }

    #[TestDox('D3 na lateral: o responsável conclui e devolve; o criador só conclui; "Devolver para" com dois nomes')]
    public function testAcoesDaLateral(): void
    {
        $responsavel = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio);
        self::assertTrue($responsavel->podeConcluir);
        self::assertTrue($responsavel->podeDevolver);
        self::assertSame('Thâmara Thays', $responsavel->devolverPara);

        $criador = $this->useCase()->executar($this->meta, $this->criador, $this->escritorio);
        self::assertTrue($criador->podeConcluir);
        self::assertFalse($criador->podeDevolver);

        $outro = $this->useCase()->executar($this->meta, self::pessoa(9, 'Fora'), $this->escritorio);
        self::assertFalse($outro->podeConcluir);
        self::assertFalse($outro->podeDevolver);
    }

    #[TestDox('Meta concluída: sem Concluir, sem Devolver e sem próxima (spec 2.2); em revisão, sem Devolver')]
    public function testConcluidaEEmRevisao(): void
    {
        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        $saida = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio);
        self::assertFalse($saida->podeConcluir);
        self::assertFalse($saida->podeDevolver);
        self::assertNull($saida->proxima);

        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        $saida = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio);
        self::assertTrue($saida->podeConcluir);
        self::assertFalse($saida->podeDevolver);
    }

    #[TestDox('Dados do modal: o cliente da pasta judicializada é o da carteira, não o texto composto gravado')]
    public function testClienteDoModalEODerivado(): void
    {
        $this->meta->getPasta()->setNomeCliente('CREDOR - DEVEDOR');

        $dados = $this->useCase([40 => ['fantasia' => 'CREDOR', 'razaoSocial' => 'CREDOR S.A.']])->dadosDoModal($this->meta, $this->escritorio, new \DateTimeImmutable('2026-10-22 15:00'));

        self::assertSame('CREDOR', $dados['meta']['cliente']);
    }

    #[TestDox('Dados do modal: etapas, tipos com sequência, equipe com iniciais e função, prazo padrão de 2 dias úteis')]
    public function testDadosDoModal(): void
    {
        $dados = $this->useCase()->dadosDoModal($this->meta, $this->escritorio, new \DateTimeImmutable('2026-10-22 15:00'));

        self::assertSame(['id' => 30, 'titulo' => 'Análise de pasta', 'pasta' => '828', 'cliente' => null, 'etapa' => 'juridico', 'tipo' => 'judicial'], $dados['meta']);
        self::assertSame('Documentação', $dados['etapas']['documentacao']['nome']);
        self::assertSame(4, $dados['etapas']['documentacao']['posicao']);
        self::assertSame(['comercial', 'abertura', 'controladoria', 'juridico', 'protocolo', 'encerramento'], $dados['tipos'][2]['sequencia']);
        self::assertSame([['id' => 1, 'nome' => 'Thâmara Thays Silva Carvalho', 'iniciais' => 'TT', 'funcao' => 'Secretária', 'foto' => null]], $dados['membros']);
        self::assertSame('2026-10-26', $dados['prazo']);
    }

    #[TestDox('Foto no seletor (04 L195): o endereço do avatar do sistema quando há foto; sem ela, null e o JS mostra as iniciais')]
    public function testFotoDosMembros(): void
    {
        $this->fotos = [1 => 'abc123.jpg'];

        $dados = $this->useCase()->dadosDoModal($this->meta, $this->escritorio, new \DateTimeImmutable('2026-10-22 15:00'));

        self::assertSame('/app_profile_foto_serve/abc123.jpg', $dados['membros'][0]['foto']);
        self::assertSame('TT', $dados['membros'][0]['iniciais']);
    }

    #[TestDox('Pasta na lápide (excluída): sem Concluir nem Devolver, mesmo para o responsável')]
    public function testLapide(): void
    {
        $this->meta->getPasta()->marcarExcluida($this->criador, new \DateTimeImmutable('2026-10-20'));

        $saida = $this->useCase()->executar($this->meta, $this->responsavel, $this->escritorio);

        self::assertFalse($saida->podeConcluir);
        self::assertFalse($saida->podeDevolver);
    }

    #[TestDox('podeMexerNaEtapa (a regra que o desfazer da auditoria também chama): aberta e com a pasta viva, sim; concluída ou na lápide, não')]
    public function testPodeMexerNaEtapa(): void
    {
        self::assertTrue(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta));

        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        self::assertTrue(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta));

        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);
        self::assertFalse(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta));

        // O status de depois (o do desfazer) vence o atual.
        self::assertTrue(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta, Tarefa::STATUS_PENDENTE));
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        self::assertFalse(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta, Tarefa::STATUS_CONCLUIDA));

        $this->meta->getPasta()->marcarExcluida($this->criador, new \DateTimeImmutable('2026-10-20'));
        self::assertFalse(MontarEtapaDaMetaUseCase::podeMexerNaEtapa($this->meta));
    }

    #[TestDox('Rota: próxima pela posição, mesmo com a etapa fora da sequência do tipo')]
    public function testRota(): void
    {
        self::assertSame(EtapaDaPasta::Juridico, RotaDaPassagem::proximaPadrao(TipoDeDemanda::Consultoria, EtapaDaPasta::Documentacao));
        self::assertNull(RotaDaPassagem::proximaPadrao(TipoDeDemanda::Judicial, EtapaDaPasta::Encerramento));
        self::assertSame(
            [EtapaDaPasta::Juridico],
            RotaDaPassagem::saltadas([], TipoDeDemanda::Consultoria, EtapaDaPasta::Controladoria, EtapaDaPasta::Protocolo),
        );
    }

    /** @param array<int, array{fantasia: ?string, razaoSocial: ?string}> $daCarteira cliente da carteira por id de pasta judicial */
    private function useCase(array $daCarteira = []): MontarEtapaDaMetaUseCase
    {
        $caixas = $this->createStub(CaixaDaEsteiraRepository::class);
        $caixas->method('configuradasDoEscritorio')->willReturn([]);
        $casos = $this->createStub(\App\Cobranca\Repository\CasoCobrancaRepository::class);
        $casos->method('clienteDaCarteiraPorPasta')->willReturn($daCarteira);
        $vinculo = new UserTenant($this->criador, $this->escritorio);
        $vinculo->setCargo((new Cargo())->setNome('Secretária'));
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivosComCargoELotacaoPorTenant')->willReturn([$vinculo]);
        $tradutor = PassarEtapaDaMetaUseCaseTest::tradutor();
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findFotoPorColaboradores')->willReturnCallback(fn (): array => $this->fotos);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $rota, array $p = []): string => '/' . $rota . '/' . $p['nome']);

        return new MontarEtapaDaMetaUseCase(
            new NomesDasEtapas($caixas, $tradutor),
            new QuemCuidaDaMeta(),
            $vinculos,
            new DiasUteisDoEscritorio(new CalendarioDoSistema()),
            new class ($tradutor) implements \Symfony\Contracts\Translation\TranslatorInterface {
                public function __construct(private readonly \Symfony\Contracts\Translation\TranslatorInterface $base)
                {
                }

                public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
                {
                    return match ($id) {
                        'metas.passagem.trilho.dica_saltada' => 'etapa saltada, sem registro',
                        'metas.passagem.trilho.dica_concluida' => 'concluída',
                        default => $this->base->trans($id),
                    };
                }

                public function getLocale(): string
                {
                    return 'pt_BR';
                }
            },
            $usuarios,
            $urls,
            new \App\Cobranca\Service\ResolvedorIdentificadorDaPasta($casos, new \App\Cobranca\Service\ComporNomeDaPastaJudicial()),
        );
    }

    private static function pessoa(int $id, string $nome): User
    {
        return self::comId((new User())->setEmail('p' . $id . '@test.com')->setFullName($nome), $id);
    }

    /**
     * @template T of object
     * @param T $entidade
     * @return T
     */
    private static function comId(object $entidade, int $id): object
    {
        (new \ReflectionProperty($entidade::class, 'id'))->setValue($entidade, $id);

        return $entidade;
    }
}
