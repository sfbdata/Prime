<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use App\Contribua\DTO\CampanhaView;
use App\Contribua\Entity\Campanha;
use App\Contribua\Enum\EstadoDaCampanha;
use App\Contribua\Repository\CampanhaParticipacaoRepository;
use App\Contribua\Service\CampanhaVigente;
use App\Contribua\Service\CofreDaChavePixDaCampanha;
use App\Contribua\Service\CpfDaCampanha;
use App\Contribua\Service\RegrasDaCampanha;
use App\Contribua\UseCase\MontarCampanhaViewUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegrasDaCampanha::class)]
#[CoversClass(CpfDaCampanha::class)]
#[CoversClass(CofreDaChavePixDaCampanha::class)]
#[CoversClass(MontarCampanhaViewUseCase::class)]
final class CampanhaServicosTest extends TestCase
{
    #[TestDox('As 10 regras da campanha padrão, literais do desenho')]
    public function testRegrasPadrao(): void
    {
        $regras = (new RegrasDaCampanha())->gerar(Campanha::padrao());

        self::assertCount(10, $regras);
        self::assertSame('Valor único de R$ 1,00, pago por Pix. Sem cartão, sem renovação automática e sem cobrança posterior.', $regras[0]);
        self::assertSame('Garantia do Desafio: se não gostar, peça a devolução em até 30 dias da inscrição e receba R$ 2,00 (o dobro do valor pago) por Pix em até 7 dias úteis. Não precisa justificar.', $regras[3]);
        self::assertSame('Uma inscrição por CPF e por inscrição na OAB.', $regras[5]);
        self::assertSame('Período: de 19/11/2026 a 30/11/2026. Pedidos de devolução feitos dentro do prazo são honrados mesmo depois do fim da campanha.', $regras[6]);
    }

