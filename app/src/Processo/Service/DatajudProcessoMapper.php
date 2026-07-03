<?php

namespace App\Processo\Service;

use App\Processo\Entity\AssuntoProcesso;
use App\Processo\Entity\MovimentacaoProcesso;
use App\Processo\Entity\ParteProcesso;
use App\Processo\Entity\Processo;
use App\Processo\Repository\ProcessoRepository;

class DatajudProcessoMapper
{
    public function __construct(
        private readonly ProcessoRepository $processoRepository
    ) {
    }

    public function mapFromSource(Processo $processo, array $source): Processo
    {
        $processo->setNumeroProcesso((string) ($source['numeroProcesso'] ?? $processo->getNumeroProcesso()));

        // Tratamento especial para orgaoJulgador com conversão de encoding
        $orgaoJulgadorRaw = $source['orgaoJulgador']['nome'] ?? $source['orgaoJulgador'] ?? null;
        $processo->setOrgaoJulgador($this->fixOrgaoJulgador($orgaoJulgadorRaw));

        $processo->setSiglaTribunal($this->stringOrDefault($source['tribunal'] ?? null));
        $processo->setClasseProcessual($this->stringOrDefault($source['classe']['nome'] ?? $source['classeProcessual'] ?? null));
        $processo->setAssuntoProcessual($this->stringOrDefault($this->resolveAssunto($source['assuntos'] ?? null)));
        $processo->setSituacaoProcesso($this->stringOrDefault($source['situacaoProcesso'] ?? $source['situacao'] ?? 'EM_ANDAMENTO'));
        $processo->setInstancia($this->stringOrDefault($source['grau'] ?? $source['instancia'] ?? 'G1'));
        $this->mapMetadadosDatajud($processo, $source);
        $this->mapProcessoPai($processo, $source);

        $processo->setDataDistribuicao($this->parseDateOnly($source['dataAjuizamento'] ?? $source['dataDistribuicao'] ?? null));
        $processo->setDataBaixa($this->parseDateOnly($source['dataBaixa'] ?? null));
        $processo->setDataAtualizacao($this->parseDateTime($source['dataHoraUltimaAtualizacao'] ?? $source['dataAtualizacao'] ?? null));

        $this->replacePartes($processo, $source['partes'] ?? []);
        $this->replaceMovimentacoes($processo, $source['movimentos'] ?? $source['movimentacoes'] ?? []);
        $this->replaceAssuntos($processo, $source['assuntos'] ?? []);

        return $processo;
    }

