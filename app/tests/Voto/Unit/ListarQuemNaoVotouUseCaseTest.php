<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\QuemNaoVotouOutput;
use App\Voto\DTO\UnidadeSemVoto;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\ParticipacaoNaPautaRepository;
use App\Voto\Service\EnderecoPublicoDaAssembleia;
use App\Voto\UseCase\ListarQuemNaoVotouUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Lembrar quem não votou" (desenho: naoVotaram e o teste "Lembrete lista só aptos que ainda não votaram"): só apto sem
 * participação, em ordem de bloco e identificação, sem nenhum dado de escolha.
 */
#[CoversClass(ListarQuemNaoVotouUseCase::class)]
#[CoversClass(QuemNaoVotouOutput::class)]
#[CoversClass(UnidadeSemVoto::class)]
final class ListarQuemNaoVotouUseCaseTest extends TestCase
{
    #[TestDox('Lista só os aptos sem participação na pauta aberta, por bloco e identificação, com o telefone')]
    public function testSoAptosQueNaoVotaram(): void
    {
        $tenant = new Tenant();
        $a = $this->publicada($tenant);
        $pauta = $this->pauta($a, 1);
        $this->unidade($a, 1, '10', 'A', null, 'Ana Maria', '61999990001');
        $this->unidade($a, 2, '2', 'A', null, null, null);
        $this->unidade($a, 3, '3', 'A', 'inadimplente', 'Inapto', '61999990003');
        $this->unidade($a, 4, '4', 'A', null, 'Votou', '61999990004');
        $this->unidade($a, 5, '1', 'B', null, 'Beto', null);
        $pauta->abrir('Mesa', 1, null);

        $r = $this->executar($tenant, $a, [['unidadeId' => 4, 'pautaId' => 100, 'pauta' => 'P', 'registradaEm' => new \DateTimeImmutable()]]);

        self::assertSame(['2', '10', '1'], array_map(static fn (UnidadeSemVoto $u): string => $u->identificacao, $r->unidades), 'ordem natural por bloco e identificação');
        self::assertSame(['A', 'A', 'B'], array_map(static fn (UnidadeSemVoto $u): string => $u->bloco, $r->unidades));
        self::assertSame('61999990001', $r->unidades[1]->telefone);
        self::assertNull($r->unidades[0]->telefone);
        self::assertSame(1, $r->comTelefone());
        self::assertSame('Ana', $r->unidades[1]->primeiroNome());
        self::assertSame('https://bluejus.test/voto/v/' . $a->getCodigoPublico(), $r->link);
        self::assertNotContains('3', array_map(static fn (UnidadeSemVoto $u): string => $u->identificacao, $r->unidades), 'inapta fora');
        self::assertNotContains('4', array_map(static fn (UnidadeSemVoto $u): string => $u->identificacao, $r->unidades), 'quem votou fora');
    }

    #[TestDox('Sem pauta aberta, vale a pauta que aguarda a mesa; pauta encerrada não conta')]
    public function testPautaAguardandoEEncerrada(): void
    {
        $tenant = new Tenant();
        $a = $this->publicada($tenant);
        $this->pauta($a, 1);
        $this->unidade($a, 1, '101', 'A', null, null, null);

        self::assertCount(1, $this->executar($tenant, $a, [])->unidades, 'aguardando: ninguém votou ainda');

        $a->getPautas()->first()->abrir('Mesa', 1, null);
        $a->getPautas()->first()->encerrar('Mesa', 'Fim');
        self::assertCount(0, $this->executar($tenant, $a, [])->unidades, 'só pauta encerrada: nada a lembrar');
    }

    #[TestDox('Pauta retirada da ordem do dia não conta como pauta a votar')]
    public function testPautaRetiradaNaoConta(): void
    {
        $tenant = new Tenant();
        $a = $this->publicada($tenant);
        $this->pauta($a, 1);
        $this->unidade($a, 1, '101', 'A', null, null, null);

        $a->getPautas()->first()->retirarDePauta('Assunto resolvido antes', 'Mesa');

        self::assertCount(0, $this->executar($tenant, $a, [])->unidades);
    }

    #[TestDox('Com duas pautas abertas, entra quem falta em qualquer uma; quem votou nas duas sai')]
    public function testDuasPautasAbertas(): void
    {
        $tenant = new Tenant();
        $a = $this->publicada($tenant);
        $p1 = $this->pauta($a, 1);
        $p2 = $this->pauta($a, 2);
        $this->unidade($a, 1, '101', 'A', null, null, null);
        $this->unidade($a, 2, '102', 'A', null, null, null);
        $p1->abrir('Mesa', 1, null);
        $p2->abrir('Mesa', 1, null);
        $agora = new \DateTimeImmutable();

        $r = $this->executar($tenant, $a, [
            ['unidadeId' => 1, 'pautaId' => 100, 'pauta' => 'P1', 'registradaEm' => $agora],
            ['unidadeId' => 1, 'pautaId' => 101, 'pauta' => 'P2', 'registradaEm' => $agora],
            ['unidadeId' => 2, 'pautaId' => 100, 'pauta' => 'P1', 'registradaEm' => $agora],
        ]);

        self::assertSame(['102'], array_map(static fn (UnidadeSemVoto $u): string => $u->identificacao, $r->unidades));
    }

