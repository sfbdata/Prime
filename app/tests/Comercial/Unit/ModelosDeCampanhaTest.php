<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Os fixos do desenho 05 (dc L.504-515, spec §8.2) e a validação do que a tela manda. */
#[CoversClass(ModelosDeCampanha::class)]
#[CoversClass(MensagemDaCampanha::class)]
final class ModelosDeCampanhaTest extends TestCase
{
    #[TestDox('Os 11 fixos na ordem do dc, com os rótulos e os ícones literais')]
    public function testOrdemENomes(): void
    {
        $fixos = (new ModelosDeCampanha())->fixos();

        self::assertSame(
            ['convite', 'vagas', 'obrigado', 'validar', 'boasvindas', 'primeiros', 'espaco90', 'espaco100', 'pedido', 'news', 'novidades'],
            array_column($fixos, 'chave'),
        );
        self::assertSame('Convite · novos escritórios', $fixos[0]['nome']);
        self::assertSame('bi-envelope-heart', $fixos[0]['icone']);
        self::assertSame('Newsletter mensal', $fixos[9]['nome']);
        self::assertSame(['simples', 'simples', 'simples', 'blocos', 'blocos', 'blocos', 'blocos', 'blocos', 'blocos', 'blocos', 'simples'], array_column($fixos, 'modo'));
    }

    #[TestDox('O validar sai sem o código 482 917 (Q5) e com o botão na chave validar; as cópias de automação são blocos')]
    public function testValidarSemCodigo(): void
    {
        $validar = (new ModelosDeCampanha())->fixo('validar');

        self::assertNotNull($validar);
        self::assertStringNotContainsString('482 917', (string) json_encode($validar, JSON_UNESCAPED_UNICODE));
        self::assertSame("O link vale por 24 horas.\n\nSe você não criou esta conta, ignore este e-mail.", $validar['blocos'][2]['a'] ?? null);
        self::assertSame('validar', $validar['blocos'][1]['b'] ?? null);
    }

    #[TestDox('A mensagem da tela: modo desconhecido, campo que não é texto e bloco de tipo desconhecido são recusados')]
    public function testRecusas(): void
    {
        foreach ([['modo' => 'xyz'], ['modo' => 'blocos', 'assunto' => ['x']], ['modo' => 'blocos', 'blocos' => [['t' => 'script']]]] as $dados) {
            try {
                MensagemDaCampanha::deArray($dados);
                self::fail('deveria recusar ' . json_encode($dados));
            } catch (CampanhaRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[TestDox('HTML acima de 200 KB é recusado; a chave fora do padrão vira "campanha"')]
    public function testLimiteEChave(): void
    {
        $this->expectExceptionMessage('O HTML passa de 200 KB.');

        self::assertSame('campanha', MensagemDaCampanha::deArray(['modo' => 'blocos', 'chave' => '../x'])->chave);
        MensagemDaCampanha::deArray(['modo' => 'html', 'html' => str_repeat('a', 204801)]);
    }
}
