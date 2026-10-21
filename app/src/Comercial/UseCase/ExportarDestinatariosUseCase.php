<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\DTO\DestinatariosEmCsv;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\RelatorioDeCampanhasRepository;
use Psr\Clock\ClockInterface;

/**
 * "Exportar destinatários" do Relatório (desenho 05 VS 21.10.26, L503, L704 e L708; spec `comercial-marcas.md`, regra
 * 6): todos os destinatários da campanha (não só os 200 da tela), em CSV UTF-8 com BOM e `;`. Do visualizador, o
 * `completo` (`E-mail;Enviado;Leitura`); do menu, `emails` (só a coluna `E-mail`). Sem o e-mail anonimizado. A célula
 * que começa com `=`, `+`, `-`, `@`, tab ou CR leva `'` na frente (injeção de fórmula na planilha).
 *
 * Cada exportação vai ao `audit_log` (`camp_csv`, `{linhas, formato}`, o Master como autor), sem os e-mails. Null se
 * a campanha não existe.
 *
 * Leitura: "não rastreada" fixo, porque o rastreio está desligado; troca pelo `RastreioDaLeitura::efetivo()` quando
 * a F3g entrar.
 */
final class ExportarDestinatariosUseCase
{
    public const COMPLETO = 'completo';
    public const EMAILS = 'emails';
    public const AUDITORIA = 'camp_csv';
    public const NAO_RASTREADA = 'não rastreada';
    private const BOM = "\u{FEFF}";
    private const PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"];

    public function __construct(
        private readonly CampanhaDeEmailRepository $campanhas,
        private readonly RelatorioDeCampanhasRepository $relatorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(int $id, string $formato, ?int $masterId, ?string $masterEmail): ?DestinatariosEmCsv
    {
        if ($this->campanhas->porId($id) === null) {
            return null;
        }
        $formato = $formato === self::EMAILS ? self::EMAILS : self::COMPLETO;
        $linhas = array_map(fn (array $d): array => $this->linha($d, $formato), $this->relatorio->destinatariosParaExportar($id));
        $this->campanhas->auditar($id, self::AUDITORIA, ['linhas' => \count($linhas), 'formato' => $formato], $masterId, $masterEmail, $this->relogio->now());
        $cabecalho = $formato === self::EMAILS ? ['E-mail'] : ['E-mail', 'Enviado', 'Leitura'];

        return new DestinatariosEmCsv('destinatarios-' . $id . '.csv', self::BOM . self::csv([$cabecalho, ...$linhas]), \count($linhas));
    }

    /**
     * @param array{email: string, situacao: ?string} $d
     *
     * @return list<string>
     */
    private function linha(array $d, string $formato): array
    {
        if ($formato === self::EMAILS) {
            return [$d['email']];
        }
        $enviado = \in_array($d['situacao'], RelatorioDeCampanhasRepository::SITUACOES_ENVIADAS, true);

        return [$d['email'], $enviado ? 'sim' : 'não', self::NAO_RASTREADA];
    }

    /** @param list<list<string>> $linhas */
    private static function csv(array $linhas): string
    {
        return implode("\n", array_map(static fn (array $l): string => implode(';', array_map(self::celula(...), $l)), $linhas)) . "\n";
    }

    /** A célula segura: `'` antes do que a planilha leria como fórmula; aspas quando tem separador, aspas ou quebra. */
    public static function celula(string $valor): string
    {
        if ($valor !== '' && \in_array($valor[0], self::PERIGOSOS, true)) {
            $valor = "'" . $valor;
        }
        if (strpbrk($valor, ";\"\n\r") !== false) {
            $valor = '"' . str_replace('"', '""', $valor) . '"';
        }

        return $valor;
    }
}
