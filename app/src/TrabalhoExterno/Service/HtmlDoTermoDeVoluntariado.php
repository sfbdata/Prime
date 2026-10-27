<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\DadosDoTermo;
use App\TrabalhoExterno\Entity\TermoDeVoluntariado;

/**
 * O HTML do Termo de Adesão ao Serviço Voluntário (`Component.termoHTML`, desenho 06 de 25/10, l.943 a 970).
 * Montado SÓ no servidor, a partir do snapshot, e usado pela prévia (iframe), pelo Word e pela impressão: os três
 * recebem os mesmos bytes. O texto jurídico é o do desenho, sem uma palavra alterada (o rodapé do modelo manda
 * revisar com o responsável jurídico). Tudo que é dado vai escapado.
 *
 * Em branco vira linha para preencher (`L`). Aceito: o bloco "Aceite eletrônico" leva a data, o código e a versão.
 */
final class HtmlDoTermoDeVoluntariado
{
    public const FUSO = 'America/Sao_Paulo';

    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const UNIDADES = ['zero', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove', 'dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    private const DEZENAS = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];

    private const ESTILO = '@page{size:A4;margin:22mm 20mm}body{font-family:Arial,Helvetica,sans-serif;font-size:11.5pt;line-height:1.55;color:#111;margin:0;padding:28px 34px;text-align:justify}h1{font-size:14pt;text-align:center;margin:0 0 4px;letter-spacing:.5px}h2{font-size:10pt;text-align:center;font-weight:normal;color:#444;margin:0 0 22px}h3{font-size:11pt;margin:18px 0 6px}p{margin:0 0 8px}.ass{margin-top:46px;display:flex;gap:40px;justify-content:space-between}.ass div{flex:1;text-align:center;font-size:10pt}.ass hr{border:none;border-top:1px solid #222;margin:0 0 6px}.ace{margin-top:26px;padding:10px 12px;border:1px solid #9bb;font-size:9.5pt;background:#f6fafb;text-align:left}.rod{margin-top:30px;font-size:8.5pt;color:#666;text-align:center}@media print{body{padding:0}}';

    /** "Aceito em" como o desenho mostra (`toLocaleString('pt-BR')`): 27/10/2026 14:03:22, no horário de Brasília. */
    public static function dataHora(\DateTimeImmutable $quando): string
    {
        return $quando->setTimezone(new \DateTimeZone(self::FUSO))->format('d/m/Y H:i:s');
    }

    /** O termo gravado: o aceite, quando houver, entra com a data e o código. */
    public function doTermo(TermoDeVoluntariado $termo): string
    {
        $aceito = $termo->getAceitoEm();

        return $this->montar($termo->getCampos(), $aceito !== null ? self::dataHora($aceito) : '', $termo->getCodigo());
    }

    /**
     * @param array<string, string> $t      o snapshot (as chaves de {@see DadosDoTermo::CAMPOS})
     * @param string                $aceito "27/10/2026 14:03:22" ou vazio
     */
    public function montar(array $t, string $aceito = '', string $codigo = ''): string
    {
        $t += array_fill_keys(array_keys(DadosDoTermo::CAMPOS), '');
        $fim = $this->fim($t['inicio'], $t['meses']);

        return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Termo de Adesão ao Serviço Voluntário</title><style>' . self::ESTILO . '</style></head><body>'
            . '<h1>TERMO DE ADESÃO AO SERVIÇO VOLUNTÁRIO</h1><h2>Lei nº 9.608, de 18 de fevereiro de 1998</h2>'
            . '<p>Pelo presente instrumento particular, as partes abaixo qualificadas:</p>'
            . $this->qualificacaoDoEscritorio($t) . $this->qualificacaoDoVoluntario($t)
            . '<p>têm entre si justo e acordado o presente Termo de Adesão ao Serviço Voluntário, que se regerá pelas cláusulas a seguir.</p>'
            . $this->clausulas($t, $fim)
            . $this->fecho($t, $aceito, $codigo);
    }

