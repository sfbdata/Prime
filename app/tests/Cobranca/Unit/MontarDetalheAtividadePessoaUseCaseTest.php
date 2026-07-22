<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AtividadePessoaDetalheOutput;
use App\Cobranca\DTO\DesfechoContatoOutput;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\CanalContato;
use App\Cobranca\Enum\ResultadoContato;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Service\PastilhasDeContato;
use App\Cobranca\UseCase\MontarDetalheAtividadePessoaUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Detalhe de uma pessoa na aba Atividade (spec §4/§5/§5.1/§10). Prova que desfecho e canal saem do
 * PAYLOAD JSON (o `value` do enum, nunca o label), que o legado `prometeu_pagar` só aparece quando
 * existe de fato, que a lista avisa ao truncar e que **lançamento de cadastro/importação fica fora da
 * lista principal, mas nunca some calado**.
 */
#[CoversClass(MontarDetalheAtividadePessoaUseCase::class)]
final class MontarDetalheAtividadePessoaUseCaseTest extends TestCase
{
    private const INICIO = '2026-07-20 00:00:00';
    private const FIM_EXCLUSIVO = '2026-07-21 00:00:00';

    /**
     * @param array<string, int> $desfechos
     * @param EventoHistorico[]  $eventos
     * @param array<string, int> $canais
     * @param EventoHistorico[]  $eventosCadastro
     */
    private function useCase(
        array $desfechos,
        array $eventos = [],
        array $canais = [],
        int $totalCadastro = 0,
        array $eventosCadastro = [],
    ): MontarDetalheAtividadePessoaUseCase {
        $repositorio = $this->createMock(EventoHistoricoRepository::class);

        $repositorio->method('contarPayloadDeContatoDaPessoa')->willReturnCallback(
            static fn (Tenant $t, string $chave): array => $chave === PastilhasDeContato::CHAVE_CANAL ? $canais : $desfechos,
        );

        // O repositório devolve conforme os TIPOS pedidos: trabalho na chamada padrão, cadastro na de
        // expansão. É esse contrato que o §5.1 depende.
        $repositorio->method('eventosDoUsuarioNoPeriodo')->willReturnCallback(
            static function (Tenant $t, ?int $u, \DateTimeImmutable $i, \DateTimeImmutable $f, $c, array $tipos) use ($eventos, $eventosCadastro): array {
                $ehCadastro = \in_array(TipoEventoHistorico::ObrigacaoCriada, $tipos, true);

                return $ehCadastro ? $eventosCadastro : $eventos;
            },
        );

        $repositorio->method('contarEventosDoUsuarioNoPeriodo')->willReturn($totalCadastro);

        return new MontarDetalheAtividadePessoaUseCase($repositorio, new PastilhasDeContato());
    }

    private function executar(
        MontarDetalheAtividadePessoaUseCase $sut,
        ?int $usuarioId = 1,
        string $nome = 'Maria',
        bool $incluirCadastro = false,
    ): AtividadePessoaDetalheOutput {
        return $sut->executar(
            new Tenant(),
            $usuarioId,
            $nome,
            new \DateTimeImmutable(self::INICIO),
            new \DateTimeImmutable(self::FIM_EXCLUSIVO),
            null,
            $incluirCadastro,
        );
    }

    /**
     * @param list<DesfechoContatoOutput> $pastilhas
     */
    private function quantidade(array $pastilhas, string $label): ?int
    {
        foreach ($pastilhas as $pastilha) {
            if ($pastilha->label === $label) {
                return $pastilha->quantidade;
            }
        }

        return null;
    }

    #[TestDox('Os desfechos vêm do payload, rotulados pelo label do enum')]
    public function testDesfechosLidosDoPayload(): void
    {
        $sut = $this->useCase([
            ResultadoContato::Atendido->value => 27,
            ResultadoContato::NaoAtendido->value => 21,
            ResultadoContato::CaixaPostal->value => 6,
        ]);

        $saida = $this->executar($sut);

        self::assertSame(27, $this->quantidade($saida->desfechos, 'Atendido'));
        self::assertSame(21, $this->quantidade($saida->desfechos, 'Não atendido'));
        self::assertSame(6, $this->quantidade($saida->desfechos, 'Caixa postal'));
    }

