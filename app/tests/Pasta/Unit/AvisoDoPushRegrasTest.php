<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Disponibilidade;
use App\Pasta\DTO\AvisoDaPastaOutput;
use App\Pasta\DTO\AvisosDoPushOutput;
use App\Pasta\DTO\ChavesDoAvisoDoPush;
use App\Pasta\DTO\MovimentacaoDoAvisoOutput;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Service\AgrupadorDoAvisoDoPush;
use App\Pasta\Service\DestinosDoAvisoDoPush;
use App\Pasta\Service\PadroesDoAvisoDoPush;
use App\Processo\Entity\Processo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As regras do "Avisar quando chegar" que não precisam de banco (spec push-avisar-quando-chegar
 * §1-§4): os padrões, a decisão de quem recebe, o agrupamento por pasta, a mensagem e o cartão.
 */
#[CoversClass(PadroesDoAvisoDoPush::class)]
#[CoversClass(ChavesDoAvisoDoPush::class)]
#[CoversClass(DestinosDoAvisoDoPush::class)]
#[CoversClass(AgrupadorDoAvisoDoPush::class)]
#[CoversClass(AvisoDaPastaOutput::class)]
#[CoversClass(MovimentacaoDoAvisoOutput::class)]
#[CoversClass(AvisosDoPushOutput::class)]
#[CoversClass(AvisoDoPushDaPasta::class)]
final class AvisoDoPushRegrasTest extends TestCase
{
    #[TestDox('Padrões: responsável e e-mail ligados (como no desenho); IA desligada (D2, decidido pelo dono em 19/10)')]
    public function testPadroes(): void
    {
        $chaves = ChavesDoAvisoDoPush::da(null);

        self::assertTrue($chaves->responsavel);
        self::assertTrue($chaves->email);
        self::assertFalse($chaves->ia);
        self::assertSame([true, true, false], array_map(PadroesDoAvisoDoPush::de(...), ChaveDoAvisoDoPush::cases()));
    }

    #[TestDox('A linha nasce dos padrões, muda só a chave pedida para o estado pedido e guarda quem mexeu')]
    public function testLinhaAlterna(): void
    {
        $quem = new User();
        $linha = new AvisoDoPushDaPasta(new Tenant(), new Pasta(), null, new \DateTimeImmutable('2026-10-01'));
        $linha->definir(ChaveDoAvisoDoPush::Ia, true, $quem, new \DateTimeImmutable('2026-10-19 10:00'));

        $chaves = ChavesDoAvisoDoPush::da($linha);
        self::assertTrue($chaves->responsavel);
        self::assertTrue($chaves->email);
        self::assertTrue($chaves->ia);
        self::assertSame($quem, $linha->getAtualizadoPor());
        self::assertSame('2026-10-19 10:00', $linha->getAtualizadoEm()->format('Y-m-d H:i'));
    }

    /** @return iterable<string, array{ChavesDoAvisoDoPush, bool, bool, bool, array{bool, bool, bool}}> */
    public static function casosDeDecisao(): iterable
    {
        $padrao = new ChavesDoAvisoDoPush(true, true, false);
        $tudo = new ChavesDoAvisoDoPush(true, true, true);
        yield 'padrões, responsável apto' => [$padrao, true, false, true, [true, true, false]];
        yield 'responsável inativo (ou sem acesso): ninguém' => [$tudo, false, false, true, [false, false, false]];
        yield 'e-mail descadastrado: só o sino' => [$padrao, true, true, true, [true, false, false]];
        yield 'aviso ao responsável desligado: nem e-mail' => [new ChavesDoAvisoDoPush(false, true, false), true, false, true, [false, false, false]];
        yield 'e-mail desligado: só o sino' => [new ChavesDoAvisoDoPush(true, false, false), true, false, true, [true, false, false]];
        yield 'IA ligada e disponível' => [$tudo, true, false, true, [true, true, true]];
        yield 'IA ligada e indisponível' => [$tudo, true, false, false, [true, true, false]];
        yield 'IA ligada sem o aviso ao responsável' => [new ChavesDoAvisoDoPush(false, false, true), true, false, true, [false, false, true]];
    }

    /** @param array{bool, bool, bool} $esperado sino, e-mail, IA */
    #[DataProvider('casosDeDecisao')]
    #[TestDox('Quem recebe: $_dataName')]
    public function testDecisao(ChavesDoAvisoDoPush $chaves, bool $apto, bool $descadastrado, bool $iaDisponivel, array $esperado): void
    {
        $destinos = DestinosDoAvisoDoPush::decidir($chaves, $apto, $descadastrado, $iaDisponivel);

        self::assertSame($esperado, [$destinos->sino, $destinos->email, $destinos->ia]);
        self::assertSame(in_array(true, $esperado, true), $destinos->algum());
    }