    /** @param array<string, string> $t */
    private function qualificacaoDoEscritorio(array $t): string
    {
        $oab = $t['respOab'] !== '' ? ', OAB/' . self::e($t['respOabUf']) . ' ' . self::e($t['respOab']) : '';

        return '<p>' . self::l($t['eNome'], 260) . ', inscrito(a) no CNPJ/CPF sob o nº ' . self::l($t['eDoc']) . ', com sede em ' . self::l($t['eEnd'], 260)
            . ', e-mail ' . self::l($t['eEmail']) . ', telefone ' . self::l($t['eTel']) . ', neste ato representado(a) por ' . self::l($t['resp'], 200) . $oab
            . ', doravante denominado(a) <b>ESCRITÓRIO</b>; e</p>';
    }

    /** @param array<string, string> $t */
    private function qualificacaoDoVoluntario(array $t): string
    {
        $oab = $t['vOab'] !== ''
            ? ', inscrito(a) na OAB/' . self::e($t['vOabUf']) . ($t['vSit'] === 'estOab' ? ' como estagiário(a)' : '') . ' sob o nº ' . self::e($t['vOab'])
            : '';
        $curso = $t['vInst'] !== ''
            ? ', ' . ($t['vSit'] === 'bach' ? 'bacharel(a) em Direito pela ' : 'estudante de Direito da ') . self::e($t['vInst']) . ($t['vSem'] !== '' ? ' (' . self::e($t['vSem']) . ')' : '')
            : '';

        return '<p>' . self::l($t['vNome'], 240) . ', ' . self::l($t['vNac'] !== '' ? $t['vNac'] : 'brasileiro(a)', 80) . ', portador(a) do RG nº ' . self::l($t['vRg'])
            . ', inscrito(a) no CPF sob o nº ' . self::l($t['vCpf']) . $oab . $curso . ', residente e domiciliado(a) em ' . self::l($t['vEnd'], 260)
            . ', e-mail ' . self::l($t['vEmail']) . ', doravante denominado(a) <b>VOLUNTÁRIO(A)</b>;</p>';
    }

    /** @param array<string, string> $t */
    private function clausulas(array $t, string $fim): string
    {
        $horas = $t['horas'] !== '' ? $t['horas'] . ' horas' : '';
        $meses = $t['meses'] !== '' ? $t['meses'] . ' (' . self::extenso($t['meses']) . ') meses' : '';
        $aviso = $t['aviso'] !== '' ? $t['aviso'] . ' (' . self::extenso($t['aviso']) . ') dias' : '';

        return self::clausula(1, 'OBJETO', [
            '1.1. O presente termo tem por objeto a prestação de serviço voluntário pelo(a) VOLUNTÁRIO(A) junto ao ESCRITÓRIO, de forma espontânea e não remunerada, com finalidade de aprendizado, aperfeiçoamento técnico e prático e colaboração em atividades de interesse social e jurídico.',
            '1.2. As atividades a serem desenvolvidas compreendem: ' . self::l($t['ativ'], 300) . '.',
            '1.3. As atividades serão exercidas sob orientação de ' . self::l($t['orient'] !== '' ? $t['orient'] : $t['resp'], 200) . ', observados o Estatuto da Advocacia (Lei nº 8.906/1994) e o Código de Ética e Disciplina da OAB, sendo vedada a prática de atos privativos de advocacia por quem não estiver regularmente inscrito na OAB.',
        ])
            . self::clausula(2, 'NATUREZA DA RELAÇÃO', [
                '2.1. O serviço voluntário não gera vínculo empregatício, nem obrigação de natureza trabalhista, previdenciária ou afim, nos termos do art. 1º, parágrafo único, da Lei nº 9.608/1998.',
                '2.2. O(A) VOLUNTÁRIO(A) não está sujeito(a) a controle de jornada, subordinação ou cumprimento de metas de produção, organizando sua participação em comum acordo com o ESCRITÓRIO.',
                '2.3. A dedicação estimada é de até ' . self::l($horas, 60) . ' semanais, em ' . self::l($t['modal'], 90) . ', podendo ser ajustada entre as partes.',
            ])
            . self::clausula(3, 'PRAZO', [
                '3.1. O presente termo vigorará pelo prazo de ' . self::l($meses, 110) . ', com início em ' . self::data($t['inicio']) . ' e término em ' . self::data($fim) . ', podendo ser renovado mediante novo termo ou aditivo.',
                '3.2. Qualquer das partes poderá encerrar a participação a qualquer tempo, mediante comunicação prévia de ' . self::l($aviso, 70) . ', sem que isso gere direito a indenização.',
            ])
            . self::clausula(4, 'GRATUIDADE E RESSARCIMENTO', [
                '4.1. O serviço é prestado a título gratuito, não cabendo ao(à) VOLUNTÁRIO(A) qualquer remuneração, 13º salário, férias, FGTS, contribuições previdenciárias ou outro benefício de natureza trabalhista.',
                $this->ressarcimento($t),
            ])
            . $this->obrigacoes() . $this->disposicoesFinais($t);
    }

