<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\PreferenciaDoUsuario;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\PreferenciaDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\UseCase\DefinirAlertaDeVagasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O aviso de vaga nova (quem pode ligar e o que guarda) e a visita à aba Vagas (a referência de "nova"
 * é a visita anterior, que só avança depois de 30 min), spec VS 18.10.26 §4.7.
 */
#[CoversClass(DefinirAlertaDeVagasUseCase::class)]
#[CoversClass(PreferenciaDoUsuario::class)]
final class AlertaEVisitaTest extends TestCase
{
    #[TestDox('alerta: o externo com escritório liga e guarda o escritório; Convidado avulso, interno, prévia e sino desligado são 403')]
    public function testQuemLigaOAlerta(): void
    {
        $preferencias = $this->createMock(PreferenciaDoUsuarioRepository::class);
        $guardada = new PreferenciaDoUsuario(Apoio::usuario(3), PreferenciaDoUsuario::ALERTA_DE_VAGAS);
        $preferencias->method('obter')->willReturn($guardada);
        $preferencias->expects(self::once())->method('salvar')->with($guardada, true);
        $definir = new DefinirAlertaDeVagasUseCase($preferencias, new PoliticaDaVaga(new PoliticaDaDemanda()));

        $r = $definir->executar(Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2)), true);
        self::assertSame(['disponivel' => true, 'ligado' => true], $r['alerta']);
        self::assertSame(['ligado' => true, 'escritorioId' => 2], $guardada->getValor());

        $semSino = new ConfiguracaoCentral();
        $semSino->definirVagas(['sino' => false]);
        $recusas = [
            'Convidado avulso' => new Contexto(Apoio::usuario(4), null, Perfil::Convidado, new ConfiguracaoCentral()),
            'interno' => Apoio::contexto(Perfil::Master),
            'prévia' => new Contexto(Apoio::usuario(4), Apoio::tenant(2), Perfil::Solicitante, new ConfiguracaoCentral(), previa: true),
            'sino desligado' => Apoio::contexto(Perfil::Solicitante, Apoio::tenant(2), $semSino),
        ];
        foreach ($recusas as $caso => $ctx) {
            try {
                $definir->executar($ctx, true);
                self::fail('esperava a recusa: ' . $caso);
            } catch (AcaoNaoPermitida) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[TestDox('visita: a primeira não tem anterior; dentro de 30 min a anterior fica; depois dela, a última vira a anterior')]
    public function testVisitaAnterior(): void
    {
        $visita = new PreferenciaDoUsuario(Apoio::usuario(3), PreferenciaDoUsuario::ULTIMA_VISITA_VAGAS);
        $t0 = new \DateTimeImmutable('2026-10-19 10:00:00');
        $visita->registrarVisita($t0);
        self::assertNull($visita->visitaAnterior());

        $visita->registrarVisita($t0->modify('+20 minutes'));
        self::assertNull($visita->visitaAnterior(), 'recarregar não cria visita anterior');

        $visita->registrarVisita($t0->modify('+2 hours'));
        self::assertEquals($t0->modify('+20 minutes'), $visita->visitaAnterior());
        $visita->registrarVisita($t0->modify('+2 hours 5 minutes'));
        self::assertEquals($t0->modify('+20 minutes'), $visita->visitaAnterior(), 'o aviso da mesma visita não zera a referência');
    }
}
