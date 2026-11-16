<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * O que fazer com as fotos das candidaturas de uma pauta ao salvar (ver `FotosDasCandidaturas`): por número da opção, a
 * foto que fica (`manter`, só nome que a pauta já tinha) e o arquivo novo (`arquivo`); as chaves que deixam de ser
 * referenciadas (`descartadas`, apagadas só depois do COMMIT); e, depois de gravar, as chaves novas.
 */
final class PlanoDeFotosDaPauta
{
    /** @var array<int, string> número da opção => nome gravado agora */
    private array $gravadas = [];

    /** @var list<ChaveDeArquivo> */
    private array $novas = [];

    /**
     * @param array<int, array{manter: ?string, arquivo: ?UploadedFile}> $porNumero
     * @param list<ChaveDeArquivo>                                       $descartadas
     */
    public function __construct(
        public readonly array $porNumero,
        public readonly array $descartadas,
    ) {
    }

    public function registrarGravada(int $numero, ChaveDeArquivo $chave): void
    {
        $this->gravadas[$numero] = $chave->nome;
        $this->novas[] = $chave;
    }

    /** O nome da foto que a opção deste número passa a ter (a nova, a mantida ou nenhuma). */
    public function nomeDe(int $numero): ?string
    {
        return $this->gravadas[$numero] ?? ($this->porNumero[$numero]['manter'] ?? null);
    }

    /** @return list<ChaveDeArquivo> os arquivos gravados por este plano (saem se o COMMIT não aconteceu) */
    public function novas(): array
    {
        return $this->novas;
    }

    public function haArquivoNovo(): bool
    {
        return [] !== $this->novas;
    }
}
