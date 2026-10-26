<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A aba Pagamento no `trabalho-externo.js` e no `trabalho-externo.css`, lidos como FOLHA (não há navegador no
 * PHPUnit): textos e medidas do desenho 06 de 25/10 (l.620 a 637 e 1361). Aparência e movimento são smoke do dono.
 */
final class AbaPagamentoJsTest extends TestCase
{
    private function js(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/js/trabalho-externo.js');
    }

    private function css(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../public/css/trabalho-externo.css');
    }

    private function aba(): string
    {
        $js = $this->js();
        $inicio = strpos($js, '    function abaPagamento(');
        $fim = strpos($js, '    /* ---------------------------------------------------------------- aba WhatsApp (F2) */');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);

        return substr($js, $inicio, $fim - $inicio);
    }

    #[TestDox('a aba "Pagamento" entra entre Chat e Histórico (l.1361) e tem o despacho próprio')]
    public function testAbaNoLugar(): void
    {
        $js = $this->js();

        self::assertStringContainsString("chat: 'Chat', pag: 'Pagamento', hist: 'Histórico', whats: 'WhatsApp'", $js);
        self::assertStringContainsString('chat: abaChat, pag: abaPagamento, hist: abaHistorico', $js);
    }

    #[TestDox('os textos do desenho (l.620 a 637) estão na aba, literais e sem travessão')]
    public function testTextosDoDesenho(): void
    {
        $aba = $this->aba();

        foreach ([
            'O pagamento fica disponível quando uma proposta for aceita. O valor e o QR Code PIX são gerados automaticamente.',
            'VALOR DA PROPOSTA ACEITA',
            'Favorecido: ',
            'BlueJus Gestão e Tecnologia LTDA',
            'CNPJ 66.803.163/0001-10 · identificação ',
            'Copiar código PIX',
            'Copiado',
            'Enviar no chat',
            'Já paguei · enviar comprovante',
            'Confirmar recebimento',
            ".pdf,image/*",
        ] as $texto) {
            self::assertStringContainsString($texto, $aba, $texto);
        }
        self::assertStringContainsString('2000', $aba, '"Copiado" volta em 2 s');
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $aba, 'sem travessão');
    }

    #[TestDox('a confirmação usa o diálogo da tela (abrirConf), nunca o confirm nativo, e o QR entra como nó lido como XML')]
    public function testSemComponenteDoSistema(): void
    {
        $js = $this->js();
        $aba = $this->aba();

        self::assertStringNotContainsString('confirm(', $aba);
        self::assertStringContainsString('abrirConf(codigo, {', $aba);
        self::assertStringContainsString("k: '__pagamento_pix__'", $aba);
        self::assertStringContainsString("new DOMParser().parseFromString(texto, 'image/svg+xml')", $aba);
        self::assertStringNotContainsString('innerHTML', $aba, 'o SVG não entra como texto de marcação');
        self::assertStringContainsString("trocarAba('chat')", $aba, '"Enviar no chat" abre a aba Chat');
        self::assertStringContainsString('toastDoServidor(r.toast, \'Pagamento confirmado\', \'O solicitante foi avisado no chat e por e-mail.\')', $aba);
        self::assertStringContainsString('urlPagamentoConfirmar', $js);
    }

    #[TestDox('o CSS da aba copia as medidas do desenho: faixa 13.5px bold, QR 170x170 com borda #e1e8ed e padding 8px, valor 24px, botões 32px e 36px')]
    public function testMedidasDoDesenho(): void
    {
        $css = $this->css();

        foreach ([
            '.te-pg-st { display: flex; align-items: center; gap: 9px; padding: 10px 12px; border-radius: 2px; font-size: 13.5px; font-weight: bold; }',
            '.te-pg-qr { width: 170px; height: 170px; flex: none; border: 1px solid #e1e8ed; border-radius: 2px; padding: 8px; box-sizing: border-box; background: #fff; }',
            '.te-pg-rot { font-size: 11px; font-weight: bold; letter-spacing: .6px; color: #4a6373; }',
            '.te-pg-valor { font-size: 24px; color: #12242f; }',
            '.te-pg-forma { font-size: 12.5px; color: #5b7383; }',
            '.te-pg-cod { font: 11px/1.45 monospace; color: #34505f; background: #f5f8fa; border: 1px solid #e1e8ed; border-radius: 2px; padding: 6px 8px; word-break: break-all; max-height: 54px; overflow: auto; user-select: all; }',
            '.te-pg-comp { display: flex; align-items: center; gap: 9px; height: 40px; border: 1px dashed #9fc3d8; border-radius: 2px; padding: 0 12px; background: #f5fafd; color: #0a6fa0;',
            '.te-pg-conf { align-self: flex-start; height: 36px; padding: 0 15px; border: none; border-radius: 2px; background: #1e7b45; color: #fff;',
            '.te-pg-baixar { align-self: flex-start; height: 32px; padding: 0 11px; border: 1px solid #c5d2db; border-radius: 2px; background: #fff; color: #34505f;',
        ] as $regra) {
            self::assertStringContainsString($regra, $css, $regra);
        }
    }
}
