<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Auth\Entity\ConfirmacaoEmail;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Tests\Support\CatalogoPtBr;
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
        self::assertSame("O link vale por 7 dias.\n\nSe você não criou esta conta, ignore este e-mail.", $validar['blocos'][2]['a'] ?? null);
        self::assertSame('validar', $validar['blocos'][1]['b'] ?? null);
        self::assertSame('Confirmar e criar minha senha', $validar['blocos'][1]['a'] ?? null);
    }

    #[TestDox('O "vale por 7 dias" do validar e do lembrete acompanha a validade do link de confirmação do cadastro')]
    public function testPrazoDoTextoIgualAoDoLink(): void
    {
        self::assertSame('+7 days', ConfirmacaoEmail::VALIDADE, 'mudou a validade do link: troque o "7 dias" no modelo validar e no lembrete_confirmacao');
        $lembrete = (string) file_get_contents(__DIR__ . '/../../../templates/email/comercial/automacao/lembrete_confirmacao.html.twig');
        // O texto do lembrete mora no catálogo (emails.comercial.lembrete_confirmacao, spec idioma-do-sistema §25.C).
        $catalogo = CatalogoPtBr::carregar();
        $textos = $catalogo['emails']['comercial']['lembrete_confirmacao'] ?? [];
        self::assertStringContainsString("b.texto('emails.comercial.lembrete_confirmacao.validade'|trans)", $lembrete);
        self::assertStringStartsWith('O link vale por 7 dias.', $textos['validade'] ?? '');
        self::assertStringContainsString("b.botao('emails.comercial.lembrete_confirmacao.botao'|trans, urlDeConfirmacao)", $lembrete);
        self::assertSame('Confirmar e criar minha senha', $textos['botao'] ?? null);
    }

    #[TestDox('F4 (dc 05 VS 21.10.26 L626): o modelo de cada data, literal: assunto + " · BlueJus", selo em maiúsculas, título sem o "!" + ", {nome}!", texto e o botão para o app')]
    public function testModelosDasDatas(): void
    {
        $modelos = new ModelosDeCampanha();

        self::assertSame([
            'chave' => 'data-natal', 'nome' => 'Natal', 'icone' => 'bi-gift', 'modo' => 'blocos', 'assunto' => 'Feliz Natal! · BlueJus', 'selo' => 'NATAL',
            'titulo' => 'Feliz Natal, {nome}!',
            'blocos' => [['t' => 'texto', 'a' => 'Que o Natal traga paz, alegria e união para você, a sua família e toda a equipe do escritório.'], ['t' => 'botao', 'a' => 'Abrir o BlueJus', 'b' => 'app']],
        ], $modelos->fixo('data-natal'));
        $tiradentes = $modelos->fixo('data-tiradentes');
        self::assertSame(['Feriado de Tiradentes · BlueJus', 'TIRADENTES', 'Feriado de Tiradentes, {nome}!'], [$tiradentes['assunto'] ?? null, $tiradentes['selo'] ?? null, $tiradentes['titulo'] ?? null]);
        self::assertSame('Lembrete: confira os prazos que vencem no dia útil seguinte ao feriado.', $tiradentes['blocos'][0]['a'] ?? null);
        self::assertSame('PÁSCOA', $modelos->fixo('data-pascoa')['selo'] ?? null, 'maiúsculas com acento');
        self::assertTrue($modelos->ehFixo('data-reveillon'));
        self::assertNull($modelos->fixo('data-xyz'));
        self::assertSame('data-natal', MensagemDaCampanha::deArray((array) $modelos->fixo('data-natal'))->chave, 'a chave da data passa pela mensagem');
    }

    #[TestDox('F4 (dc L627): as 17 datas entram na grade na ordem da próxima data a partir de hoje')]
    public function testDatasNaGrade(): void
    {
        $datas = (new ModelosDeCampanha())->datas(new \DateTimeImmutable('2026-10-21 10:00'));

        self::assertCount(17, $datas);
        self::assertSame(['data-finados', 'data-consciencia', 'data-natal', 'data-reveillon', 'data-ano'], \array_slice(array_column($datas, 'chave'), 0, 5));
        self::assertSame('data-criancas', $datas[16]['chave'], 'o Dia das Crianças de 2027 por último');
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
