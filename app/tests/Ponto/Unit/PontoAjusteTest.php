<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Enum\TipoDoAjuste;
use App\Ponto\Exception\AjusteDeBatidaRecusadoException;
use App\Ponto\Model\AlvoDoAjuste;
use App\Ponto\Service\HorarioFuturoDaBatida;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O ajuste de batida nasce na forma que o banco exige (E10, plano-e10-ponto §6): cada construtor nomeado prende a linha
 * à cabeça do alvo e copia a raiz; a restauração traz de volta a forma de antes da desconsideração. E as recusas com os
 * textos do plano.
 */
#[CoversClass(PontoAjuste::class)]
#[CoversClass(AlvoDoAjuste::class)]
#[CoversClass(AjusteDeBatidaRecusadoException::class)]
final class PontoAjusteTest extends TestCase
{
    private Tenant $escritorio;
    private User $pessoa;
    private User $gestora;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->pessoa = new User();
        $this->gestora = new User();
        $this->agora = new \DateTimeImmutable('2026-10-22 09:00:00');
    }

    #[TestDox('Inclusão: sem raiz, sem anterior, com a nova e a justificativa; o alvo é a própria inclusão')]
    public function testInclusao(): void
    {
        $inclusao = PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'Motivo.', $this->gestora, 'Gestora', $this->agora);
        (new \ReflectionProperty(PontoAjuste::class, 'id'))->setValue($inclusao, 50);

        self::assertSame(TipoDoAjuste::Inclusao, $inclusao->getTipo());
        self::assertSame([null, null, null, null, null], [$inclusao->getRegistroPonto(), $inclusao->getInclusao(), $inclusao->getAnterior(), $inclusao->getTipoMarcacaoAnterior(), $inclusao->getDataHoraAnterior()]);
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$inclusao->getTipoMarcacaoNova(), $inclusao->getDataHoraNova()?->format('Y-m-d H:i:s')]);
        self::assertSame([$this->escritorio, $this->pessoa, $this->gestora, 'Gestora', $this->agora], [$inclusao->getTenant(), $inclusao->getUser(), $inclusao->getAutor(), $inclusao->getAutorNome(), $inclusao->getCriadoEm()]);
        self::assertSame('a50', $inclusao->alvo());
    }

    #[TestDox('Alteração: presa à cabeça do alvo (nula no registro nunca ajustado), com a forma atual como anterior e a raiz copiada')]
    public function testAlteracaoPresaACabeca(): void
    {
        [$batida, $alvo] = $this->alvoNaBatida(null);

        $primeira = PontoAjuste::alteracao($alvo, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), 'Motivo.', $this->gestora, 'Gestora', $this->agora);
        $segunda = PontoAjuste::alteracao($this->alvoNaBatida($primeira, $batida)[1], 'retorno', new \DateTimeImmutable('2026-10-21 13:00:00'), 'Motivo.', $this->gestora, 'Gestora', $this->agora);

        self::assertSame([TipoDoAjuste::Alteracao, $batida, null], [$primeira->getTipo(), $primeira->getRegistroPonto(), $primeira->getAnterior()]);
        self::assertSame(['saida', '2026-10-21 18:00:00'], [$primeira->getTipoMarcacaoAnterior(), $primeira->getDataHoraAnterior()?->format('Y-m-d H:i:s')]);
        self::assertSame([$batida, $primeira], [$segunda->getRegistroPonto(), $segunda->getAnterior()]);
        self::assertSame('b31', $segunda->alvo());
    }

    #[TestDox('Desconsideração e restauração: a desconsideração não tem nova; a restauração desfaz e devolve a forma de antes')]
    public function testDesconsideracaoERestauracao(): void
    {
        [$batida, $alvo] = $this->alvoNaBatida(null);

        $desconsideracao = PontoAjuste::desconsideracao($alvo, 'Motivo.', $this->gestora, 'Gestora', $this->agora);
        $restauracao = PontoAjuste::restauracao($desconsideracao, 'Volta.', $this->gestora, 'Gestora', $this->agora);

        self::assertSame([TipoDoAjuste::Desconsideracao, null, null], [$desconsideracao->getTipo(), $desconsideracao->getTipoMarcacaoNova(), $desconsideracao->getDataHoraNova()]);
        self::assertSame([TipoDoAjuste::Restauracao, $batida, $desconsideracao, $desconsideracao], [$restauracao->getTipo(), $restauracao->getRegistroPonto(), $restauracao->getAnterior(), $restauracao->getDesfaz()]);
        self::assertSame(['saida', '2026-10-21 18:00:00', null], [$restauracao->getTipoMarcacaoNova(), $restauracao->getDataHoraNova()?->format('Y-m-d H:i:s'), $restauracao->getTipoMarcacaoAnterior()]);
    }

    #[TestDox('Invariantes: só a desconsideração se restaura; alvo fora da conta não se altera nem se desconsidera; tipo de batida inválido é recusado')]
    public function testInvariantes(): void
    {
        [, $alvo] = $this->alvoNaBatida(null);
        $alteracao = PontoAjuste::alteracao($alvo, 'saida', new \DateTimeImmutable('2026-10-21 18:30:00'), 'm', $this->gestora, 'Gestora', $this->agora);
        $fora = new AlvoDoAjuste($this->escritorio, $this->pessoa, 'b31', $alvo->raizBatida, null, $alteracao, null, null);

        $casos = [
            'restaurar alteração'    => [fn () => PontoAjuste::restauracao($alteracao, 'm', $this->gestora, 'Gestora', $this->agora), 'Só a desconsideração se restaura.'],
            'alterar fora da conta'  => [fn () => PontoAjuste::alteracao($fora, 'saida', new \DateTimeImmutable(), 'm', $this->gestora, 'Gestora', $this->agora), 'A batida está fora da conta: só a restauração a traz de volta.'],
            'desconsiderar de novo'  => [fn () => PontoAjuste::desconsideracao($fora, 'm', $this->gestora, 'Gestora', $this->agora), 'A batida está fora da conta: só a restauração a traz de volta.'],
            'tipo de batida inválido' => [fn () => PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'almoco', new \DateTimeImmutable(), 'm', $this->gestora, 'Gestora', $this->agora), 'Tipo de batida inválido.'],
        ];
        foreach ($casos as $caso => [$operacao, $mensagem]) {
            try {
                $operacao();
                self::fail('Aceitou: ' . $caso);
            } catch (\LogicException $erro) {   // \InvalidArgumentException também é LogicException
                self::assertSame($mensagem, $erro->getMessage(), $caso);
            }
        }
    }

    #[TestDox('AlvoDoAjuste exige uma raiz só')]
    public function testAlvoComUmaRaizSo(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('O alvo do ajuste tem uma raiz só');
        new AlvoDoAjuste($this->escritorio, $this->pessoa, 'b31', null, null, null, 'saida', new \DateTimeImmutable());
    }

    #[TestDox('AlvoDoAjuste na inclusão exige a cabeça (no mínimo a própria inclusão)')]
    public function testAlvoDaInclusaoSemCabeca(): void
    {
        $inclusao = PontoAjuste::inclusao($this->escritorio, $this->pessoa, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'), 'm', $this->gestora, 'Gestora', $this->agora);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Na inclusão, a cabeça é no mínimo a própria inclusão');
        new AlvoDoAjuste($this->escritorio, $this->pessoa, 'a1', null, $inclusao, null, 'saida', new \DateTimeImmutable('2026-10-21 18:00:00'));
    }

    #[TestDox('Recusas: o motivo de cada uma e os textos do plano, sem travessão')]
    public function testRecusas(): void
    {
        $recusas = [
            AjusteDeBatidaRecusadoException::mudou(),
            AjusteDeBatidaRecusadoException::naoEncontrada(),
            AjusteDeBatidaRecusadoException::futuro(),
            AjusteDeBatidaRecusadoException::nadaMudou(),
            AjusteDeBatidaRecusadoException::semMotivo(),
            AjusteDeBatidaRecusadoException::motivoLongo(),
            AjusteDeBatidaRecusadoException::ligadaAJustificativa(),
            AjusteDeBatidaRecusadoException::daPropriaBatida(),
        ];

        self::assertSame(
            ['mudou', 'nao_encontrada', 'futuro', 'nada_mudou', 'sem_motivo', 'motivo_longo', 'ligada_a_justificativa', 'da_propria_batida'],
            array_map(static fn (AjusteDeBatidaRecusadoException $r): string => $r->motivo, $recusas),
        );
        self::assertSame([
            'Esta batida mudou desde que a folha foi aberta. Confira a folha e tente de novo.',
            'Batida não encontrada nesta folha.',
            HorarioFuturoDaBatida::MENSAGEM,
            'O ajuste não muda nada: a batida já tem este tipo, esta data e esta hora.',
            'Informe o motivo da correção.',
            'O motivo da correção passa de 2000 caracteres. Resuma o texto.',
            'Esta batida saiu da conta pela análise da justificativa e só volta por ela: com um novo abono ou, no trabalho externo, quando a justificativa volta para análise.',
            'Só o usuário primário do escritório ou o Master pode corrigir a própria batida.',
        ], array_map(static fn (AjusteDeBatidaRecusadoException $r): string => $r->getMessage(), $recusas));
        foreach ($recusas as $recusa) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $recusa->getMessage());
        }
    }

    /** @return array{RegistroPonto, AlvoDoAjuste} */
    private function alvoNaBatida(?PontoAjuste $cabeca, ?RegistroPonto $batida = null): array
    {
        if ($batida === null) {
            $batida = (new RegistroPonto())->setTenant($this->escritorio)->setUser($this->pessoa)->setTipo('saida')->setDataHora(new \DateTime('2026-10-21 18:00:00'));
            (new \ReflectionProperty(RegistroPonto::class, 'id'))->setValue($batida, 31);
        }
        $tipo = $cabeca === null ? 'saida' : $cabeca->getTipoMarcacaoNova();
        $dataHora = $cabeca === null ? new \DateTimeImmutable('2026-10-21 18:00:00') : $cabeca->getDataHoraNova();

        return [$batida, new AlvoDoAjuste($this->escritorio, $this->pessoa, 'b31', $batida, null, $cabeca, $tipo, $dataHora)];
    }
}
