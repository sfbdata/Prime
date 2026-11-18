<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Integration;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Tarefa\DTO\AnalisarPrazoDaMetaInput;
use App\Tarefa\DTO\CriarMetaInput;
use App\Tarefa\DTO\MetaCriadaOutput;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\AnaliseDoPrazoRecusadaException;
use App\Tarefa\Exception\MetaNovaInvalidaException;
use App\Tarefa\Exception\SemAcessoAoModuloTarefasException;
use App\Tarefa\UseCase\CriarMetaUseCase;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A meta nova da pasta pela regra única que a tela e o conector usam. Contra o banco: o número da meta sai da
 * trava do escritório e o aviso só chega a quem vê a pasta, e isso não se prova com mock.
 */
#[CoversClass(CriarMetaUseCase::class)]
#[Group('tarefa')]
final class CriarMetaUseCaseTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const QUEM_CRIA = ['modules.tarefas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $quem;
    private User $colega;
    private Pasta $pasta;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->quem = $this->pessoa();
        $this->vinculo($this->quem, $this->escritorio, permissoes: self::QUEM_CRIA);
        $this->colega = $this->pessoa();
        $this->vinculo($this->colega, $this->escritorio, permissoes: self::QUEM_CRIA);
        $this->pasta = $this->pasta($this->escritorio);
    }

    #[TestDox('cria a meta na pasta com número, prazo, etapa e responsáveis, e avisa o responsável')]
    public function testCria(): void
    {
        $saida = $this->criar($this->pedido(responsaveis: [(int) $this->colega->getId()]));

        $meta = $this->em()->find(Tarefa::class, $saida->id);
        self::assertNotNull($meta);
        self::assertSame($this->pasta->getId(), $meta->getPasta()->getId());
        self::assertSame($this->escritorio->getId(), $meta->getTenant()?->getId());
        self::assertSame($this->quem->getId(), $meta->getCriadoPor()?->getId());
        self::assertSame(Tarefa::STATUS_PENDENTE, $meta->getStatus());
        self::assertNotNull($saida->numero, 'a meta sai numerada no escritório');
        self::assertSame('2026-12-01', $saida->prazo?->format('Y-m-d'));
        self::assertSame(EtapaDaPasta::Juridico, $saida->etapa);
        self::assertSame([(int) $this->colega->getId()], array_column($saida->responsaveis, 'id'));
        self::assertCount(1, $this->em()->getRepository(Notificacao::class)->findBy(['usuario' => $this->colega, 'tarefa' => $meta]));
    }

    #[TestDox('responsável de outro escritório fica de fora; sem nenhum do escritório, recusa')]
    public function testResponsavelSoDoEscritorio(): void
    {
        $outro = $this->escritorio('Outro');
        $deFora = $this->pessoa();
        $this->vinculo($deFora, $outro, permissoes: self::QUEM_CRIA);

        $saida = $this->criar($this->pedido(responsaveis: [(int) $this->colega->getId(), (int) $deFora->getId()]));
        self::assertSame([(int) $this->colega->getId()], array_column($saida->responsaveis, 'id'));

        $this->expectExceptionObject(new MetaNovaInvalidaException('Escolha ao menos um responsável do escritório para a meta.'));
        $this->criar($this->pedido(responsaveis: [(int) $deFora->getId()]));
    }

    /** @return iterable<string, array{CriarMetaInput, string}> */
    public static function pedidosInvalidos(): iterable
    {
        $prazo = new \DateTimeImmutable('2026-12-01');
        yield 'sem título' => [new CriarMetaInput('  ', 'Descrição', $prazo, [], EtapaDaPasta::Juridico), 'Título e descrição são obrigatórios.'];
        yield 'sem descrição' => [new CriarMetaInput('Título', '', $prazo, [], EtapaDaPasta::Juridico), 'Título e descrição são obrigatórios.'];
        yield 'sem prazo' => [new CriarMetaInput('Título', 'Descrição', null, [], EtapaDaPasta::Juridico), 'Informe um prazo válido para a meta.'];
        yield 'sem responsável' => [new CriarMetaInput('Título', 'Descrição', $prazo, [], EtapaDaPasta::Juridico), 'Escolha ao menos um responsável do escritório para a meta.'];
    }

    #[DataProvider('pedidosInvalidos')]
    #[TestDox('pedido incompleto recusa com a mensagem da tela e nada é gravado: $_dataName')]
    public function testPedidoInvalido(CriarMetaInput $pedido, string $mensagem): void
    {
        $this->assertRecusa(new MetaNovaInvalidaException($mensagem), $pedido);
    }

    #[TestDox('sem a etapa, recusa com a mensagem da tela')]
    public function testSemEtapa(): void
    {
        $pedido = new CriarMetaInput('Título', 'Descrição', new \DateTimeImmutable('2026-12-01'), [(int) $this->colega->getId()], null);

        $this->assertRecusa(new MetaNovaInvalidaException('Escolha a caixa / etapa da meta.'), $pedido);
    }

    #[TestDox('pasta de outro escritório: recusa como não encontrada, mesmo com responsável válido')]
    public function testPastaDeOutroEscritorio(): void
    {
        $this->pasta = $this->pasta($this->escritorio('Outro'));

        $this->assertRecusa(new PastaDeOutroEscritorioException('Pasta não encontrada.'), $this->pedido());
    }

    #[TestDox('quem tem o módulo mas não vê a pasta não cria meta nela')]
    public function testSemVerAPasta(): void
    {
        $this->quem = $this->pessoa();
        $this->vinculo($this->quem, $this->escritorio, permissoes: ['modules.tarefas.view']);

        $this->assertRecusa(new SemPermissaoParaVerPastaException('Sem permissão para acessar esta pasta.'), $this->pedido());
    }

    #[TestDox('sem o módulo Tarefas não cria meta, mesmo vendo a pasta')]
    public function testSemModuloTarefas(): void
    {
        $this->quem = $this->pessoa();
        $this->vinculo($this->quem, $this->escritorio, permissoes: ['resources.pasta.view']);

        $this->assertRecusa(new SemAcessoAoModuloTarefasException('Sem acesso ao módulo Tarefas.'), $this->pedido());
    }

    #[TestDox('pasta excluída (lápide): recusa com a mensagem da pasta somente leitura')]
    public function testPastaExcluida(): void
    {
        $this->pasta->marcarExcluida($this->quem, new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertRecusa(new MetaNovaInvalidaException(PastaSomenteLeituraListener::MENSAGEM), $this->pedido());
    }

    #[TestDox('análise do prazo só estimada (contestação com a ciência, sem prazo jurídico nem confirmar) recusa e nada é gravado')]
    public function testAnaliseEstimadaRecusa(): void
    {
        $analise = new AnalisarPrazoDaMetaInput('Contestação', 'Apresentar a contestação do réu', null, new \DateTimeImmutable('2026-10-19'));

        try {
            $this->criar($this->pedido(analise: $analise));
            self::fail('deveria recusar a análise estimada');
        } catch (AnaliseDoPrazoRecusadaException $e) {
            self::assertSame('Confirme a análise do prazo ou informe o prazo jurídico antes de criar a meta.', $e->getMessage());
        }
        self::assertSame(0, $this->metasDaPasta());
    }

    #[TestDox('análise com o prazo jurídico informado grava junto com a meta')]
    public function testAnaliseComPrazoJuridicoGrava(): void
    {
        $analise = new AnalisarPrazoDaMetaInput('Contestação', 'Prazo de 15 dias', new \DateTimeImmutable('2026-11-10'), null, new \DateTimeImmutable('2026-12-01'));

        $saida = $this->criar($this->pedido(analise: $analise));

        self::assertSame('2026-12-01', $this->em()->find(Tarefa::class, $saida->id)?->getPrazoJuridico()?->format('Y-m-d'));
    }

    /** @param list<int>|null $responsaveis */
    private function pedido(?array $responsaveis = null, ?AnalisarPrazoDaMetaInput $analise = null): CriarMetaInput
    {
        return new CriarMetaInput('Contestação', 'Prazo de 15 dias', new \DateTimeImmutable('2026-12-01'), $responsaveis ?? [(int) $this->colega->getId()], EtapaDaPasta::Juridico, $analise);
    }

    private function criar(CriarMetaInput $pedido): MetaCriadaOutput
    {
        return static::getContainer()->get(CriarMetaUseCase::class)->executar($this->pasta, $this->quem, $this->escritorio, $pedido, new \DateTimeImmutable('2026-11-18'));
    }

    private function assertRecusa(\DomainException $esperada, CriarMetaInput $pedido): void
    {
        try {
            $this->criar($pedido);
            self::fail('deveria recusar: ' . $esperada->getMessage());
        } catch (\DomainException $e) {
            self::assertInstanceOf($esperada::class, $e);
            self::assertSame($esperada->getMessage(), $e->getMessage());
        }
        self::assertSame(0, $this->metasDaPasta());
    }

    private function metasDaPasta(): int
    {
        return $this->em()->getRepository(Tarefa::class)->count(['pasta' => $this->pasta]);
    }

    private function pasta(Tenant $escritorio): Pasta
    {
        $pasta = (new Pasta())->setNup('META-' . uniqid());
        $pasta->setTenant($escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
