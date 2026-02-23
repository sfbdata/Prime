<?php

namespace App\Service;

use App\Entity\Processo\MovimentacaoProcesso;
use App\Entity\Processo\ParteProcesso;
use App\Entity\Processo\Processo;
use App\Repository\ProcessoRepository;

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
        $this->mapProcessoPai($processo, $source);

        $processo->setDataDistribuicao($this->parseDateOnly($source['dataAjuizamento'] ?? $source['dataDistribuicao'] ?? null));
        $processo->setDataBaixa($this->parseDateOnly($source['dataBaixa'] ?? null));
        $processo->setDataAtualizacao($this->parseDateTime($source['dataHoraUltimaAtualizacao'] ?? $source['dataAtualizacao'] ?? null));

        $this->replacePartes($processo, $source['partes'] ?? []);
        $this->replaceMovimentacoes($processo, $source['movimentos'] ?? $source['movimentacoes'] ?? []);

        return $processo;
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

        $processoPai = $this->processoRepository->findOneBy(['numeroProcesso' => $processoPaiNumero]);
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
            $processo->addMovimentacao($movimentacao);
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
            return new \DateTimeImmutable($value);
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