    #[TestDox('A saída não carrega voto nem opção: só identificação, bloco, nome e telefone')]
    public function testSemDadoDeEscolha(): void
    {
        $propriedades = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(UnidadeSemVoto::class))->getProperties());

        self::assertSame(['identificacao', 'bloco', 'responsavel', 'telefone'], $propriedades);
    }

    #[TestDox('CSV do desenho: cabeçalho, campos entre aspas, só quem não votou; fórmula vira texto')]
    public function testCsv(): void
    {
        $r = new QuemNaoVotouOutput(7, 'AGO', 'https://x', 'ABCD-EFGH', [
            new UnidadeSemVoto('101', 'A', 'Ana "Maria"', '61999990001'),
            new UnidadeSemVoto('102', '', null, null),
            new UnidadeSemVoto('103', 'A', '=1+1', null),
        ]);

        self::assertSame(
            "nome;identificacao;grupo;telefone\n\"Ana \"\"Maria\"\"\";\"101\";\"A\";\"61999990001\"\n\"\";\"102\";\"\";\"\"\n\"'=1+1\";\"103\";\"A\";\"\"\n",
            $r->csv(),
        );
    }

    #[TestDox('Link do WhatsApp: DDI 55 com até 11 dígitos, mensagem codificada; sem telefone, nenhum link')]
    public function testLinkDoWhatsapp(): void
    {
        self::assertSame('https://wa.me/5561999990001?text=Ol%C3%A1%20a', (new UnidadeSemVoto('1', '', null, '61999990001'))->linkDoWhatsapp('Olá a'));
        self::assertStringStartsWith('https://wa.me/351912345678?', (new UnidadeSemVoto('1', '', null, '351912345678'))->linkDoWhatsapp('x') ?? '');
        self::assertNull((new UnidadeSemVoto('1', '', null, null))->linkDoWhatsapp('x'));
    }

    #[TestDox('Outro escritório, inexistente ou rascunho: não encontrada')]
    public function testNaoEncontrada(): void
    {
        $tenant = new Tenant();
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->willReturn(null);
        $usecase = new ListarQuemNaoVotouUseCase($repo, $this->createMock(ParticipacaoNaPautaRepository::class), new EnderecoPublicoDaAssembleia('https://bluejus.test'));

        try {
            $usecase->executar($tenant, 1);
            self::fail('devia lançar');
        } catch (AssembleiaNaoEncontradaException) {
            self::addToAssertionCount(1);
        }

        $rascunho = new Assembleia($tenant, 'AGO', PesoDoVoto::Unidade, 'G');
        $this->expectException(AssembleiaNaoEncontradaException::class);
        $this->executar($tenant, $rascunho, []);
    }

    private function publicada(Tenant $tenant): Assembleia
    {
        $a = new Assembleia($tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        (new \ReflectionProperty(Assembleia::class, 'estado'))->setValue($a, EstadoDaAssembleia::Publicada);

        return $a;
    }

    private function pauta(Assembleia $a, int $ordem): PautaDaAssembleia
    {
        $p = new PautaDaAssembleia($a, $ordem, 'Pauta ' . $ordem);
        new OpcaoDaPauta($p, 1, 'Sim');
        (new \ReflectionProperty(PautaDaAssembleia::class, 'id'))->setValue($p, 99 + $ordem);

        return $p;
    }

    private function unidade(Assembleia $a, int $id, string $ident, string $bloco, ?string $situacao, ?string $nome, ?string $tel): UnidadeDaAssembleia
    {
        $u = new UnidadeDaAssembleia($a, $ident, str_repeat('a', 32), str_repeat('b', 64), $bloco, $situacao, null, $nome, null, $tel);
        (new \ReflectionProperty(UnidadeDaAssembleia::class, 'id'))->setValue($u, $id);

        return $u;
    }

    /** @param list<array{unidadeId: int, pautaId: int, pauta: string, registradaEm: \DateTimeImmutable}> $participacoes */
    private function executar(Tenant $tenant, Assembleia $a, array $participacoes): QuemNaoVotouOutput
    {
        $repo = $this->createMock(AssembleiaRepository::class);
        $repo->method('buscarDoEscritorio')->with(7, $tenant)->willReturn($a);
        $part = $this->createMock(ParticipacaoNaPautaRepository::class);
        $part->method('paraConferencia')->willReturn($participacoes);

        return (new ListarQuemNaoVotouUseCase($repo, $part, new EnderecoPublicoDaAssembleia('https://bluejus.test')))->executar($tenant, 7);
    }
}
