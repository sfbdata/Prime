<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Integration;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AjustarBatidaInput;
use App\Ponto\DTO\AssinarFolhaInput;
use App\Ponto\DTO\DesconsiderarBatidaInput;
use App\Ponto\DTO\RestaurarBatidaInput;
use App\Ponto\Entity\PontoAjuste;
use App\Ponto\Model\MarcacaoDoPonto;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\EstadoDoAceiteDaFolha;
use App\Ponto\UseCase\AjustarBatidaUseCase;
use App\Ponto\UseCase\DesconsiderarBatidaUseCase;
use App\Ponto\UseCase\RestaurarBatidaUseCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * O ajuste da E10 feito pelo gestor depois do aceite, de ponta a ponta (spec ponto-desenho-0811, E16, decisões 1, 3 e
 * 4; E10). A Maria assina a folha de outubro pelo `AssinarFolhaDoPontoUseCase` e a gestora corrige a saída do dia 6
 * pelos UseCases da ficha (desconsiderar, restaurar e ajustar o horário), como a tela faz, sem UPDATE no banco. A
 * folha muda, então o aceite não prova mais o que a Maria assinou: "Alterada depois do aceite", o "Assinar folha"
 * volta e a folha emitida depois sai sem carimbo.
 */
#[Group('ponto')]
#[CoversClass(EstadoDoAceiteDaFolha::class)]
#[CoversClass(DesconsiderarBatidaUseCase::class)]
#[CoversClass(RestaurarBatidaUseCase::class)]
#[CoversClass(AjustarBatidaUseCase::class)]
final class AjusteDaBatidaDepoisDoAceiteTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private Tenant $escritorio;
    private User $maria;
    private User $gestora;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        $this->segredo('segredo-de-teste-do-ajuste-depois-do-aceite');
        $this->escritorio = $this->escritorio('Escritório do ajuste depois do aceite');
        $this->maria = $this->pessoaComOutubro($this->escritorio);
        $this->gestora = $this->pessoa('Gestora do Ponto');
        $this->vincular($this->gestora, $this->escritorio, ['modules.ponto.view', 'admin.users.manage']);
    }

    #[TestDox('Desconsiderar a saída do dia 6 depois do aceite: "Alterada depois do aceite", o "Assinar folha" volta e a folha nova sai sem carimbo')]
    public function testDesconsiderarDepoisDoAceite(): void
    {
        $this->assinarEConferirQueVale();

        $this->desconsiderarASaidaDoDia6();

        $this->assertAlteradaDepoisDoAceite();
    }

    #[TestDox('Restaurar depois do aceite a saída do dia 6 desconsiderada antes dele: "Alterada depois do aceite", o "Assinar folha" volta e a folha nova sai sem carimbo')]
    public function testRestaurarDepoisDoAceite(): void
    {
        $desconsideracao = $this->desconsiderarASaidaDoDia6();
        $this->assinarEConferirQueVale();

        $this->useCase(RestaurarBatidaUseCase::class)->executar(new RestaurarBatidaInput($this->escritorio, $this->maria, (int) $desconsideracao->getId(), 'Era válida.', $this->gestora));

        $this->assertAlteradaDepoisDoAceite();
    }

    #[TestDox('Ajustar o horário da saída do dia 6 depois do aceite (das 17:00 para as 17:30): "Alterada depois do aceite", o "Assinar folha" volta e a folha nova sai sem carimbo')]
    public function testAjustarOHorarioDepoisDoAceite(): void
    {
        $this->assinarEConferirQueVale();
        $saida = $this->saidaDoDia6();

        $this->useCase(AjustarBatidaUseCase::class)->executar(new AjustarBatidaInput(
            $this->escritorio,
            $this->maria,
            $saida->alvo(),
            $saida->cabeca(),
            'saida',
            new \DateTimeImmutable('2026-10-06 17:30:00'),
            'Saiu mais tarde.',
            $this->gestora,
        ));

        $this->assertAlteradaDepoisDoAceite();
    }

    /** A Maria assina outubro e o aceite vale (o controle: sem o ajuste, a folha não muda). */
    private function assinarEConferirQueVale(): void
    {
        $this->assinarFolha()->executar(new AssinarFolhaInput(2026, 10, 'concordo', '', '189.6.10.2', 'Mozilla/5.0'), $this->maria, $this->escritorio);

        $estado = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        self::assertSame([false, false, 'Assinada em 09/11/2026'], [$estado->alterada, $estado->podeAssinar, $estado->textoDoSelo()], 'logo depois de assinar, o aceite vale');
    }

    private function desconsiderarASaidaDoDia6(): PontoAjuste
    {
        $saida = $this->saidaDoDia6();

        return $this->useCase(DesconsiderarBatidaUseCase::class)->executar(DesconsiderarBatidaInput::daTela($this->escritorio, $this->maria, $saida->alvo(), $saida->cabeca(), 'Batida em dobro.', $this->gestora));
    }

    /** A saída das 17:00 da terça, 6 de outubro, pelo leitor único (a batida efetiva, como a ficha mostra). */
    private function saidaDoDia6(): MarcacaoDoPonto
    {
        $batidas = static::getContainer()->get(RegistroPontoRepository::class)->findBatidasDoDia($this->maria, $this->escritorio, new \DateTimeImmutable('2026-10-06'));
        foreach ($batidas as $batida) {
            if ($batida->getTipo() === 'saida') {
                return $batida;
            }
        }

        throw new \LogicException('A saída do dia 6 não está na folha.');
    }

    private function assertAlteradaDepoisDoAceite(): void
    {
        $estado = $this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10);
        $emitida = $this->emitirFolha()->executar($this->escritorio, $this->maria, 2026, 10, $this->maria);

        self::assertSame([true, true, 'Alterada depois do aceite'], [$estado->alterada, $estado->podeAssinar, $estado->textoDoSelo()]);
        self::assertFalse($estado->carimbaAFolha());
        self::assertNull($emitida->carimbo, 'a folha emitida depois do ajuste sai sem carimbo');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T
     */
    private function useCase(string $classe): object
    {
        return static::getContainer()->get($classe);
    }
}
