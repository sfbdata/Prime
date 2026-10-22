<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Tarefa\DTO\AguardandoNaMetaOutput;
use App\Tarefa\DTO\SituacaoDaMetaOutput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Enum\TipoDeDemanda;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\UseCase\MontarSituacaoDaMetaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Situação da meta (desenho 04B de 22/10, L50, L77, L85, L131-164; spec L2 a L4). */
#[CoversClass(MontarSituacaoDaMetaUseCase::class)]
#[CoversClass(SituacaoDaMetaOutput::class)]
#[CoversClass(AguardandoNaMetaOutput::class)]
#[Group('tarefa')]
final class MontarSituacaoDaMetaUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $criador;
    private Tarefa $meta;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->criador = (new User())->setFullName('FARLEI ASSIS DA ROCHA');
        $this->meta = new Tarefa();
        $this->meta->setTenant($this->escritorio);
        $this->meta->setCriadoPor($this->criador);
        $this->meta->addResponsavel((new User())->setFullName('thâmara thays silva carvalho dos santos'));
        $this->meta->addResponsavel((new User())->setFullName('Mariana'));
        $this->meta->setStatus(Tarefa::STATUS_PENDENTE);
        $this->meta->setEtapa(EtapaDaPasta::Juridico);
        $this->meta->setTipoDeDemanda(TipoDeDemanda::Judicial);
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($this->meta, new \DateTimeImmutable('2026-10-12 17:00'));
        $this->agora = new \DateTimeImmutable('2026-10-22 09:30');
    }

    #[TestDox('Pendente no prazo: faixa azul com os responsáveis "com a demanda" desde a criação; Etapa 5/8 · Jurídico')]
    public function testPendenteNoPrazo(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('2026-10-22'));

        $s = $this->executar();

        self::assertSame(SituacaoDaMetaOutput::PENDENTE, $s->estado);
        self::assertSame('bi-hourglass', $s->icone());
        self::assertFalse($s->atrasada);
        self::assertSame([5, 8, null], [$s->posicao, $s->total, $s->nomeDaEtapa]);
        self::assertNotNull($s->aguardando);
        self::assertFalse($s->aguardando->emRevisao);
        self::assertSame('despacho', $s->aguardando->tom());
        self::assertSame(10, $s->aguardando->dias, 'dias corridos pela data, sem a hora');
        self::assertSame([['iniciais' => 'TT', 'nome' => 'Thâmara Santos'], ['iniciais' => 'M', 'nome' => 'Mariana']], $s->aguardando->pessoas);
        self::assertSame(10, $s->diasDesdeACriacao);
        self::assertSame('FARLEI ASSIS DA ROCHA', $s->despachante);
    }

    #[TestDox('Prazo vencido: Atrasada com os dias de atraso, faixa vermelha e quadro do prazo vermelho')]
    public function testAtrasada(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('2026-10-19'));

        $s = $this->executar();

        self::assertSame(SituacaoDaMetaOutput::ATRASADA, $s->estado);
        self::assertSame('bi-exclamation-octagon', $s->icone());
        self::assertSame(3, $s->diasDeAtraso);
        self::assertTrue($s->atrasada);
        self::assertSame('atrasada', $s->aguardando?->tom());
    }

    #[TestDox('Em revisão: o painel diz Aguardando revisão; a faixa aguarda o criador desde o envio, âmbar mesmo vencida')]
    public function testEmRevisao(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('2026-10-19'));
        $this->meta->setStatus(Tarefa::STATUS_EM_REVISAO);
        $envio = (new TarefaMensagem())->setMensagem('Pronto.')->setAcao(AcaoDoDespacho::Envio)->setCriadoEm(new \DateTimeImmutable('2026-10-20 18:00'));
        $this->meta->addMensagem($envio);
        $this->meta->addMensagem((new TarefaMensagem())->setMensagem('Antes.')->setAcao(AcaoDoDespacho::Envio)->setCriadoEm(new \DateTimeImmutable('2026-10-14 08:00')));

        $s = $this->executar();

        self::assertSame(SituacaoDaMetaOutput::REVISAO, $s->estado);
        self::assertSame('bi-hourglass-split', $s->icone());
        self::assertTrue($s->atrasada, 'o quadro do prazo continua vermelho');
        self::assertNotNull($s->aguardando);
        self::assertTrue($s->aguardando->emRevisao);
        self::assertSame('revisao', $s->aguardando->tom());
        self::assertSame(2, $s->aguardando->dias, 'desde o envio mais recente');
        self::assertSame([['iniciais' => 'FA', 'nome' => 'Farlei Rocha']], $s->aguardando->pessoas);
    }

    #[TestDox('Concluída: sem faixa e sem atraso, mesmo com o prazo no passado')]
    public function testConcluida(): void
    {
        $this->meta->setPrazo(new \DateTimeImmutable('2026-10-01'));
        $this->meta->setStatus(Tarefa::STATUS_CONCLUIDA);

        $s = $this->executar();

        self::assertSame(SituacaoDaMetaOutput::CONCLUIDA, $s->estado);
        self::assertSame('bi-check2-circle', $s->icone());
        self::assertFalse($s->atrasada);
        self::assertNull($s->aguardando);
    }

    #[TestDox('Etapa: usa o nome que o escritório deu na caixa; fora da sequência do tipo, sem posição')]
    public function testEtapaComNomeDaCaixaEForaDaSequencia(): void
    {
        $caixa = new CaixaDaEsteira($this->escritorio, EtapaDaPasta::Acompanhamento);
        $caixa->configurar(null, 'Pós-protocolo', []);
        $this->meta->setEtapa(EtapaDaPasta::Acompanhamento);
        $this->meta->setTipoDeDemanda(TipoDeDemanda::Consultoria);

        $s = $this->executar($caixa);

        self::assertNull($s->posicao, 'consultoria não passa por Acompanhamento');
        self::assertSame(6, $s->total);
        self::assertSame('Pós-protocolo', $s->nomeDaEtapa);
    }

    #[TestDox('Sem responsáveis: sem faixa; sem prazo: não atrasa')]
    public function testSemResponsaveisESemPrazo(): void
    {
        $this->meta->getResponsaveis()->clear();

        $s = $this->executar();

        self::assertNull($s->aguardando);
        self::assertNull($s->prazo);
        self::assertSame(SituacaoDaMetaOutput::PENDENTE, $s->estado);
    }

    private function executar(?CaixaDaEsteira $caixa = null): SituacaoDaMetaOutput
    {
        $caixas = $this->createStub(CaixaDaEsteiraRepository::class);
        $caixas->method('daEtapa')->willReturn($caixa);

        return (new MontarSituacaoDaMetaUseCase($caixas))->executar($this->meta, $this->escritorio, $this->agora);
    }
}
