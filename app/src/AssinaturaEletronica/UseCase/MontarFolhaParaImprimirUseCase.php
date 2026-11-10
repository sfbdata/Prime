<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\FolhaParaImprimir;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\Entity\Tenant\Tenant;

/**
 * Função PROVISÓRIA "Baixar para imprimir": monta o conteúdo do PDF com o texto inteiro do documento e o lugar de cada
 * signatário assinar à mão (o bloco de assinatura do texto, ou uma lista ao fim para quem não tem bloco). O HTML e o
 * PDF saem no controller; aqui só os dados, sem tocar no banco além da leitura do documento do escritório.
 *
 * Limite conhecido: a página do PDF enviado (bloco `pg`) não é redesenhada aqui; a folha avisa para imprimir o arquivo
 * original.
 */
final readonly class MontarFolhaParaImprimirUseCase
{
    private const TEXTO = ['t', 's', 'h', 'l', 'p', 'd', 'n'];

    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
    ) {
    }

    /** @throws DocumentoNaoEncontrado */
    public function executar(Tenant $tenant, int $id): FolhaParaImprimir
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();
        $signatarios = $this->signatariosDe($documento);

        $usados = [];
        $itens = [];
        foreach ($documento->getBlocos() as $bloco) {
            $item = $this->item($bloco, $signatarios, $usados);
            if ($item !== null) {
                $itens[] = $item;
            }
        }
        $sobraram = array_values(array_diff_key($signatarios, $usados));

        return new FolhaParaImprimir(
            $documento->getNome(),
            self::html(trim((string) $documento->getLateral())),
            $itens,
            $sobraram,
            $signatarios === [] && $usados === [],
        );
    }

    /** @return array<int, array{nome: string, papel: string}> por posição do signatário no documento */
    private function signatariosDe(DocumentoParaAssinar $documento): array
    {
        $lista = [];
        foreach ($documento->getSignatarios() as $posicao => $s) {
            $lista[$posicao] = ['nome' => $s->getNome(), 'papel' => $s->getPapel()];
        }

        return $lista;
    }

    /**
     * @param array<string, mixed>                           $bloco
     * @param array<int, array{nome: string, papel: string}> $signatarios
     * @param array<int, true>                               $usados      os signatários que já têm lugar no texto
     *
     * @return array{tipo: string, html: string, nome: string, papel: string, pagina: int}|null
     */
    private function item(array $bloco, array $signatarios, array &$usados): ?array
    {
        $tipo = (string) ($bloco['k'] ?? '');
        $vazio = ['tipo' => $tipo, 'html' => '', 'nome' => '', 'papel' => '', 'pagina' => 0];

        if (\in_array($tipo, self::TEXTO, true)) {
            return ['html' => self::html((string) ($bloco['x'] ?? ''))] + $vazio;
        }
        if ($tipo === 'pg') {
            return ['pagina' => (int) ($bloco['n'] ?? 0)] + $vazio;
        }
        if ($tipo !== 'a') {
            return null;
        }

        $indice = (int) ($bloco['i'] ?? -1);
        $usados[$indice] = true;
        $papel = trim((string) ($bloco['r'] ?? ''));

        return [
            'nome' => $signatarios[$indice]['nome'] ?? '',
            'papel' => $papel !== '' ? $papel : ($signatarios[$indice]['papel'] ?? ''),
        ] + $vazio;
    }

    /** Escapa o texto do usuário e deixa só o realce {b}...{/b}, sem os marcadores amarelos do assistente. */
    private static function html(string $texto): string
    {
        $seguro = htmlspecialchars(self::limpar($texto), \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return nl2br(str_replace(['{b}', '{/b}'], ['<b>', '</b>'], $seguro));
    }

    private static function limpar(string $texto): string
    {
        return str_replace(["\u{1}", "\u{2}"], '', $texto);
    }
}