    #[TestDox('Os canais vêm do payload (dados->>canal), rotulados pelo CanalContato')]
    public function testCanaisLidosDoPayload(): void
    {
        $sut = $this->useCase([], canais: [
            CanalContato::Telefone->value => 34,
            CanalContato::WhatsApp->value => 21,
            CanalContato::Email->value => 5,
        ]);

        $saida = $this->executar($sut);

        self::assertSame(34, $this->quantidade($saida->canais, 'Telefone'));
        self::assertSame(21, $this->quantidade($saida->canais, 'WhatsApp'));
        self::assertSame(5, $this->quantidade($saida->canais, 'E-mail'));
        self::assertSame(0, $this->quantidade($saida->canais, 'SMS'), 'canal sem uso aparece zerado');
    }

    #[TestDox('Desfecho e canal não se misturam: cada um lê a sua chave do payload')]
    public function testDesfechoECanalNaoSeMisturam(): void
    {
        $sut = $this->useCase(
            [ResultadoContato::Atendido->value => 9],
            canais: [CanalContato::Telefone->value => 4],
        );

        $saida = $this->executar($sut);

        self::assertSame(9, $this->quantidade($saida->desfechos, 'Atendido'));
        self::assertSame(4, $this->quantidade($saida->canais, 'Telefone'));
        self::assertNull($this->quantidade($saida->desfechos, 'Telefone'));
        self::assertNull($this->quantidade($saida->canais, 'Atendido'));
    }

    #[TestDox('Desfecho selecionável sem nenhuma ocorrência aparece zerado (o gestor vê a régua inteira)')]
    public function testDesfechoSelecionavelZeradoAparece(): void
    {
        $sut = $this->useCase([ResultadoContato::Atendido->value => 3]);

        $saida = $this->executar($sut);

        self::assertSame(0, $this->quantidade($saida->desfechos, 'Outro'));
        self::assertSame(0, $this->quantidade($saida->desfechos, 'Número errado'));
    }

    #[TestDox('"Atendido" é a primeira pastilha — é a medida de efetividade da aba')]
    public function testAtendidoVemPrimeiro(): void
    {
        $sut = $this->useCase([ResultadoContato::NaoAtendido->value => 5]);

        $saida = $this->executar($sut);

        self::assertSame('Atendido', $saida->desfechos[0]->label);
    }

    #[TestDox('O legado "prometeu_pagar" só aparece quando há ocorrência no período')]
    public function testPrometeuPagarSoQuandoExiste(): void
    {
        $semLegado = $this->executar($this->useCase([ResultadoContato::Atendido->value => 2]));
        self::assertNull($this->quantidade($semLegado->desfechos, 'Prometeu pagar'));

        $comLegado = $this->executar($this->useCase([
            ResultadoContato::Atendido->value => 2,
            ResultadoContato::PrometeuPagar->value => 4,
        ]));
        self::assertSame(4, $this->quantidade($comLegado->desfechos, 'Prometeu pagar'));
    }

    #[TestDox('Contato antigo sem "resultado" no payload vira "Não informado", não some da conta')]
    public function testContatoSemResultadoNoPayload(): void
    {
        $sut = $this->useCase([
            ResultadoContato::Atendido->value => 2,
            '' => 9,
        ]);

        $saida = $this->executar($sut);

        self::assertSame(9, $this->quantidade($saida->desfechos, 'Não informado'));
    }

    #[TestDox('Valor desconhecido no payload é exibido cru, nunca descartado em silêncio')]
    public function testValorDesconhecidoNaoSomeEmSilencio(): void
    {
        $sut = $this->useCase(['valor_que_nao_existe_no_enum' => 3]);

        $saida = $this->executar($sut);

        self::assertSame(3, $this->quantidade($saida->desfechos, 'valor_que_nao_existe_no_enum'));
    }

    #[TestDox('A lista de eventos avisa quando trunca e devolve no máximo o limite')]
    public function testTruncamento(): void
    {
        $limite = MontarDetalheAtividadePessoaUseCase::LIMITE_EVENTOS;
        // O repositório busca limite+1 justamente para o UseCase saber que sobrou coisa de fora.
        $eventos = array_map(static fn (): EventoHistorico => new EventoHistorico(), range(1, $limite + 1));

        $saida = $this->executar($this->useCase([], $eventos));

        self::assertTrue($saida->truncado);
        self::assertCount($limite, $saida->eventos);
        self::assertSame($limite, $saida->limite);
    }

