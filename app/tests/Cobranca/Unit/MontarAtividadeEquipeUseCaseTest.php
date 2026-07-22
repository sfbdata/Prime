<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\AtividadePessoaOutput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\EventoHistoricoRepository;
use App\Cobranca\Repository\UsuarioCobrancaRepository;
use App\Cobranca\Service\PastilhasDeContato;
use App\Cobranca\UseCase\MontarAtividadeEquipeUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Aba Atividade da Central de Acompanhamento (spec `cobranca-central-acompanhamento.md` §10). O que
 * importa provar aqui é a MONTAGEM: quem trabalhou aparece com o que fez, quem não trabalhou aparece
 * ZERADO (é a informação que o gestor foi buscar), evento órfão vira "Sem responsável" e o período/
 * carteira chegam intactos ao repositório (a faixa é filtrada no BANCO, não em PHP).
 */
#[CoversClass(MontarAtividadeEquipeUseCase::class)]
final class MontarAtividadeEquipeUseCaseTest extends TestCase
{
    private const INICIO = '2026-07-20 00:00:00';
    private const FIM_EXCLUSIVO = '2026-07-21 00:00:00';

    /**
     * @param list<array<string, mixed>>         $agregado
     * @param list<array{id: int, nome: string}> $elegiveis
     * @param array<string, int>                 $canais
     */
    private function useCase(array $agregado, array $elegiveis, array $canais = []): MontarAtividadeEquipeUseCase
    {
        $eventos = $this->createMock(EventoHistoricoRepository::class);
        $eventos->method('agregarAtividadePorUsuario')->willReturn($agregado);

        $eventos->method('contarPayloadDeContatoDoSetor')->willReturn($canais);

        $usuarios = $this->createMock(UsuarioCobrancaRepository::class);
        $usuarios->method('ativosComAcessoAoModulo')->willReturn($elegiveis);

        return new MontarAtividadeEquipeUseCase($eventos, $usuarios, new PastilhasDeContato());
    }

    /**
     * @return array{usuarioId: ?int, usuarioNome: ?string, contatos: int, atendidos: int, acordos: int, baixas: int, ultimaAcao: ?\DateTimeImmutable}
     */
    private function linha(?int $usuarioId, ?string $nome, int $contatos, int $atendidos, int $acordos, int $baixas, ?string $ultimaAcao = null): array
    {
        return [
            'usuarioId' => $usuarioId,
            'usuarioNome' => $nome,
            'contatos' => $contatos,
            'atendidos' => $atendidos,
            'acordos' => $acordos,
            'baixas' => $baixas,
            'ultimaAcao' => $ultimaAcao === null ? null : new \DateTimeImmutable($ultimaAcao),
        ];
    }

    private function executar(MontarAtividadeEquipeUseCase $sut): \App\Cobranca\DTO\AtividadeEquipeOutput
    {
        return $sut->executar(
            new Tenant(),
            new \DateTimeImmutable(self::INICIO),
            new \DateTimeImmutable(self::FIM_EXCLUSIVO),
        );
    }

    /**
     * @param list<AtividadePessoaOutput> $pessoas
     */
    private function porNome(array $pessoas, string $nome): AtividadePessoaOutput
    {
        foreach ($pessoas as $pessoa) {
            if ($pessoa->nome === $nome) {
                return $pessoa;
            }
        }

        self::fail(sprintf('Linha "%s" não encontrada na tabela de atividade.', $nome));
    }

    #[TestDox('Conta contatos, atendidos, acordos e baixas de cada pessoa a partir do agregado')]
    public function testContagensPorPessoa(): void
    {
        $sut = $this->useCase(
            [
                $this->linha(1, 'Maria', 61, 27, 4, 5, '2026-07-20 17:40:00'),
                $this->linha(2, 'Joana', 49, 19, 2, 6, '2026-07-20 09:15:00'),
            ],
            [['id' => 1, 'nome' => 'Maria'], ['id' => 2, 'nome' => 'Joana']],
        );

        $saida = $this->executar($sut);

        $maria = $this->porNome($saida->pessoas, 'Maria');
        self::assertSame(61, $maria->contatos);
        self::assertSame(27, $maria->atendidos);
        self::assertSame(4, $maria->acordos);
        self::assertSame(5, $maria->baixas);
        self::assertSame('2026-07-20 17:40:00', $maria->ultimaAcao?->format('Y-m-d H:i:s'));

        $joana = $this->porNome($saida->pessoas, 'Joana');
        self::assertSame(49, $joana->contatos);
        self::assertSame(19, $joana->atendidos);
    }