    /** A cláusula 4.2 conforme o ressarcimento escolhido. @param array<string, string> $t */
    private function ressarcimento(array $t): string
    {
        if ($t['ress'] !== 'sim') {
            return '4.2. Não haverá ressarcimento de despesas, salvo ajuste prévio e por escrito entre as partes, nos termos do art. 3º da Lei nº 9.608/1998.';
        }

        return '4.2. O ESCRITÓRIO poderá ressarcir o(a) VOLUNTÁRIO(A) das despesas que comprovadamente realizar no desempenho das atividades voluntárias, desde que expressamente autorizadas'
            . ($t['ressLim'] !== '' ? ', até o limite mensal de R$ ' . self::e($t['ressLim']) : '')
            . ', nos termos do art. 3º da Lei nº 9.608/1998, mediante apresentação de comprovantes. O ressarcimento não tem natureza remuneratória.';
    }

    private function obrigacoes(): string
    {
        return self::clausula(5, 'OBRIGAÇÕES DO(A) VOLUNTÁRIO(A)', [
            '5.1. Manter sigilo absoluto sobre informações, documentos, dados de clientes e processos a que tiver acesso, inclusive após o término deste termo, sob pena de responsabilidade civil e criminal.',
            '5.2. Tratar os dados pessoais acessados exclusivamente para as finalidades das atividades, observando a Lei Geral de Proteção de Dados (Lei nº 13.709/2018) e as orientações do ESCRITÓRIO.',
            '5.3. Exercer as atividades com zelo, ética e responsabilidade, e comunicar previamente ausências ou qualquer situação que impeça a continuidade.',
            '5.4. Utilizar os sistemas, materiais e acessos fornecidos somente para as atividades voluntárias, devolvendo-os ou tendo os acessos encerrados ao fim da participação.',
        ])
            . self::clausula(6, 'OBRIGAÇÕES DO ESCRITÓRIO', [
                '6.1. Oferecer orientação, supervisão e acesso aos meios necessários ao aprendizado e ao desenvolvimento das atividades.',
                '6.2. Garantir ambiente adequado, ético e seguro, e fornecer, ao final, declaração das atividades desenvolvidas e do período de participação, quando solicitada.',
            ]);
    }

    /** @param array<string, string> $t */
    private function disposicoesFinais(array $t): string
    {
        return self::clausula(7, 'DISPOSIÇÕES FINAIS', [
            '7.1. As partes declaram que este termo expressa fielmente sua vontade e que a adesão do(a) VOLUNTÁRIO(A) é livre e espontânea.',
            '7.2. Fica eleito o foro da Comarca de ' . self::l($t['foro'], 140) . ' para dirimir eventuais dúvidas oriundas deste termo, com renúncia a qualquer outro.',
        ]);
    }

