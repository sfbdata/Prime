<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\DesfechoContatoOutput;
use App\Cobranca\Entity\EventoHistorico;
use App\Cobranca\Enum\ResultadoContato;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\UseCase\MontarDetalheAtividadePessoaUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Detalhe de uma pessoa na aba Atividade (spec §4/§5/§10). Prova que o desfecho sai do PAYLOAD JSON
 * (`dados->>'resultado'`, o `value` do enum — nunca o label), que o legado `prometeu_pagar` só aparece
 * quando existe de fato, e que a lista de eventos avisa quando trunca.
 */
#[CoversClass(MontarDetalheAtividadePessoaUseCase::class)]
final class MontarDetalheAtividadePessoaUseCaseTest extends TestCase
{
    private const INICIO = '2026-07-20 00:00:00';
    private const FIM_EXCLUSIVO = '2026-07-21 00:00:00';

    /**
     * @param array<string, int> $desfechos
     * @param EventoHistorico[]  $eventos
     */
    private function useCase(array $desfechos, array $eventos = []): MontarDetalheAtividadePessoaUseCase
    {
        $repositorio = $this->createMock(EventoHistoricoRepository::class);
        $repositorio->method('contarDesfechosDeContato')->willReturn($desfechos);
        $repositorio->method('eventosDoUsuarioNoPeriodo')->willReturn($eventos);

        return new MontarDetalheAtividadePessoaUseCase($repositorio);
    }

    private function executar(MontarDetalheAtividadePessoaUseCase $sut, ?int $usuarioId = 1, string $nome = 'Maria'): \App\Cobranca\DTO\AtividadePessoaDetalheOutput
    {
        return $sut->executar(
            new Tenant(),
            $usuarioId,
            $nome,
            new \DateTimeImmutable(self::INICIO),
            new \DateTimeImmutable(self::FIM_EXCLUSIVO),
        );
    }

    /**
     * @param list<DesfechoContatoOutput> $desfechos
     */
    private function quantidade(array $desfechos, string $label): ?int
    {
        foreach ($desfechos as $desfecho) {
            if ($desfecho->label === $label) {
                return $desfecho->quantidade;
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
}
