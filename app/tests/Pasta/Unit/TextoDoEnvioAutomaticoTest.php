<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Djen\Entity\PublicacaoDjen;
use App\Pasta\DTO\MovimentacaoAoClienteOutput;
use App\Pasta\Service\TextoDoEnvioAutomatico;
use App\Processo\Entity\Processo;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O conteúdo do e-mail automático ao cliente (spec push-envio-automatico §3 e D4): tipo, data, órgão e
 * o texto oficial sem HTML até 4.000 caracteres, com "Texto completo disponível com o escritório." se
 * cortado; segredo de justiça não leva nada da movimentação; tudo escapado e sem travessão.
 */
#[CoversClass(TextoDoEnvioAutomatico::class)]
#[CoversClass(MovimentacaoAoClienteOutput::class)]
final class TextoDoEnvioAutomaticoTest extends TestCase
{
    private function publicacao(int $id, string $texto, int $sigilo = 0, string $tipo = 'Intimação'): PublicacaoDjen
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070007');
        $processo->setNivelSigilo($sigilo);
        $publicacao = new PublicacaoDjen();
        DefineId::em($publicacao, $id);
        $publicacao->setTipoComunicacao($tipo);
        $publicacao->setNomeOrgao('1ª Vara Cível de Brasília');
        $publicacao->setSiglaTribunal('TJDFT');
        $publicacao->setDataDisponibilizacao(new \DateTimeImmutable('2026-10-02'));
        $publicacao->setTexto($texto);
        $publicacao->setProcesso($processo);

        return $publicacao;
    }

    #[TestDox('Movimentação comum: tipo, data, órgão, processo e o texto oficial sem HTML, escapado; assunto com o número')]
    public function testMovimentacaoComum(): void
    {
        $m = MovimentacaoAoClienteOutput::dePublicacao($this->publicacao(7, '<p>Fica a parte <b>intimada</b> &amp; ciente. <script>x()</script></p>'));

        self::assertSame('Fica a parte intimada & ciente.', $m->texto);
        self::assertFalse($m->cortado);
        self::assertSame('intimação de 02/10', $m->descricao());

        $html = TextoDoEnvioAutomatico::html('MARIA DA SILVA', TextoDoEnvioAutomatico::introducaoDasNovas(), [$m]);
        self::assertStringContainsString('Olá, Maria.', $html);
        self::assertStringContainsString('Intimação de 02/10/2026', $html);
        self::assertStringContainsString('1ª Vara Cível de Brasília · Processo 0701134-57.2025.8.07.0007', $html);
        self::assertStringContainsString('Fica a parte intimada &amp; ciente.', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(TextoDoEnvioAutomatico::TEXTO_CORTADO, $html);
        self::assertStringNotContainsString('—', $html);
        self::assertSame('Nova movimentação no seu processo 0701134-57.2025.8.07.0007', TextoDoEnvioAutomatico::assuntoDasNovas([$m]));
        self::assertSame('2 novas movimentações no seu processo 0701134-57.2025.8.07.0007', TextoDoEnvioAutomatico::assuntoDasNovas([$m, $m]));
    }

    #[TestDox('Texto acima de 4.000 caracteres: cortado e com "Texto completo disponível com o escritório."')]
    public function testTextoCortado(): void
    {
        $m = MovimentacaoAoClienteOutput::dePublicacao($this->publicacao(8, str_repeat('palavra ', 600) . 'FIM'));

        self::assertTrue($m->cortado);
        self::assertLessThanOrEqual(MovimentacaoAoClienteOutput::LIMITE_DO_TEXTO + 1, mb_strlen((string) $m->texto));
        self::assertStringNotContainsString('FIM', (string) $m->texto);
        self::assertStringContainsString(TextoDoEnvioAutomatico::TEXTO_CORTADO, TextoDoEnvioAutomatico::html('Maria', 'Intro.', [$m]));
    }

    #[TestDox('Segredo de justiça (D4): nem tipo, data, órgão, número ou texto; só a frase, e o assunto sem número')]
    public function testSegredoDeJustica(): void
    {
        $m = MovimentacaoAoClienteOutput::dePublicacao($this->publicacao(9, 'Texto sigiloso do processo.', 1));

        $html = TextoDoEnvioAutomatico::html('Maria', TextoDoEnvioAutomatico::introducaoDasNovas(), [$m]);

        self::assertTrue($m->sigilosa);
        self::assertStringContainsString('Há movimentação nova no seu processo sob segredo de justiça. Fale com o escritório.', $html);
        foreach (['Texto sigiloso', 'Intimação', '02/10', 'Vara', '0701134', TextoDoEnvioAutomatico::introducaoDasNovas()] as $vazamento) {
            self::assertStringNotContainsString($vazamento, $html);
        }
        self::assertSame('Nova movimentação no seu processo', TextoDoEnvioAutomatico::assuntoDasNovas([$m]));
        self::assertSame('Resumo semanal do seu processo', TextoDoEnvioAutomatico::assuntoDoResumo([$m]));
    }

    #[TestDox('Natureza na descrição do histórico: audiência designada, movimentação comum')]
    public function testDescricao(): void
    {
        self::assertSame('audiência de 02/10', MovimentacaoAoClienteOutput::dePublicacao($this->publicacao(10, 'Designo audiência para o dia 5.', 0, 'Edital'))->descricao());
        self::assertSame('movimentação de 02/10', MovimentacaoAoClienteOutput::dePublicacao($this->publicacao(11, 'Junte-se.', 0, 'Edital'))->descricao());
    }
}
