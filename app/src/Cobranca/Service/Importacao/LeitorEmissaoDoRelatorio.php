<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Lê a linha `Emissão: dd/mm/aaaa hh:mm` do rodapé de qualquer relatório da contábil.
 *
 * 🔑 É esta data — e não a data em que a importação rodou — que responde "até quando os dados estão
 * em dia". Importar hoje um relatório emitido há três dias deixa a carteira com dados de três dias
 * atrás; anunciar "atualizado hoje" seria mentir na única pergunta que a tela existe para responder.
 *
 * Os quatro relatórios têm o mesmo rodapé (Filtros / endereço da contábil / Emissão), então um leitor
 * só serve para todos — mesma razão pela qual `ValidadorRodapeFiltros` é único.
 *
 * Lê só a coluna A e varre de BAIXO para cima: o rodapé mora no fim, e subir do começo instanciaria
 * dezenas de milhares de células numa Receitas completa antes de chegar nele.
 */
final class LeitorEmissaoDoRelatorio
{
    private const PREFIXO = 'Emissão:';
    private const COLUNA_RODAPE = 'A';

    /** Quantas linhas do fim vale procurar: o rodapé tem 3 linhas; 30 cobre variação com folga. */
    private const LINHAS_DE_RODAPE = 30;

    public function ler(string $caminhoArquivo): ?\DateTimeImmutable
    {
        if (!is_readable($caminhoArquivo)) {
            return null;
        }

        try {
            $linha = $this->extrairLinha($caminhoArquivo);
        } catch (\Throwable) {
            // Ler a data de emissão NUNCA pode derrubar uma importação: é informação de tela, não de
            // dinheiro. Arquivo ilegível aqui só significa carteira sem data, não importação perdida.
            return null;
        }

        return $linha === null ? null : $this->interpretar($linha);
    }

    /** `Emissão: 06/08/2026 15:22` → data. Aceita com e sem hora; formato inválido devolve null. */
    private function interpretar(string $linha): ?\DateTimeImmutable
    {
        $conteudo = trim(mb_substr(trim($linha), mb_strlen(self::PREFIXO)));

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})(?:\s+(\d{2}):(\d{2}))?/', $conteudo, $m) !== 1) {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat(
            'd/m/Y H:i:s',
            sprintf('%s/%s/%s %s:%s:00', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00'),
        );

        return $data === false ? null : $data;
    }

    private function extrairLinha(string $caminhoArquivo): ?string
    {
        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class(self::COLUNA_RODAPE) implements IReadFilter {
            public function __construct(private readonly string $coluna)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $columnAddress === $this->coluna;
            }
        });

        $primeiraAba = $reader->listWorksheetNames($caminhoArquivo)[0] ?? null;
        if ($primeiraAba === null) {
            return null;
        }
        $reader->setLoadSheetsOnly([$primeiraAba]);

        $aba = $reader->load($caminhoArquivo)->getActiveSheet();
        $ultima = $aba->getHighestDataRow();
        $limite = max(1, $ultima - self::LINHAS_DE_RODAPE);

        for ($i = $ultima; $i >= $limite; $i--) {
            $valor = $aba->getCell(self::COLUNA_RODAPE . $i)->getValue();
            if (is_string($valor) && str_starts_with(trim($valor), self::PREFIXO)) {
                return trim($valor);
            }
        }

        return null;
    }
}