    #[TestDox('Com triplo e vagas as regras mudam: R$ 3,00, "o triplo" e "Limite de N vagas."')]
    public function testRegrasTriploComVagas(): void
    {
        $c = Campanha::padrao();
        $c->aplicar('X', 'Y', 100, 3, 30, 7, new \DateTimeImmutable('2026-11-19'), new \DateTimeImmutable('2026-11-30'), 50, false, true, ['a'], true, null, new \DateTimeImmutable());
        $regras = (new RegrasDaCampanha())->gerar($c);

        self::assertStringContainsString('receba R$ 3,00 (o triplo do valor pago)', $regras[3]);
        self::assertSame('Uma inscrição por CPF e por inscrição na OAB. Limite de 50 vagas.', $regras[5]);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function cpfs(): iterable
    {
        yield 'válido sem máscara' => ['52998224725', true];
        yield 'válido com máscara' => ['529.982.247-25', true];
        yield 'dígito verificador errado' => ['52998224726', false];
        yield 'todos iguais' => ['11111111111', false];
        yield 'curto' => ['1234567890', false];
        yield 'vazio' => ['', false];
    }

    #[DataProvider('cpfs')]
    #[TestDox('CPF $cpf válido: $valido')]
    public function testCpf(string $cpf, bool $valido): void
    {
        self::assertSame($valido, (new CpfDaCampanha('segredo'))->valido($cpf));
    }

    #[TestDox('O hash do CPF ignora a máscara, depende do segredo e não contém o número')]
    public function testHashDoCpf(): void
    {
        $a = new CpfDaCampanha('segredo-a');
        $b = new CpfDaCampanha('segredo-b');

        self::assertSame($a->hash('529.982.247-25'), $a->hash('52998224725'));
        self::assertNotSame($a->hash('52998224725'), $b->hash('52998224725'));
        self::assertSame(64, \strlen($a->hash('52998224725')));
        self::assertStringNotContainsString('52998224725', $a->hash('52998224725'));
        self::assertSame('25', $a->doisUltimos('529.982.247-25'));
    }

    #[TestDox('O cofre cifra e decifra a chave Pix; segredo trocado ou texto quebrado devolve null')]
    public function testCofre(): void
    {
        $cofre = new CofreDaChavePixDaCampanha('segredo-a');
        $cifrado = $cofre->cifrar('fulano@exemplo.com');

        self::assertStringNotContainsString('fulano', $cifrado);
        self::assertNotSame($cifrado, $cofre->cifrar('fulano@exemplo.com'));
        self::assertSame('fulano@exemplo.com', $cofre->decifrar($cifrado));
        self::assertNull((new CofreDaChavePixDaCampanha('segredo-b'))->decifrar($cifrado));
        self::assertNull($cofre->decifrar('lixo'));
    }

    /** @return iterable<string, array{EstadoDaCampanha, bool, bool, bool, bool, bool, string}> */
    public static function visoes(): iterable
    {
        //                           estado                   master  aba    previa faixa  faq    status
        yield 'ativa, usuário' => [EstadoDaCampanha::Ativa, false, true, false, true, true, 'Inscrições abertas até 30/11/2026'];
        yield 'ativa, master' => [EstadoDaCampanha::Ativa, true, true, false, true, true, 'Inscrições abertas até 30/11/2026'];
        yield 'esgotada, usuário' => [EstadoDaCampanha::Esgotada, false, true, false, true, true, 'Vagas esgotadas'];
        yield 'agendada, usuário' => [EstadoDaCampanha::Agendada, false, false, true, false, false, 'Prévia do Master · começa em 19/11/2026'];
        yield 'agendada, master' => [EstadoDaCampanha::Agendada, true, true, true, true, false, 'Prévia do Master · começa em 19/11/2026'];
        yield 'encerrada, master' => [EstadoDaCampanha::Encerrada, true, true, true, true, false, 'Prévia do Master · encerrada em 30/11/2026'];
        yield 'desligada, master' => [EstadoDaCampanha::Desligada, true, true, true, false, false, ''];
        yield 'desligada, usuário' => [EstadoDaCampanha::Desligada, false, false, true, false, false, ''];
    }

    #[DataProvider('visoes')]
    #[TestDox('CampanhaView em $estado (master=$master)')]
    public function testView(EstadoDaCampanha $estado, bool $master, bool $aba, bool $previa, bool $faixa, bool $faq, string $status): void
    {
        $view = $this->montar()->montar(Campanha::padrao(), $estado, $master, false);

        self::assertSame($aba, $view->mostrarAba, 'mostrarAba');
        self::assertSame($previa, $view->abaPrevia, 'abaPrevia');
        self::assertSame($faixa, $view->mostrarFaixa, 'mostrarFaixa');
        self::assertSame($faq, $view->mostrarFaq, 'mostrarFaq');
        self::assertSame($status, $view->statusTexto);
    }

    #[TestDox('CampanhaView: textos derivados da configuração')]
    public function testTextosDaView(): void
    {
        $view = $this->montar()->montar(Campanha::padrao(), EstadoDaCampanha::Ativa, false, false);
        self::assertInstanceOf(CampanhaView::class, $view);

        self::assertSame('BlueJus Black', $view->nome);
        self::assertSame('BLACK FRIDAY BLUEJUS', $view->chamadaSelo);
        self::assertSame('BLACK FRIDAY', $view->chamadaAba);
        self::assertSame('R$ 1,00', $view->preco);
        self::assertSame('R$ 2,00', $view->devolve);
        self::assertSame('o dobro', $view->multTx);
        self::assertSame('Black Friday BlueJus: Desafio R$ 1,00', $view->abaTitulo);
        self::assertSame(' Durante a campanha BlueJus Black, existe também a inscrição opcional no Desafio, de R$ 1,00, com devolução de R$ 2,00 se você não gostar.', $view->faqPago);
        self::assertSame('Conhecer o Desafio', $view->botao);
        self::assertSame('Minha participação', $this->montar()->montar(Campanha::padrao(), EstadoDaCampanha::Ativa, false, true)->botao);
        self::assertSame('Prévia do Master: campanha agendada (só você vê)', $this->montar()->montar(Campanha::padrao(), EstadoDaCampanha::Agendada, true, false)->abaTitulo);
        self::assertSame('', $this->montar()->montar(Campanha::padrao(), EstadoDaCampanha::Encerrada, true, false)->faqPago);
    }

    private function montar(): MontarCampanhaViewUseCase
    {
        $participacoes = $this->createStub(CampanhaParticipacaoRepository::class);

        return new MontarCampanhaViewUseCase($this->createStub(CampanhaVigente::class), $participacoes);
    }
}