    /**
     * Metadados escalares do Datajud (sigilo, formato, sistema, códigos TPU e id do doc no ES).
     * O `?? null` usa semântica de isset(): chave ausente OU `orgaoJulgador` vindo como string
     * (fallback legado) resolvem para null sem emitir warning.
     */
    private function mapMetadadosDatajud(Processo $processo, array $source): void
    {
        $processo->setNivelSigilo($this->nullableInt($source['nivelSigilo'] ?? null));
        $processo->setFormato($this->nullableString($source['formato']['nome'] ?? null));
        $processo->setFormatoCodigo($this->nullableInt($source['formato']['codigo'] ?? null));
        $processo->setSistema($this->nullableString($source['sistema']['nome'] ?? null));
        $processo->setSistemaCodigo($this->nullableInt($source['sistema']['codigo'] ?? null));
        $processo->setClasseCodigo($this->nullableInt($source['classe']['codigo'] ?? null));
        $processo->setOrgaoJulgadorCodigo($this->nullableString($source['orgaoJulgador']['codigo'] ?? null));
        $processo->setOrgaoJulgadorMunicipioIbge($this->nullableInt($source['orgaoJulgador']['codigoMunicipioIBGE'] ?? null));
        $processo->setDatajudId($this->nullableString($source['id'] ?? null));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $texto = trim((string) $value);

        return $texto === '' ? null : $texto;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function mapProcessoPai(Processo $processo, array $source): void
    {
        $processoPaiNumero = $source['processoPai'] ?? null;

        if (!is_string($processoPaiNumero) || trim($processoPaiNumero) === '') {
            $processo->setProcessoPai(null);
            $processo->setProcessoPaiRef(null);
            return;
        }

        $processoPaiNumero = trim($processoPaiNumero);
        $processo->setProcessoPai($processoPaiNumero);

        if ($processoPaiNumero === $processo->getNumeroProcesso()) {
            $processo->setProcessoPaiRef(null);
            return;
        }

        // Escopar o processo-pai pelo tenant do processo (B1). No CLI (TenantFilter OFF) isso evita
        // linkar a um processo homônimo de outro escritório; no caminho web efêmero (datajudSearch,
        // processo sem tenant) o filtro está ON e já escopa — mesma guarda de partes/movimentações.
        $criteria = ['numeroProcesso' => $processoPaiNumero];
        if ($processo->getTenant() !== null) {
            $criteria['tenant'] = $processo->getTenant();
        }

        $processoPai = $this->processoRepository->findOneBy($criteria);
        $processo->setProcessoPaiRef($processoPai);
    }

    private function replacePartes(Processo $processo, array $partes): void
    {
        foreach ($processo->getPartes() as $parte) {
            $processo->removeParte($parte);
        }

        foreach ($partes as $parteData) {
            if (!is_array($parteData)) {
                continue;
            }

            $parte = new ParteProcesso();
            $parte->setTipo($this->stringOrDefault($parteData['tipo'] ?? $parteData['tipoParte'] ?? 'PARTE'));
            $parte->setNome($this->stringOrDefault($parteData['nome'] ?? 'Desconhecido'));
            $parte->setDocumento($parteData['documento'] ?? $parteData['cpfCnpj'] ?? null);
            $parte->setPapel($parteData['papel'] ?? $parteData['qualificacao'] ?? null);
            // Busca efêmera da API (processo não persistido) não tem tenant; só propaga quando há.
            if ($processo->getTenant() !== null) {
                $parte->setTenant($processo->getTenant());
            }
            $processo->addParte($parte);
        }
    }

    private function replaceMovimentacoes(Processo $processo, array $movimentos): void
    {
        foreach ($processo->getMovimentacoes() as $movimentacao) {
            $processo->removeMovimentacao($movimentacao);
        }

        foreach ($movimentos as $movimento) {
            if (!is_array($movimento)) {
                continue;
            }

            $movimentacao = new MovimentacaoProcesso();
            $movimentacao->setDescricao($this->stringOrDefault($movimento['nome'] ?? 'Movimentacao'));
            $movimentacao->setTipo(isset($movimento['codigo']) ? (string) $movimento['codigo'] : null);
            $orgaoMovimento = $movimento['orgaoJulgador']['nomeOrgao'] ?? $movimento['orgaoJulgador']['nome'] ?? null;
            $movimentacao->setOrgao($this->fixOrgaoJulgador($orgaoMovimento));
            $movimentacao->setDataMovimentacao($this->parseDateOnly($movimento['dataHora'] ?? null));
            if ($processo->getTenant() !== null) {
                $movimentacao->setTenant($processo->getTenant());
            }
            $processo->addMovimentacao($movimentacao);
        }
    }

    /**
     * Substitui a coleção de assuntos pela lista completa do Datajud (cada item {codigo, nome}).
     * O assunto principal continua guardado como string em `assuntoProcessual` (via resolveAssunto).
     *
     * @param mixed $assuntos
     */
    private function replaceAssuntos(Processo $processo, $assuntos): void
    {
        foreach ($processo->getAssuntos() as $assunto) {
            $processo->removeAssunto($assunto);
        }

        if (!is_array($assuntos)) {
            return;
        }

        foreach ($assuntos as $assuntoData) {
            // Mesmo caso defensivo de resolveAssunto: a API às vezes aninha como array de arrays.
            if (is_array($assuntoData) && isset($assuntoData[0]) && is_array($assuntoData[0])) {
                $assuntoData = $assuntoData[0];
            }

            if (!is_array($assuntoData)) {
                continue;
            }

            $nome = $this->stringOrDefault($assuntoData['nome'] ?? null, '');
            if ($nome === '') {
                continue;
            }

            $assunto = new AssuntoProcesso();
            $assunto->setNome($nome);
            $assunto->setCodigo($this->nullableInt($assuntoData['codigo'] ?? null));
            // Busca efêmera (processo sem tenant) não propaga; só quando há tenant (CLI/persistência).
            if ($processo->getTenant() !== null) {
                $assunto->setTenant($processo->getTenant());
            }
            $processo->addAssunto($assunto);
        }
    }

    private function resolveAssunto($assuntos): ?string
    {
        if (!is_array($assuntos) || $assuntos === []) {
            return null;
        }

        $first = $assuntos[0];
        if (is_array($first) && isset($first[0]) && is_array($first[0])) {
            return $first[0]['nome'] ?? null;
        }

        if (is_array($first)) {
            return $first['nome'] ?? null;
        }

        return null;
    }

    private function parseDateOnly(?string $value): ?\DateTimeInterface
    {
        if (!$value) {
            return null;
        }

        try {
            // Colunas dataDistribuicao/dataBaixa/dataMovimentacao são type:'date' (Doctrine DateType),
            // que só aceita \DateTime mutável no flush — DateTimeImmutable estoura InvalidType.
            // Igual ao parseDateOrNull do controller, que já escreve nessas mesmas colunas.
            return new \DateTime($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseDateTime(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function stringOrDefault(?string $value, string $default = 'N/A'): string
    {
        $value = $value ?? '';

        // Corrigir problemas de encoding específicos da API DataJud
        // Alguns acentos vêm como "?" - tentamos recuperar usando iconv
        if (strpos($value, '?') !== false) {
            // Se tem "?", tenta converter assumindo ISO-8859-1 malformado
            $converted = @iconv('CP1252', 'UTF-8', $value);
            if ($converted !== false && $converted !== $value) {
                $value = $converted;
            }

            // Se ainda tem "?", tenta outro encoding
            if (strpos($value, '?') !== false) {
                $converted = @iconv('ISO-8859-1', 'UTF-8', $value);
                if ($converted !== false && $converted !== $value) {
                    $value = $converted;
                }
            }
        }

        return trim($value) === '' ? $default : $value;
    }

    /**
     * Método especial para corrigir encoding do órgão julgador
     * Aplica conversão mais agressiva de ISO-8859-1 para UTF-8
     */
    private function fixOrgaoJulgador(?string $value): string
    {
        if (!$value) {
            return 'N/A';
        }

        // Tenta conversão direta de ISO-8859-1 para UTF-8
        // A maioria dos problemas de órgaoJulgador vêm disso
        $converted = @iconv('ISO-8859-1', 'UTF-8//TRANSLIT', $value);
        if ($converted !== false) {
            return trim($converted);
        }

        // Se falhar, tenta CP1252
        $converted = @iconv('CP1252', 'UTF-8//TRANSLIT', $value);
        if ($converted !== false) {
            return trim($converted);
        }

        // Se tudo falhar, retorna como está
        return trim($value);
    }
}
