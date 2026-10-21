<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use App\Auth\Entity\MensagemSuporte;
use App\Auth\Repository\MensagemSuporteRepository;
use App\Entity\Auth\User;
use App\Indicacao\Entity\IndicacaoAlerta;
use App\Indicacao\Repository\IndicacaoAlertaRepository;
use App\Master\DTO\MensagemDaCentral;
use App\Master\Notificacoes\LeitorDeAlertasDeIndicacao;
use App\Master\Notificacoes\LeitorDeSuporte;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os leitores de suporte e de alerta (o de sugestões passa pela VisaoDoMaster e se prova com banco). */
#[CoversClass(LeitorDeSuporte::class)]
#[CoversClass(LeitorDeAlertasDeIndicacao::class)]
#[CoversClass(MensagemDaCentral::class)]
final class LeitoresSemBancoTest extends TestCase
{
    #[TestDox('Suporte: login vira "Suporte" com "tela de login"; outra porta, "Fale conosco"; sem assunto, "Mensagem ao suporte"')]
    public function testSuporte(): void
    {
        $login = $this->suporte(3, 'Ana Lima', 'ana@exemplo.com', 'Esqueci a senha', 'login');
        $site = $this->suporte(4, '', 'bia@exemplo.com', null, 'site');
        $repo = $this->createStub(MensagemSuporteRepository::class);
        $repo->method('recentes')->willReturn([$login, $site]);
        $repo->method('find')->willReturnCallback(static fn (mixed $id): ?MensagemSuporte => $id === 3 ? $login : null);
        $leitor = new LeitorDeSuporte($repo);

        [$a, $b] = $leitor->todas();

        self::assertSame(['suporte', 3, 'sup', 'Suporte', 'Ana Lima', 'ana@exemplo.com', 'ana@exemplo.com · tela de login', 'Esqueci a senha', true, null], [$a->origem, $a->idOrigem, $a->tipo, $a->rotuloDoTipo, $a->deNome, $a->deEmail, $a->escritorio, $a->assunto, $a->podeResponder, $a->tenantId]);
        self::assertSame(['fale', 'Fale conosco', 'bia@exemplo.com', 'bia@exemplo.com', 'Mensagem ao suporte'], [$b->tipo, $b->rotuloDoTipo, $b->deNome, $b->escritorio, $b->assunto]);
        self::assertSame('ana@exemplo.com', $leitor->destinoDaResposta(3)?->email);
        self::assertNull($leitor->uma(9), 'mensagem apagada some sem erro');
        self::assertNull($leitor->destinoDaResposta(9));
        self::assertNull($leitor->respostaNaOrigem(3), 'suporte não guarda resposta na origem');
        self::assertTrue($a->contem('ESQUECI'));
        self::assertTrue($a->contem('ana@exemplo'));
        self::assertFalse($a->contem('outra coisa'));
    }

    #[TestDox('Alerta: do "Sistema BlueJus", no padrão "Fulano enviou 35 indicações nas últimas 24 horas", sem resposta')]
    public function testAlerta(): void
    {
        $alerta = new IndicacaoAlerta(new User(), 'Ana Lima enviou 35 convites em 24h', new \DateTimeImmutable('2026-10-20 09:00'));
        (new \ReflectionProperty(IndicacaoAlerta::class, 'id'))->setValue($alerta, 5);
        $repo = $this->createStub(IndicacaoAlertaRepository::class);
        $repo->method('ultimos')->willReturn([$alerta]);
        $repo->method('find')->willReturn($alerta);
        $leitor = new LeitorDeAlertasDeIndicacao($repo);

        $m = $leitor->todas()[0];

        self::assertSame(['alerta_indicacao', 5, 'alerta', 'Alerta', 'Sistema BlueJus', null, 'Disparo alto de indicações', false], [$m->origem, $m->idOrigem, $m->tipo, $m->rotuloDoTipo, $m->deNome, $m->deEmail, $m->assunto, $m->podeResponder]);
        self::assertSame('Ana Lima enviou 35 indicações nas últimas 24 horas', $m->texto, 'o texto gravado é reformatado só na leitura');
        self::assertSame('Ana enviou 1 indicação nas últimas 24 horas', LeitorDeAlertasDeIndicacao::textoDoAlerta('Ana enviou 1 convites em 24h'));
        self::assertSame('Outro formato qualquer', LeitorDeAlertasDeIndicacao::textoDoAlerta('Outro formato qualquer'));
        self::assertNull($leitor->respostaNaOrigem(5));
        self::assertNull($leitor->destinoDaResposta(5));
        $this->expectException(\DomainException::class);
        $leitor->registrarResposta(5, 'Oi', new User());
    }

    private function suporte(int $id, string $nome, string $email, ?string $assunto, string $origem): MensagemSuporte
    {
        $m = new MensagemSuporte($nome, $email, $assunto, 'Não consigo entrar no sistema', '127.0.0.1', null, null, $origem);
        (new \ReflectionProperty(MensagemSuporte::class, 'id'))->setValue($m, $id);

        return $m;
    }
}
