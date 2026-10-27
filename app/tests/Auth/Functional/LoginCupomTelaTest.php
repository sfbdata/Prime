<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Contribua\Entity\BonusDeEspaco;
use App\Controller\SecurityController;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * Cupom de indicação no "Criar conta" do desenho 10 VS 25.10.26 (spec `login-cupom-e-facebook.md`, PR A): a chave
 * "Tenho um cupom de indicação" e o campo ficam entre o escritório e o e-mail; `?ind=`, `?codigo=` e `?ref=` chegam
 * à tela já no formato `BJ-` para o JS abrir o cadastro com o cupom preenchido.
 *
 * Posição, cor e a animação da chave não aparecem aqui: são do smoke do dono.
 */
#[CoversClass(SecurityController::class)]
final class LoginCupomTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('?ind=, ?codigo= e ?ref= chegam à tela como BJ- + código em caixa alta')]
    #[TestWith(['ind=BJ-QWER2345'])]
    #[TestWith(['ind=bj-qwer2345'])]
    #[TestWith(['codigo=qwer2345'])]
    #[TestWith(['ref=QWER2345'])]
    #[TestWith(['abrir=cadastro&ind=QWER2345'])]
    public function testCupomDaUrl(string $query): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login?' . $query);

        self::assertResponseIsSuccessful();
        self::assertSame('BJ-QWER2345', $crawler->filter('#lgRaiz')->attr('data-ind'));
    }

    #[TestDox('valor fora do formato (curto, longo, com símbolo ou em lista) não chega à tela e não derruba a página')]
    #[TestWith(['ind=BJ-12'])]
    #[TestWith(['ind=ABCDEFGHIJKLM'])]
    #[TestWith(['ind=%3Cscript%3E'])]
    #[TestWith(['ind[]=QWER2345'])]
    public function testCupomForaDoFormato(string $query): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login?' . $query);

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('#lgRaiz')->attr('data-ind'));
    }

    #[TestDox('a chave e o campo do cupom são filhos diretos do passo "cad", entre o escritório e o e-mail, como no desenho')]
    public function testArranjoDoCupom(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        $filhos = $crawler->filter('.lg-md__passo[data-passo="cad"] > *');
        $ids = [];
        foreach ($filhos as $no) {
            $ids[] = $no->getAttribute('id') ?: $no->getAttribute('class');
        }
        $escritorio = array_search('lgCdEscCampo', $ids, true);
        self::assertIsInt($escritorio, 'o rótulo do escritório é filho direto do passo');
        self::assertSame('lgCdIndTg', $ids[$escritorio + 1], 'a chave vem logo depois do escritório');
        self::assertSame('lgCdIndCampo', $ids[$escritorio + 2], 'o campo do cupom vem logo depois da chave');
        self::assertSame('lgCdEmailCampo', $ids[$escritorio + 3], 'o e-mail vem depois do cupom');

        self::assertSame('Tenho um cupom de indicação', trim($crawler->filter('#lgCdIndTg')->text()));
        self::assertSame('button', $crawler->filter('#lgCdIndTg')->attr('type'));
        self::assertSame('false', $crawler->filter('#lgCdIndTg')->attr('aria-checked'));
        self::assertSame(1, $crawler->filter('#lgCdIndTg > .lg-chave > .lg-chave__bola')->count());
        self::assertSame('BJ-XXXXXX', $crawler->filter('#lgCdIndCampo > input#lgCdInd')->attr('placeholder'));
        self::assertSame('11', $crawler->filter('#lgCdInd')->attr('maxlength'));
        self::assertNotNull($crawler->filter('#lgCdIndCampo')->attr('hidden'), 'o campo começa fechado');
    }

    #[TestDox('o texto de status da conferência ao vivo existe vazio e escondido, dentro do rótulo do cupom, depois do campo (desenho 10)')]
    public function testTextoDeStatusDoCupom(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login?abrir=cadastro');

        $status = $crawler->filter('#lgCdIndCampo > #lgCdInd + #lgCdIndStatus');
        self::assertSame(1, $status->count(), 'o status é o irmão logo depois do campo, dentro do rótulo');
        self::assertSame('', trim($status->text()));
        self::assertNotNull($status->attr('hidden'));
        self::assertSame('/cadastro/cupom/conferir', $status->attr('data-url'));
        self::assertSame(
            sprintf('Cupom válido · você ganha %d GB extras no 1º mês', BonusDeEspaco::GIGABYTES_DA_INDICACAO),
            $status->attr('data-valido'),
            'o valor prometido vem da constante do bônus concedido',
        );
        self::assertSame('Cupom não encontrado. Confira com quem te indicou.', $status->attr('data-nao-encontrado'));
    }

    #[TestDox('sem o nome de quem indicou (decisão do dono); o bônus de 5 GB é o real e vem da constante')]
    public function testSemONomeDeQuemIndicou(): void
    {
        $client = static::createClient();

        $client->request('GET', '/login?abrir=cadastro&ind=QWER2345');

        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('indicado por', $html);
    }
}
