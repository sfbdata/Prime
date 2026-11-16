<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Command\FaxinarConectorCommand;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\CodigoDoConector;
use App\Conector\Entity\ConcessaoDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\PropostaDoConector;
use App\Conector\Entity\TokenDoConector;
use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;
use App\Conector\UseCase\FaxinarConectorUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A faxina do conector (spec mcp-remoto-oauth 9.9, 12.28 e 13.2): apaga o que venceu além do prazo, em todos os
 * escritórios, e não toca o que está em uso (token de acesso dentro do prazo, renovação de cadeia viva, a já girada que
 * a janela consulta, proposta pendente no prazo).
 */
#[CoversClass(FaxinarConectorUseCase::class)]
#[CoversClass(FaxinarConectorCommand::class)]
final class FaxinarConectorTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;
    use ClockSensitiveTrait;

    private const AGORA = '2026-11-16 10:00:00';

    private int $sequencia = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        self::mockTime(self::AGORA);
    }

    private function antes(string $intervalo): \DateTimeImmutable
    {
        return (new \DateTimeImmutable(self::AGORA))->modify('-' . $intervalo);
    }

    private function hash(): string
    {
        return hash('sha256', 'faxina' . uniqid('', true) . ++$this->sequencia);
    }

    private function consentimento(Tenant $escritorio, User $pessoa, ClienteDoConector $cliente): ConsentimentoDoConector
    {
        $consentimento = new ConsentimentoDoConector($pessoa, $escritorio, $cliente, ['mcp:leitura'], $this->antes('60 days'));
        $this->em()->persist($consentimento);

        return $consentimento;
    }

    private function concessao(ConsentimentoDoConector $consentimento, \DateTimeImmutable $autorizadoEm, ?\DateTimeImmutable $revogadaEm = null): ConcessaoDoConector
    {
        $concessao = new ConcessaoDoConector($consentimento, ['mcp:leitura'], 'https://bluejus.com.br/mcp', hash('sha256', 'x'), $autorizadoEm);
        if ($revogadaEm !== null) {
            $concessao->revogar(MotivoDaRevogacaoDaConcessao::RevogadaPelaPessoa, $revogadaEm);
        }
        $this->em()->persist($concessao);

        return $concessao;
    }

    private function codigo(ConcessaoDoConector $concessao, \DateTimeImmutable $criadoEm): CodigoDoConector
    {
        $codigo = new CodigoDoConector($this->hash(), $concessao, self::REDIRECT, self::CHALLENGE, $criadoEm);
        $this->em()->persist($codigo);

        return $codigo;
    }

    private function token(bool $acesso, ConcessaoDoConector $concessao, \DateTimeImmutable $criadoEm, ?TokenDoConector $geradoPor = null): TokenDoConector
    {
        $token = $acesso
            ? TokenDoConector::deAcesso($this->hash(), $concessao, $criadoEm, $geradoPor)
            : TokenDoConector::deRenovacao($this->hash(), $concessao, $criadoEm, $geradoPor);
        $this->em()->persist($token);

        return $token;
    }

    private function proposta(ConsentimentoDoConector $consentimento, \DateTimeImmutable $criadaEm, bool $confirmada = false): PropostaDoConector
    {
        $proposta = new PropostaDoConector($this->hash(), $consentimento, 'atualizar_cliente', 'cadastrar', ['nome' => 'Fulano'], hash('sha256', 'dados'), 'Cadastrar Fulano', $criadaEm);
        if ($confirmada) {
            $proposta->confirmar([1], $criadaEm->modify('+1 minute'));
        }
        $this->em()->persist($proposta);

        return $proposta;
    }

    /**
     * @param array<string, CodigoDoConector|ConcessaoDoConector|TokenDoConector|PropostaDoConector> $entidades
     *
     * @return array<string, bool> cada uma ainda está no banco?
     */
    private function existem(array $entidades): array
    {
        $this->em()->clear();
        $existem = [];
        foreach ($entidades as $nome => $entidade) {
            $existem[$nome] = $this->em()->find($entidade::class, $entidade->getId()) !== null;
        }

        return $existem;
    }

    #[TestDox('Apaga código e token vencidos há mais de 1 dia, concessão vencida ou revogada há mais de 30 dias (com a cascata) e proposta decidida ou vencida, em todos os escritórios; o que está em uso fica')]
    public function testFaxinaApagaSoOQueVenceu(): void
    {
        [$a, $b] = [$this->escritorio(), $this->escritorio()];
        $pessoa = $this->pessoa();
        $cliente = $this->appDeIa();
        [$doA, $doB] = [$this->consentimento($a, $pessoa, $cliente), $this->consentimento($b, $pessoa, $cliente)];

        // Cadeia viva, autorizada há 3 dias, já girada uma vez.
        $viva = $this->concessao($doA, $this->antes('3 days'));
        $codigoVelho = $this->codigo($viva, $this->antes('3 days'));
        $acessoVelho = $this->token(true, $viva, $this->antes('3 days'));
        $renovacaoGirada = $this->token(false, $viva, $this->antes('3 days'));
        $acessoRecente = $this->token(true, $viva, $this->antes('90 minutes'), $renovacaoGirada);
        $renovacaoViva = $this->token(false, $viva, $this->antes('90 minutes'), $renovacaoGirada);
        $acessoEmUso = $this->token(true, $viva, $this->antes('10 minutes'));
        $codigoRecente = $this->codigo($viva, $this->antes('2 hours'));

        // Encerradas: vencida há 33 dias (escritório B), revogada há 31 dias, vencida há 20 dias, revogada há 29 dias.
        $vencidaHa33 = $this->concessao($doB, $this->antes('40 days'));
        $tokenDaVencida = $this->token(false, $vencidaHa33, $this->antes('40 days'));
        $revogadaHa31 = $this->concessao($doA, $this->antes('32 days'), $this->antes('31 days'));
        $vencidaHa20 = $this->concessao($doA, $this->antes('27 days'));
        $renovacaoDaVencidaHa20 = $this->token(false, $vencidaHa20, $this->antes('27 days'));
        $revogadaHa29 = $this->concessao($doA, $this->antes('29 days'), $this->antes('29 days'));

        $pendente = $this->proposta($doA, $this->antes('5 minutes'));
        $vencida = $this->proposta($doA, $this->antes('11 minutes'));
        $confirmada = $this->proposta($doB, $this->antes('2 minutes'), confirmada: true);
        $this->em()->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:conector:faxinar'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('2 concessão(ões)', $tester->getDisplay());

        self::assertSame([
            'viva' => true, 'acessoRecente' => true, 'renovacaoViva' => true, 'renovacaoGirada' => true, 'acessoEmUso' => true,
            'codigoRecente' => true, 'vencidaHa20' => true, 'revogadaHa29' => true, 'pendente' => true,
            'codigoVelho' => false, 'acessoVelho' => false, 'vencidaHa33' => false, 'tokenDaVencida' => false,
            'revogadaHa31' => false, 'renovacaoDaVencidaHa20' => false, 'vencida' => false, 'confirmada' => false,
        ], $this->existem(compact(
            'viva',
            'acessoRecente',
            'renovacaoViva',
            'renovacaoGirada',
            'acessoEmUso',
            'codigoRecente',
            'vencidaHa20',
            'revogadaHa29',
            'pendente',
            'codigoVelho',
            'acessoVelho',
            'vencidaHa33',
            'tokenDaVencida',
            'revogadaHa31',
            'renovacaoDaVencidaHa20',
            'vencida',
            'confirmada',
        )));
        self::assertNotNull($this->em()->find(ConsentimentoDoConector::class, $doB->getId()), 'o consentimento fica');
        $filha = $this->em()->find(TokenDoConector::class, $renovacaoViva->getId());
        self::assertSame($renovacaoGirada->getId(), $filha?->getGeradoPor()?->getId(), 'a cadeia da janela de tolerância continua ligada');
    }
}