    #[TestDox('O total do setor soma todas as linhas e a última ação é a mais recente')]
    public function testTotalDoSetor(): void
    {
        $sut = $this->useCase(
            [
                $this->linha(1, 'Maria', 61, 27, 4, 5, '2026-07-20 17:40:00'),
                $this->linha(2, 'Joana', 49, 19, 2, 6, '2026-07-20 09:15:00'),
            ],
            [['id' => 1, 'nome' => 'Maria'], ['id' => 2, 'nome' => 'Joana']],
        );

        $saida = $this->executar($sut);

        self::assertSame(110, $saida->totalContatos);
        self::assertSame(46, $saida->totalAtendidos);
        self::assertSame(6, $saida->totalAcordos);
        self::assertSame(11, $saida->totalBaixas);
        self::assertSame('2026-07-20 17:40:00', $saida->ultimaAcaoDoSetor?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Usuário sem nenhum evento no período aparece ZERADO, não some da lista')]
    public function testUsuarioSemEventoApareceZerado(): void
    {
        $sut = $this->useCase(
            [$this->linha(1, 'Maria', 10, 4, 1, 0, '2026-07-20 11:00:00')],
            [['id' => 1, 'nome' => 'Maria'], ['id' => 9, 'nome' => 'Samuel']],
        );

        $saida = $this->executar($sut);

        $samuel = $this->porNome($saida->pessoas, 'Samuel');
        self::assertSame(0, $samuel->contatos);
        self::assertSame(0, $samuel->atendidos);
        self::assertSame(0, $samuel->acordos);
        self::assertSame(0, $samuel->baixas);
        self::assertNull($samuel->ultimaAcao);
        self::assertFalse($samuel->semResponsavel);
    }

    #[TestDox('Evento com usuario_id nulo cai na linha "Sem responsável", ao final e sem dono')]
    public function testEventoOrfaoVaiParaSemResponsavel(): void
    {
        $sut = $this->useCase(
            [
                $this->linha(null, null, 7, 3, 0, 2, '2026-07-20 08:00:00'),
                $this->linha(1, 'Maria', 10, 4, 1, 0, '2026-07-20 11:00:00'),
            ],
            [['id' => 1, 'nome' => 'Maria']],
        );

        $saida = $this->executar($sut);

        $ultima = $saida->pessoas[array_key_last($saida->pessoas)];
        self::assertTrue($ultima->semResponsavel);
        self::assertSame(AtividadePessoaOutput::SEM_RESPONSAVEL, $ultima->nome);
        self::assertNull($ultima->usuarioId);
        self::assertSame(7, $ultima->contatos);

        // O órfão não pode ser atribuído a ninguém: a Maria continua com o que é dela.
        self::assertSame(10, $this->porNome($saida->pessoas, 'Maria')->contatos);
        // …mas entra no total do setor, senão a tela subnotifica o que aconteceu no período.
        self::assertSame(17, $saida->totalContatos);
    }

    #[TestDox('Sem eventos órfãos, a linha "Sem responsável" não é criada')]
    public function testSemOrfaoNaoCriaLinha(): void
    {
        $sut = $this->useCase(
            [$this->linha(1, 'Maria', 10, 4, 1, 0, '2026-07-20 11:00:00')],
            [['id' => 1, 'nome' => 'Maria']],
        );

        $saida = $this->executar($sut);

        foreach ($saida->pessoas as $pessoa) {
            self::assertFalse($pessoa->semResponsavel);
        }
    }

    #[TestDox('Quem trabalhou mas perdeu o acesso ao módulo continua na tabela (o trabalho não some)')]
    public function testUsuarioForaDaListaDeElegiveisAindaAparece(): void
    {
        $sut = $this->useCase(
            [
                $this->linha(1, 'Maria', 10, 4, 1, 0, '2026-07-20 11:00:00'),
                $this->linha(77, 'Cláudia (ex-equipe)', 32, 12, 1, 1, '2026-07-20 10:00:00'),
            ],
            [['id' => 1, 'nome' => 'Maria']],
        );

        $saida = $this->executar($sut);

        self::assertSame(32, $this->porNome($saida->pessoas, 'Cláudia (ex-equipe)')->contatos);
        self::assertSame(42, $saida->totalContatos);
    }

    #[TestDox('As pessoas saem em ordem alfabética; "Sem responsável" fica sempre por último')]
    public function testOrdenacao(): void
    {
        $sut = $this->useCase(
            [$this->linha(null, null, 1, 0, 0, 0, '2026-07-20 08:00:00')],
            [['id' => 3, 'nome' => 'Zulmira'], ['id' => 1, 'nome' => 'Ana'], ['id' => 2, 'nome' => 'Élida']],
        );

        $saida = $this->executar($sut);

        self::assertSame(
            ['Ana', 'Élida', 'Zulmira', AtividadePessoaOutput::SEM_RESPONSAVEL],
            array_map(static fn (AtividadePessoaOutput $p): string => $p->nome, $saida->pessoas),
        );
    }

    #[TestDox('Período e carteira chegam INTACTOS ao repositório (a faixa é filtrada no banco)')]
    public function testRepassaFiltrosAoRepositorio(): void
    {
        $tenant = new Tenant();
        $carteira = new Carteira();
        $inicio = new \DateTimeImmutable(self::INICIO);
        $fimExclusivo = new \DateTimeImmutable(self::FIM_EXCLUSIVO);

        $eventos = $this->createMock(EventoHistoricoRepository::class);
        $eventos->expects(self::once())
            ->method('agregarAtividadePorUsuario')
            ->with(
                self::identicalTo($tenant),
                self::identicalTo($inicio),
                self::identicalTo($fimExclusivo),
                self::identicalTo($carteira),
            )
            ->willReturn([]);

        // A faixa de canais é do SETOR e tem de respeitar os MESMOS filtros da tabela.
        $eventos->expects(self::once())
            ->method('contarPayloadDeContatoDoSetor')
            ->with(
                self::identicalTo($tenant),
                self::identicalTo(PastilhasDeContato::CHAVE_CANAL),
                self::identicalTo($inicio),
                self::identicalTo($fimExclusivo),
                self::identicalTo($carteira),
            )
            ->willReturn([]);

        $usuarios = $this->createMock(UsuarioCobrancaRepository::class);
        $usuarios->expects(self::once())
            ->method('ativosComAcessoAoModulo')
            ->with(self::identicalTo($tenant))
            ->willReturn([]);

        $saida = (new MontarAtividadeEquipeUseCase($eventos, $usuarios, new PastilhasDeContato()))
            ->executar($tenant, $inicio, $fimExclusivo, $carteira);

        self::assertSame([], $saida->pessoas);
        self::assertSame(0, $saida->totalContatos);
        self::assertNull($saida->ultimaAcaoDoSetor);
    }

    #[TestDox('A faixa de canais responde "quantos contatos e por qual meio" (spec §4)')]
    public function testFaixaDeCanais(): void
    {
        $sut = $this->useCase(
            [$this->linha(1, 'Maria', 142, 58, 7, 12, '2026-07-20 17:40:00')],
            [['id' => 1, 'nome' => 'Maria']],
            canais: ['telefone' => 71, 'whatsapp' => 54, 'email' => 12, 'sms' => 5],
        );

        $saida = $this->executar($sut);

        $porRotulo = [];
        foreach ($saida->canais as $canal) {
            $porRotulo[$canal->label] = $canal->quantidade;
        }

        self::assertSame(71, $porRotulo['Telefone'] ?? null);
        self::assertSame(54, $porRotulo['WhatsApp'] ?? null);
        self::assertSame(12, $porRotulo['E-mail'] ?? null);
        self::assertSame(5, $porRotulo['SMS'] ?? null);
    }
}
