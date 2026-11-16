<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\EstadoDaPauta;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Service\ApuracaoDaPauta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Suspender, retomar, cancelar e retirar de pauta (decisão C6 e núcleo do desenho: pausar, retomarPausa, cancelar):
 * regras, mensagens e o efeito no prazo e na apuração. Arquivar e excluir ficam em CicloDaAssembleiaTest.
 */
#[CoversClass(PautaDaAssembleia::class)]
#[CoversClass(ApuracaoDaPauta::class)]
final class CicloDaPautaTest extends TestCase
{
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->agora = new \DateTimeImmutable('2026-11-16 19:00:00');
    }

    #[TestDox('Suspender a pauta aberta: segue aberta, para de aceitar voto e guarda quando, motivo e quem')]
    public function testSuspender(): void
    {
        $pauta = $this->pautaAberta();

        $pauta->suspender('  Queda de energia no salão ', 'Comissão', $this->agora);

        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());
        self::assertTrue($pauta->suspensa());
        self::assertFalse($pauta->aceitaVotoEm($this->agora));
        self::assertSame($this->agora, $pauta->getSuspensaEm());
        self::assertSame('Queda de energia no salão', $pauta->getSuspensaMotivo());
        self::assertSame('Comissão', $pauta->getSuspensaPorNome());
    }

    #[TestDox('Motivo com menos de 5 caracteres: "Informe o motivo da suspensão." e nada muda')]
    public function testSuspenderSemMotivo(): void
    {
        $pauta = $this->pautaAberta();

        $this->assertRecusa('Informe o motivo da suspensão.', fn () => $pauta->suspender('abc ', 'Mesa', $this->agora));

        self::assertFalse($pauta->suspensa());
        self::assertTrue($pauta->aceitaVotoEm($this->agora));
    }

    #[TestDox('Só pauta aberta se suspende; suspender duas vezes é recusado')]
    public function testSuspenderSoAberta(): void
    {
        $aguardando = $this->pautaAguardando();
        $this->assertRecusa('Só votação aberta pode ser suspensa.', fn () => $aguardando->suspender('Motivo válido', 'Mesa', $this->agora));

        $encerrada = $this->pautaAberta();
        $encerrada->encerrar('Mesa', '', $this->agora);
        $this->assertRecusa('Só votação aberta pode ser suspensa.', fn () => $encerrada->suspender('Motivo válido', 'Mesa', $this->agora));

        $suspensa = $this->pautaAberta();
        $suspensa->suspender('Motivo válido', 'Mesa', $this->agora);
        $this->assertRecusa('A votação já está suspensa.', fn () => $suspensa->suspender('Outro motivo', 'Mesa', $this->agora));
    }

    #[TestDox('Retomar só a suspensa; volta a aceitar voto e limpa a suspensão')]
    public function testRetomar(): void
    {
        $pauta = $this->pautaAberta();
        $this->assertRecusa('A votação não está suspensa.', fn () => $pauta->retomar('Mesa', $this->agora));

        $pauta->suspender('Queda de energia', 'Mesa', $this->agora);
        $pauta->retomar('Mesa', $this->agora->modify('+10 minutes'));

        self::assertFalse($pauta->suspensa());
        self::assertNull($pauta->getSuspensaEm());
        self::assertNull($pauta->getSuspensaMotivo());
        self::assertTrue($pauta->aceitaVotoEm($this->agora->modify('+10 minutes')));
    }

    #[TestDox('O prazo congela na suspensão: não vence suspensa e, ao retomar, anda o tempo que ficou parada')]
    public function testPrazoCongela(): void
    {
        $pauta = $this->pautaAberta(prazo: '+30 minutes');
        $pauta->suspender('Queda de energia', 'Mesa', $this->agora->modify('+10 minutes'));

        // Passou do prazo original, mas está suspensa: não vence nem encerra por prazo.
        self::assertFalse($pauta->prazoVencidoEm($this->agora->modify('+2 hours')));
        self::assertFalse($pauta->encerrarSePrazoVenceu($this->agora->modify('+2 hours')));
        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());

        $pauta->retomar('Mesa', $this->agora->modify('+70 minutes'));

        // Ficou parada 60 minutos: o prazo de +30 vira +90.
        self::assertEquals($this->agora->modify('+90 minutes'), $pauta->getPrazoAte());
        self::assertTrue($pauta->aceitaVotoEm($this->agora->modify('+80 minutes')));
        self::assertTrue($pauta->prazoVencidoEm($this->agora->modify('+90 minutes')));
    }

    #[TestDox('Suspensa não muda o prazo: "Retome a votação antes de mudar o prazo."')]
    public function testPrazoNaoMudaSuspensa(): void
    {
        $pauta = $this->pautaAberta();
        $pauta->suspender('Queda de energia', 'Mesa', $this->agora);

        $this->assertRecusa('Retome a votação antes de mudar o prazo.', fn () => $pauta->definirPrazo($this->agora->modify('+1 hour'), $this->agora));
    }

    #[TestDox('A mesa pode encerrar a pauta suspensa: a suspensão sai e o encerramento vale')]
    public function testEncerrarSuspensa(): void
    {
        $pauta = $this->pautaAberta();
        $pauta->suspender('Queda de energia', 'Mesa', $this->agora);

        $pauta->encerrar('Mesa', 'Fim da assembleia', $this->agora->modify('+5 minutes'));

        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertFalse($pauta->suspensa());
        self::assertNull($pauta->getSuspensaEm());
    }

    #[TestDox('Cancelar a pauta aberta: encerra com "Votação cancelada: motivo", guarda o cancelamento e é irreversível')]
    public function testCancelarAberta(): void
    {
        $pauta = $this->pautaAberta();
        $pauta->suspender('Queda de energia', 'Mesa', $this->agora);

        $pauta->cancelar(' Convocação irregular ', 'Comissão', $this->agora->modify('+1 minute'));

        self::assertSame(EstadoDaPauta::Encerrada, $pauta->getEstado());
        self::assertTrue($pauta->cancelada());
        self::assertFalse($pauta->suspensa());
        self::assertSame('Votação cancelada: Convocação irregular', $pauta->getMotivoEncerramento());
        self::assertSame('Convocação irregular', $pauta->getCanceladaMotivo());
        self::assertSame('Comissão', $pauta->getCanceladaPorNome());
        self::assertEquals($this->agora->modify('+1 minute'), $pauta->getCanceladaEm());
        $this->assertRecusa('Votação já cancelada.', fn () => $pauta->cancelar('Outro motivo', 'Mesa', $this->agora));
    }

    #[TestDox('Cancelar a pauta já encerrada (apurada) é recusado e nada muda')]
    public function testCancelarEncerradaRecusa(): void
    {
        $pauta = $this->pautaAberta();
        $pauta->encerrar('Mesa', '', $this->agora);

        $this->assertRecusa('Votação já apurada não pode ser cancelada: arquive se quiser tirá-la das listas.', fn () => $pauta->cancelar('Ata contestada', 'Comissão', $this->agora->modify('+1 minute')));

        self::assertFalse($pauta->cancelada());
    }

    #[TestDox('Devolver a pauta retirada: volta a aguardando, limpa a retirada e pode abrir; só retirada e sem arquivar')]
    public function testDevolver(): void
    {
        $pauta = $this->pautaAguardando();
        $this->assertRecusa('A pauta não está retirada.', fn () => $pauta->devolverAPauta('Mesa'));
        $pauta->retirarDePauta('Matéria adiada', 'Mesa', $this->agora);

        $pauta->devolverAPauta('Mesa');

        self::assertFalse($pauta->retirada());
        self::assertNull($pauta->getRetiradaEm());
        self::assertNull($pauta->getRetiradaMotivo());
        self::assertNull($pauta->getRetiradaPorNome());
        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
        $pauta->abrir('Mesa', 1, null, $this->agora);
        self::assertTrue($pauta->aceitaVotoEm($this->agora));
    }

    #[TestDox('Devolver com a assembleia arquivada é recusado até desarquivar')]
    public function testDevolverArquivada(): void
    {
        $pauta = $this->pautaAguardando();
        $pauta->retirarDePauta('Matéria adiada', 'Mesa', $this->agora);
        $pauta->getAssembleia()->arquivar('Assembleia concluída', 'Mesa', $this->agora);

        $this->assertRecusa('Votação arquivada: desarquive antes de devolver a pauta.', fn () => $pauta->devolverAPauta('Mesa'));

        $pauta->getAssembleia()->desarquivar('Mesa');
        $pauta->devolverAPauta('Mesa');
        self::assertFalse($pauta->retirada());
    }

    #[TestDox('Cancelar sem motivo de 5 caracteres: "Informe o motivo do cancelamento."')]
    public function testCancelarSemMotivo(): void
    {
        $pauta = $this->pautaAberta();

        $this->assertRecusa('Informe o motivo do cancelamento.', fn () => $pauta->cancelar('', 'Mesa', $this->agora));

        self::assertFalse($pauta->cancelada());
        self::assertSame(EstadoDaPauta::Aberta, $pauta->getEstado());
    }

    #[TestDox('Antes de abrir, cancelar equivale a retirar de pauta, com o motivo "Cancelada antes da abertura: ..."')]
    public function testCancelarAntesDeAbrirRetira(): void
    {
        $pauta = $this->pautaAguardando();

        $pauta->cancelar('Matéria adiada', 'Mesa', $this->agora);

        self::assertTrue($pauta->retirada());
        self::assertFalse($pauta->cancelada());
        self::assertSame('Cancelada antes da abertura: Matéria adiada', $pauta->getRetiradaMotivo());
    }

    #[TestDox('Cancelar pauta de assembleia em rascunho: "Rascunho não se cancela: pode ser excluído."')]
    public function testCancelarRascunho(): void
    {
        $pauta = new PautaDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G'), 1, 'Contas');

        $this->assertRecusa('Rascunho não se cancela: pode ser excluído.', fn () => $pauta->cancelar('Motivo válido', 'Mesa', $this->agora));
    }

    #[TestDox('Retirar de pauta: só publicada e ainda não aberta; guarda quando, motivo e quem; não abre mais')]
    public function testRetirar(): void
    {
        $pauta = $this->pautaAguardando();

        $pauta->retirarDePauta(' Matéria adiada para a próxima assembleia ', 'Comissão', $this->agora);

        self::assertTrue($pauta->retirada());
        self::assertSame(EstadoDaPauta::Aguardando, $pauta->getEstado());
        self::assertSame('Matéria adiada para a próxima assembleia', $pauta->getRetiradaMotivo());
        self::assertSame('Comissão', $pauta->getRetiradaPorNome());
        self::assertEquals($this->agora, $pauta->getRetiradaEm());
        $this->assertRecusa('Pauta retirada: não pode mais ser aberta.', fn () => $pauta->abrir('Mesa', 1, null, $this->agora));
        $this->assertRecusa('Pauta já retirada.', fn () => $pauta->retirarDePauta('Outro motivo', 'Mesa', $this->agora));
    }

    #[TestDox('Retirar recusa pauta aberta, encerrada, motivo curto, rascunho e assembleia arquivada')]
    public function testRetirarRecusas(): void
    {
        $aberta = $this->pautaAberta();
        $this->assertRecusa('A votação já está aberta: suspenda ou cancele.', fn () => $aberta->retirarDePauta('Motivo válido', 'Mesa', $this->agora));

        $encerrada = $this->pautaAberta();
        $encerrada->encerrar('Mesa', '', $this->agora);
        $this->assertRecusa('Só pauta publicada e ainda não aberta pode ser retirada.', fn () => $encerrada->retirarDePauta('Motivo válido', 'Mesa', $this->agora));

        $curto = $this->pautaAguardando();
        $this->assertRecusa('Informe o motivo da retirada.', fn () => $curto->retirarDePauta('adia', 'Mesa', $this->agora));
        self::assertFalse($curto->retirada());

        $rascunho = new PautaDaAssembleia(new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G'), 1, 'Contas');
        $this->assertRecusa('Rascunho não se retira de pauta: a pauta pode ser removida.', fn () => $rascunho->retirarDePauta('Motivo válido', 'Mesa', $this->agora));

        $arquivada = $this->pautaAguardando();
        $arquivada->getAssembleia()->arquivar('Encerrada sem deliberação', 'Mesa', $this->agora);
        $this->assertRecusa('Votação arquivada: desarquive antes de retirar a pauta.', fn () => $arquivada->retirarDePauta('Motivo válido', 'Mesa', $this->agora));
    }

    #[TestDox('Apurar pauta cancelada recusa: "Votação cancelada: as cédulas ficam lacradas e não são apuradas."')]
    public function testApurarCanceladaRecusa(): void
    {
        $pauta = $this->pautaAberta();
        $pauta->cancelar('Convocação irregular', 'Mesa', $this->agora);

        $this->assertRecusa(
            'Votação cancelada: as cédulas ficam lacradas e não são apuradas.',
            fn () => (new ApuracaoDaPauta())->apurar($pauta, '1.000000', 1),
        );
    }

    #[DataProvider('motivosCurtos')]
    #[TestDox('Motivo curto demais ("$motivo") é recusado em suspender, cancelar e retirar')]
    public function testMotivoCurto(string $motivo): void
    {
        $this->assertRecusa('Informe o motivo da suspensão.', fn () => $this->pautaAberta()->suspender($motivo, 'Mesa', $this->agora));
        $this->assertRecusa('Informe o motivo do cancelamento.', fn () => $this->pautaAberta()->cancelar($motivo, 'Mesa', $this->agora));
        $this->assertRecusa('Informe o motivo da retirada.', fn () => $this->pautaAguardando()->retirarDePauta($motivo, 'Mesa', $this->agora));
    }

    /** @return iterable<string, array{string}> */
    public static function motivosCurtos(): iterable
    {
        yield 'vazio' => [''];
        yield 'espaços' => ['      '];
        yield 'quatro' => ['abcd'];
        yield 'quatro com espaços' => ['  abcd  '];
    }

    private function assertRecusa(string $mensagem, callable $acao): void
    {
        try {
            $acao();
            self::fail('Devia recusar: ' . $mensagem);
        } catch (OperacaoInvalidaNaAssembleiaException $recusa) {
            self::assertSame($mensagem, $recusa->getMessage());
        }
    }

    private function pautaAguardando(): PautaDaAssembleia
    {
        $assembleia = new Assembleia(new Tenant(), 'AGO', PesoDoVoto::Unidade, 'G');
        $pauta = new PautaDaAssembleia($assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $assembleia->publicar($this->agora->modify('-1 hour'));

        return $pauta;
    }

    private function pautaAberta(?string $prazo = null): PautaDaAssembleia
    {
        $pauta = $this->pautaAguardando();
        $pauta->abrir('Mesa', 1, null === $prazo ? null : $this->agora->modify($prazo), $this->agora);

        return $pauta;
    }
}
