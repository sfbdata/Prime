<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O alto da folha em PDF (desenho 16 L659 a L666): o período, o empregador, o quadro "REGIME DE JORNADA" e os "DADOS
 * DO EMPREGADO", com os dados das mesmas fontes do PDF de hoje (D6: nada fixo, nenhum campo novo).
 *
 * Dois desvios do PDF de hoje, decididos na E16: CPF e CTPS saem mascarados como no desenho (decisão 8:
 * `***.547.725-**` e `***2329`), e a pessoa sem código de funcionário fica com a célula vazia (decisão 9: o PDF de
 * hoje mostra o próximo código livre do escritório, que não é dela). Os mínimos de intervalo saem no formato do
 * desenho ("1h00 (mínimo)", "mínimo 11h00").
 */
final readonly class CabecalhoDaFolha
{
    /** Texto do PDF de hoje e do desenho (L663). */
    public const BANCO_DE_HORAS = 'Sim (acordo individual)';
    public const TIPO_DE_PONTO = 'Eletrônico';

    public function __construct(
        public string $inicioDoPeriodo,
        public string $fimDoPeriodo,
        public string $empregador,
        public string $cnpj,
        public string $enderecoLinha1,
        public string $enderecoLinha2,
        public string $jornada,
        public string $distribuicao,
        public string $intervaloIntrajornada,
        public string $intervaloInterjornada,
        public string $codigo,
        public string $nome,
        public string $cpf,
        public string $cargo,
        public string $ctps,
        public string $serie,
        public string $lotacao,
        public string $jornadaSemanal,
        public string $horarioContratual,
        public string $intervalo,
        public string $escala,
        public string $admissao,
        public string $responsavel,
    ) {
    }

    /**
     * @param array<string, mixed> $dados              o array de {@see \App\Ponto\Service\DadosDaFolhaExportada::montar()}
     * @param string               $codigo             o código de funcionário do vínculo, ou vazio (decisão 9)
     * @param int                  $minimoIntrajornada minutos
     * @param int                  $minimoInterjornada minutos
     */
    public static function daFolhaExportada(array $dados, string $codigo, int $minimoIntrajornada, int $minimoInterjornada): self
    {
        $texto = static fn (string $chave): string => (string) ($dados[$chave] ?? '');

        return new self(
            $texto('inicioMes'),
            $texto('fimMes'),
            $texto('nomeEmpresa'),
            $texto('cnpj'),
            $texto('enderecoLinha1'),
            $texto('enderecoLinha2'),
            $texto('jornadaSemanalTexto'),
            $texto('distribuicaoJornada'),
            IntervalosDaFolha::duracao($minimoIntrajornada) . ' (mínimo)',
            'mínimo ' . IntervalosDaFolha::duracao($minimoInterjornada),
            $codigo,
            $texto('nomeUsuario'),
            self::cpfMascarado($texto('cpf')),
            $texto('cargo'),
            self::ctpsMascarada($texto('ctps')),
            $texto('serie'),
            $texto('lotacao'),
            $texto('jornadaSemanal'),
            $texto('horarioContratual'),
            $texto('intervaloTexto'),
            $texto('escalaDescricao'),
            $texto('dataAdmissao'),
            $texto('responsavelAssinatura'),
        );
    }

    /**
     * "***.547.725-**" (decisão 8). Um valor fora do formato do CPF não sai na folha: não há como mascará-lo sem
     * mostrar o que a máscara esconde.
     */
    public static function cpfMascarado(string $cpf): string
    {
        $digitos = preg_replace('/\D/', '', $cpf) ?? '';
        if (strlen($digitos) !== 11) {
            return '';
        }

        return sprintf('***.%s.%s-**', substr($digitos, 3, 3), substr($digitos, 6, 3));
    }

    /** "***2329" (decisão 8): só os quatro últimos caracteres à vista. */
    public static function ctpsMascarada(string $ctps): string
    {
        $ctps = trim($ctps);

        return $ctps === '' ? '' : '***' . mb_substr($ctps, -4);
    }
}