    #[TestDox('Agrupa por pasta: a pasta com dois processos recebe as duas publicações num aviso; o processo em duas pastas avisa as duas')]
    public function testAgrupamento(): void
    {
        [$p1, $p2, $p3] = [$this->processo(1), $this->processo(2), $this->processo(3)];
        [$pastaA, $pastaB] = [$this->pasta(10), $this->pasta(20)];
        $vinculos = [new PastaProcesso($pastaA, $p1), new PastaProcesso($pastaA, $p2), new PastaProcesso($pastaB, $p2)];
        $pub1 = $this->publicacao($p1);
        $pub2 = $this->publicacao($p2);
        $semPasta = $this->publicacao($p3);

        $grupos = AgrupadorDoAvisoDoPush::agrupar($vinculos, [$pub1, $pub2, $semPasta]);

        self::assertCount(2, $grupos);
        self::assertSame($pastaA, $grupos[0]['pasta']);
        self::assertSame([$pub1, $pub2], $grupos[0]['publicacoes']);
        self::assertSame($pastaB, $grupos[1]['pasta']);
        self::assertSame([$pub2], $grupos[1]['publicacoes']);
    }

    #[TestDox('Mensagem: uma ("Nova movimentação na Pasta 12/2026: Intimação de 02/10/2026") ou várias ("3 novas movimentações na Pasta 12/2026")')]
    public function testMensagem(): void
    {
        $intimacao = new MovimentacaoDoAvisoOutput('Intimação', '02/10/2026', 'Vara', 'Resumo');

        self::assertSame('Nova movimentação na Pasta 12/2026: Intimação de 02/10/2026', (new AvisoDaPastaOutput(1, '12/2026', [$intimacao]))->mensagem());
        self::assertSame('3 novas movimentações na Pasta 12/2026', (new AvisoDaPastaOutput(1, '12/2026', [$intimacao, $intimacao, $intimacao]))->mensagem());
        self::assertSame('Nova movimentação na Pasta 7: Movimentação', (new AvisoDaPastaOutput(1, '7', [new MovimentacaoDoAvisoOutput('Movimentação', null, null, null)]))->mensagem());
    }

    #[TestDox('Movimentação: resumo em texto puro de até 220 caracteres (nunca o teor inteiro), órgão ou tribunal, tipo padrão')]
    public function testMovimentacaoResumida(): void
    {
        $pub = new PublicacaoDjen();
        $pub->setTexto('<p>' . str_repeat('palavra ', 100) . '</p>');
        $pub->setSiglaTribunal('TJDFT');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-02'));

        $mov = MovimentacaoDoAvisoOutput::dePublicacao($pub);

        self::assertSame('Movimentação', $mov->tipo);
        self::assertSame('02/10/2026', $mov->data);
        self::assertSame('TJDFT', $mov->orgao);
        self::assertNotNull($mov->resumo);
        self::assertLessThanOrEqual(221, mb_strlen($mov->resumo));
        self::assertStringNotContainsString('<p>', $mov->resumo);
    }

    #[TestDox('E-mail lista até 20 movimentações e conta o resto')]
    public function testLimiteDoEmail(): void
    {
        $aviso = new AvisoDaPastaOutput(1, '1', array_fill(0, 23, new MovimentacaoDoAvisoOutput('Intimação', null, null, null)));

        self::assertCount(20, $aviso->movimentacoesDoEmail());
        self::assertSame(3, $aviso->restantesForaDoEmail());
    }

    #[TestDox('Cartão: rótulos do desenho na ordem; e-mail travado sem o responsável; IA travada com o motivo real')]
    public function testCartao(): void
    {
        $cartao = AvisosDoPushOutput::montar(new ChavesDoAvisoDoPush(false, true, false), Disponibilidade::DesligadaNoEscritorio->mensagem() . '.');

        self::assertSame(['Responsável da pasta', 'Por e-mail', 'Análise da BlueJus IA'], array_map(static fn ($i): string => $i->rotulo, $cartao->itens));
        self::assertSame([false, true, false], array_map(static fn ($i): bool => $i->ligado, $cartao->itens));
        self::assertSame([false, true, true], array_map(static fn ($i): bool => $i->travado, $cartao->itens));
        self::assertSame('Ligue o aviso ao responsável para receber por e-mail.', $cartao->itens[1]->dica);
        self::assertSame('BlueJus IA desligada neste escritório. Peça ao administrador.', $cartao->itens[2]->dica);

        $livre = AvisosDoPushOutput::montar(new ChavesDoAvisoDoPush(true, true, false), null);
        self::assertSame([false, false, false], array_map(static fn ($i): bool => $i->travado, $livre->itens));
        self::assertSame(['chave' => 'ia', 'ligado' => false, 'travado' => false, 'dica' => null], $livre->paraJson()[2]);
    }

    private function processo(int $id): Processo
    {
        $processo = new Processo();
        $this->definirId($processo, $id);

        return $processo;
    }

    private function pasta(int $id): Pasta
    {
        $pasta = new Pasta();
        $this->definirId($pasta, $id);

        return $pasta;
    }

    private function publicacao(Processo $processo): PublicacaoDjen
    {
        $pub = new PublicacaoDjen();
        $pub->setProcesso($processo);

        return $pub;
    }

    private function definirId(object $entidade, int $id): void
    {
        (new \ReflectionProperty($entidade, 'id'))->setValue($entidade, $id);
    }
}