    #[TestDox('Lista dentro do limite não é marcada como truncada')]
    public function testSemTruncamento(): void
    {
        $eventos = [new EventoHistorico(), new EventoHistorico()];

        $saida = $this->executar($this->useCase([], $eventos));

        self::assertFalse($saida->truncado);
        self::assertCount(2, $saida->eventos);
    }

    #[TestDox('Detalhe de "Sem responsável" é montado sem dono, com o nome recebido')]
    public function testDetalheSemResponsavel(): void
    {
        $saida = $this->executar($this->useCase([]), usuarioId: null, nome: 'Sem responsável');

        self::assertNull($saida->usuarioId);
        self::assertSame('Sem responsável', $saida->nome);
    }

    // ── §5.1: trabalho de cobrança × lançamento de cadastro/importação ──────────────────────────

    #[TestDox('§5.1: a lista padrão pede APENAS tipos de trabalho de cobrança ao repositório')]
    public function testListaPadraoPedeSoTrabalho(): void
    {
        $repositorio = $this->createMock(EventoHistoricoRepository::class);
        $repositorio->method('contarPayloadDeContatoDaPessoa')->willReturn([]);
        $repositorio->method('contarEventosDoUsuarioNoPeriodo')->willReturn(0);
        $repositorio->expects(self::once())
            ->method('eventosDoUsuarioNoPeriodo')
            ->with(
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::anything(),
                self::identicalTo(TipoEventoHistorico::trabalhoDeCobranca()),
                self::anything(),
            )
            ->willReturn([]);

        (new MontarDetalheAtividadePessoaUseCase($repositorio, new PastilhasDeContato()))->executar(
            new Tenant(),
            1,
            'Maria',
            new \DateTimeImmutable(self::INICIO),
            new \DateTimeImmutable(self::FIM_EXCLUSIVO),
        );
    }

    #[TestDox('§5.1: os lançamentos de cadastro não somem — vêm contados, prontos para a linha "+N"')]
    public function testCadastroEhResumidoNaoEscondido(): void
    {
        $saida = $this->executar($this->useCase([], [new EventoHistorico()], totalCadastro: 537));

        self::assertSame(537, $saida->totalCadastro);
        self::assertTrue($saida->temLancamentosDeCadastro());
        self::assertCount(1, $saida->eventos, 'a lista principal segue só com trabalho');
        self::assertSame([], $saida->eventosCadastro, 'sem expandir, a lista pesada nem é buscada');
        self::assertFalse($saida->cadastroExpandido);
    }

    #[TestDox('§5.1: sem lançamento de cadastro no período, não há linha a exibir')]
    public function testSemCadastroNaoTemLinha(): void
    {
        $saida = $this->executar($this->useCase([], [new EventoHistorico()]));

        self::assertSame(0, $saida->totalCadastro);
        self::assertFalse($saida->temLancamentosDeCadastro());
    }

    #[TestDox('§5.1: ao expandir, os lançamentos de cadastro são carregados')]
    public function testExpandirCarregaOsLancamentosDeCadastro(): void
    {
        $saida = $this->executar(
            $this->useCase([], [new EventoHistorico()], totalCadastro: 3, eventosCadastro: [new EventoHistorico(), new EventoHistorico()]),
            incluirCadastro: true,
        );

        self::assertTrue($saida->cadastroExpandido);
        self::assertCount(2, $saida->eventosCadastro);
        self::assertCount(1, $saida->eventos, 'a lista de trabalho continua separada');
    }

    #[TestDox('§5.1: expandir sem nada para expandir não dispara consulta de lista')]
    public function testExpandirSemCadastroNaoConsulta(): void
    {
        $repositorio = $this->createMock(EventoHistoricoRepository::class);
        $repositorio->method('contarPayloadDeContatoDaPessoa')->willReturn([]);
        $repositorio->method('contarEventosDoUsuarioNoPeriodo')->willReturn(0);
        // Só a lista de trabalho: a de cadastro não é pedida quando o total é zero.
        $repositorio->expects(self::once())->method('eventosDoUsuarioNoPeriodo')->willReturn([]);

        $saida = (new MontarDetalheAtividadePessoaUseCase($repositorio, new PastilhasDeContato()))->executar(
            new Tenant(),
            1,
            'Maria',
            new \DateTimeImmutable(self::INICIO),
            new \DateTimeImmutable(self::FIM_EXCLUSIVO),
            null,
            true,
        );

        self::assertSame([], $saida->eventosCadastro);
    }
}
