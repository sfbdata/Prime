<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Entity\PropostaDoConector;
use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropostaDoConector::class)]
final class PropostaDoConectorTest extends TestCase
{
    use CenarioDoConector;

    private function proposta(?\App\Conector\Entity\ConsentimentoDoConector $consentimento = null): PropostaDoConector
    {
        return new PropostaDoConector(
            self::hash('proposta'),
            $consentimento ?? self::consentimento(),
            'atualizar_cliente',
            'cadastrar',
            ['clientes' => [['nome' => 'Fulano', 'cpf' => '12345678909']]],
            self::hash('dados'),
            'Cadastrar 1 cliente: Fulano',
            self::em('2026-11-16 10:00:00'),
        );
    }

    #[TestDox('Pessoa, escritório e app são os do consentimento; vale 10 minutos')]
    public function testCopiaDoConsentimentoEValidade(): void
    {
        $tenant = new Tenant();
        $user = new User();
        $consentimento = self::consentimento($tenant, $user);
        $proposta = $this->proposta($consentimento);

        self::assertSame($tenant, $proposta->getTenant());
        self::assertSame($user, $proposta->getUser());
        self::assertSame($consentimento->getCliente(), $proposta->getCliente());
        self::assertSame(EstadoDaPropostaDoConector::Pendente, $proposta->getEstado());
        self::assertEquals(self::em('2026-11-16 10:10:00'), $proposta->getExpiraEm());
    }

    #[TestDox('Confirmar guarda só ids e zera dados e resumo')]
    public function testConfirmarZeraDados(): void
    {
        $proposta = $this->proposta();
        $proposta->confirmar([41, 42], self::em('2026-11-16 10:05:00'));

        self::assertSame(EstadoDaPropostaDoConector::Confirmada, $proposta->getEstado());
        self::assertSame([41, 42], $proposta->getResultado());
        self::assertNull($proposta->getDados());
        self::assertNull($proposta->getResumo());
        self::assertEquals(self::em('2026-11-16 10:05:00'), $proposta->getDecididaEm());
    }

    #[TestDox('O resultado recusa texto: só ids')]
    public function testResultadoSoIds(): void
    {
        $proposta = $this->proposta();

        $this->expectException(\InvalidArgumentException::class);
        $proposta->confirmar(['Fulano'], self::em('2026-11-16 10:05:00'));
    }

    #[TestDox('Cancelar e expirar zeram dados e resumo')]
    public function testCancelarEExpirarZeram(): void
    {
        $cancelada = $this->proposta();
        $cancelada->cancelar(self::em('2026-11-16 10:01:00'));
        $vencida = $this->proposta();
        $vencida->expirar(self::em('2026-11-16 10:10:00'));

        foreach ([[$cancelada, EstadoDaPropostaDoConector::Cancelada], [$vencida, EstadoDaPropostaDoConector::Expirada]] as [$proposta, $estado]) {
            self::assertSame($estado, $proposta->getEstado());
            self::assertNull($proposta->getDados());
            self::assertNull($proposta->getResumo());
            self::assertNotNull($proposta->getDecididaEm());
        }
    }

    #[TestDox('Uso único: decidida não confirma de novo, vencida não confirma, no prazo não expira')]
    public function testTransicaoUnica(): void
    {
        $recusas = 0;
        $tentativas = [
            function (): void {
                $p = $this->proposta();
                $p->confirmar([1], self::em('2026-11-16 10:01:00'));
                $p->confirmar([1], self::em('2026-11-16 10:02:00'));
            },
            function (): void {
                $p = $this->proposta();
                $p->cancelar(self::em('2026-11-16 10:01:00'));
                $p->confirmar([1], self::em('2026-11-16 10:02:00'));
            },
            function (): void {
                $p = $this->proposta();
                $p->confirmar([1], self::em('2026-11-16 10:10:00'));
            },
            function (): void {
                $p = $this->proposta();
                $p->expirar(self::em('2026-11-16 10:09:59'));
            },
        ];
        foreach ($tentativas as $tentativa) {
            try {
                $tentativa();
            } catch (\DomainException) {
                ++$recusas;
            }
        }

        self::assertSame(4, $recusas);
    }

    #[TestDox('Consentimento revogado não registra proposta; resumo vazio e id cru são recusados')]
    public function testRecusasNaCriacao(): void
    {
        $revogado = self::consentimento();
        $revogado->revogar(self::em('2026-11-16 09:00:00'));
        $recusas = 0;
        $tentativas = [
            fn () => $this->proposta($revogado),
            fn () => new PropostaDoConector(self::hash('p'), self::consentimento(), 'f', 'a', [], self::hash('d'), '  ', self::em('now')),
            fn () => new PropostaDoConector('id-cru', self::consentimento(), 'f', 'a', [], self::hash('d'), 'resumo', self::em('now')),
        ];
        foreach ($tentativas as $tentativa) {
            try {
                $tentativa();
            } catch (\LogicException) {
                ++$recusas;
            }
        }

        self::assertSame(3, $recusas);
    }
}