    /** @param array<string, string> $t */
    private function fecho(array $t, string $aceito, string $codigo): string
    {
        $assinatura = $aceito !== '' ? ' por meio de aceite eletrônico registrado no BlueJus' : ' em duas vias de igual teor';
        $bloco = $aceito !== ''
            ? '<div class="ace"><b>Aceite eletrônico</b><br>' . self::e($t['vNome']) . ' declarou ter lido e aceitado este termo em ' . self::e($aceito) . '. Código do termo: ' . self::e($codigo) . ' · versão ' . TermoDeVoluntariado::VERSAO_DO_MODELO . '.</div>'
            : '';

        return '<p style="margin-top:20px;">E, por estarem de acordo, firmam o presente termo' . $assinatura . '.</p><p style="text-align:right;">' . self::l($t['cidade'], 120) . ', ' . $this->dataPorExtenso($t['data']) . '.</p>'
            . '<div class="ass"><div><hr>' . self::e($t['eNome'] !== '' ? $t['eNome'] : 'ESCRITÓRIO') . '<br>' . self::e($t['resp']) . '</div><div><hr>' . self::e($t['vNome'] !== '' ? $t['vNome'] : 'VOLUNTÁRIO(A)') . '<br>CPF: ' . self::e($t['vCpf']) . '</div></div>'
            . $bloco
            . '<div class="rod">Documento gerado pelo BlueJus · modelo padrão de Termo de Adesão ao Serviço Voluntário · revise com o responsável jurídico do escritório antes de assinar.</div></body></html>';
    }

    /** Término: o início mais os meses (`setMonth` do desenho), em AAAA-MM-DD; vazio sem um dos dois. */
    private function fim(string $inicio, string $meses): string
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $inicio);
        if ($dia === false || $meses === '') {
            return '';
        }

        return $dia->modify('+' . (int) $meses . ' months')->format('Y-m-d');
    }

    /** "27 de outubro de 2026" (sem zero à esquerda); sem data no snapshot, hoje em Brasília. */
    private function dataPorExtenso(string $data): string
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $data) ?: new \DateTimeImmutable('now', new \DateTimeZone(self::FUSO));

        return (int) $dia->format('j') . ' de ' . self::MESES[(int) $dia->format('n') - 1] . ' de ' . $dia->format('Y');
    }

    /** `dt` do desenho: dd/mm/aaaa, ou a linha para preencher sem data. */
    private static function data(string $iso): string
    {
        $dia = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        return $dia === false ? self::l('', 90) : $dia->format('d/m/Y');
    }

    /** `Component.extenso`: por extenso até 99; de 100 em diante, o próprio número. */
    public static function extenso(string|int $valor): string
    {
        $n = (int) round((float) $valor);
        if ($n < 20) {
            return self::UNIDADES[max(0, $n)];
        }
        if ($n < 100) {
            return self::DEZENAS[intdiv($n, 10)] . ($n % 10 !== 0 ? ' e ' . self::UNIDADES[$n % 10] : '');
        }

        return (string) $n;
    }

    /** `e` do desenho: escapa o dado. */
    private static function e(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** `L` do desenho: o dado em negrito, ou a linha para preencher quando em branco. */
    private static function l(string $texto, int $largura = 120): string
    {
        return $texto !== '' ? '<b>' . self::e($texto) . '</b>' : '<span style="display:inline-block; min-width:' . $largura . 'px; border-bottom:1px solid #444;">&nbsp;</span>';
    }

    /** @param list<string> $itens */
    private static function clausula(int $n, string $titulo, array $itens): string
    {
        return '<h3>' . $n . '. ' . $titulo . '</h3>' . implode('', array_map(static fn (string $p): string => '<p>' . $p . '</p>', $itens));
    }
}
