<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Tenant\Service\SituacaoDaConta;
use App\TrabalhoExterno\DTO\Autor;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Enum\Situacao;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** O Convidado de escritório fora do ar só lê (docs/specs/te-convidado-escritorio-fora-do-ar.md). */
#[CoversClass(SituacaoDaConta::class)]
#[CoversClass(PoliticaDaDemanda::class)]
#[CoversClass(Contexto::class)]
final class ConvidadoDeEscritorioForaDoArTest extends TestCase
{
    #[TestDox('fora do ar: suspenso e excluído (os dois inativos); ativo não')]
    public function testSituacaoDaConta(): void
    {
        $situacao = new SituacaoDaConta();
        $conta = Apoio::tenant(1);

        $conta->setIsActive(true);
        self::assertFalse($situacao->foraDoAr($conta));
        $conta->setIsActive(false);
        self::assertTrue($situacao->foraDoAr($conta), 'suspenso');
        $conta->setExcluidoEm(new \DateTimeImmutable());
        self::assertTrue($situacao->foraDoAr($conta), 'excluído');
    }

    #[TestDox('Convidado com todas as permissões e dono fora do ar: vê e baixa, mas não age, não conversa, não registra, não anexa nem apaga')]
    public function testConvidadoSoLe(): void
    {
        $politica = new PoliticaDaDemanda();
        [$demanda, $convidado] = $this->demandaComConvidado();
        $base = new Contexto($convidado, null, Perfil::Convidado, new ConfiguracaoCentral());
        $convite = $this->convite($demanda, $convidado);
        $noAr = $base->comoConvidado($convite);
        $foraDoAr = $base->comoConvidado($convite, true);
        $propria = $demanda->registrar(Autor::de($convidado, Perfil::Convidado), 'minha', TipoMensagem::Normal);

        self::assertNotSame([], $politica->botoes($noAr, $demanda), 'premissa: no ar ele tem botões');
        self::assertTrue($politica->podeConversar($noAr, $demanda));
        self::assertTrue($politica->podeRegistrarAndamento($noAr, $demanda));
        self::assertTrue($politica->podeAnexar($noAr, $demanda));
        self::assertTrue($politica->podeApagarMensagem($noAr, $propria));

        self::assertTrue($foraDoAr->somenteLeitura());
        self::assertSame([], $politica->botoes($foraDoAr, $demanda));
        self::assertFalse($politica->podeConversar($foraDoAr, $demanda));
        self::assertFalse($politica->podeRegistrarAndamento($foraDoAr, $demanda));
        self::assertFalse($politica->podeAnexar($foraDoAr, $demanda));
        self::assertFalse($politica->podeApagarMensagem($foraDoAr, $propria));
        self::assertTrue($politica->podeVer($foraDoAr, $demanda));
        self::assertTrue($politica->podeBaixar($foraDoAr, $demanda));
    }

    /** @return array{0: DemandaExterna, 1: \App\Entity\Auth\User} */
    private function demandaComConvidado(): array
    {
        return [Apoio::demanda(Situacao::Execucao), Apoio::usuario(40)];
    }

    private function convite(DemandaExterna $demanda, \App\Entity\Auth\User $convidado): ConviteDemanda
    {
        $todas = array_fill_keys(array_keys(ConviteDemanda::PERMISSOES), true);
        $convite = new ConviteDemanda($demanda, 'Convidado', (string) $convidado->getEmail(), null, new \DateTimeImmutable('+30 days'), str_repeat('a', 64), $todas, Apoio::autor());
        $convite->aceitar($convidado, new \DateTimeImmutable('today'));

        return $convite;
    }
}
