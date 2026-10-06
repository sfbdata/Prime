<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;

/**
 * A lixeira de UMA pasta, na forma que a UI (futura) consome: `GET /pasta/{id}/documentos/lixeira`.
 *
 * Cada item diz o que era (`tipo` pasta|arquivo), como se chamava, quando e por quem foi excluído
 * e o `caminho` original — a trilha de subpastas de onde saiu, como texto ("Raiz" ou
 * "PETIÇÕES / INICIAIS"), montada a partir dos pais ainda existentes (vivos ou também na lixeira:
 * o filtro está desligado quando isto é montado). `paiId`/`secaoId` vão junto para a UI decidir se
 * o restaurar vai devolver ao lugar ou à raiz (pai ainda na lixeira → raiz).
 *
 * Só o que é escalar e necessário; nada de token de ação por item — restaurar usa o token de lote
 * da pasta (`pex_lote_<id>`) com os ids no corpo, como mover/excluir.
 */
final class LixeiraDaPastaOutput
{
    public const RAIZ = 'Raiz';

    /**
     * @param list<array<string, mixed>> $itens
     */
    private function __construct(
        public array $itens,
        public int $totalArquivos,
        public int $totalPastas,
    ) {
    }

    /**
     * @param list<PastaSecao>     $secoes     as seções na lixeira da pasta
     * @param list<PastaDocumento> $documentos os documentos na lixeira da pasta
     */
    public static function montar(array $secoes, array $documentos): self
    {
        $itens = [];

        foreach ($secoes as $secao) {
            $itens[] = [
                'tipo'        => 'pasta',
                'id'          => (int) $secao->getId(),
                'nome'        => $secao->getNome(),
                'excluidoEm'  => self::instante($secao->getExcluidoEm()),
                'excluidoPor' => self::autor($secao->getExcluidoPor()),
                'caminho'     => self::caminho($secao->getPai()),
                'paiId'       => $secao->getPai()?->getId(),
                'paiNaLixeira' => $secao->getPai()?->estaNaLixeira() ?? false,
            ];
        }

        foreach ($documentos as $documento) {
            $itens[] = [
                'tipo'          => 'arquivo',
                'id'            => (int) $documento->getId(),
                'nome'          => $documento->getNomeOriginal(),
                'tamanho'       => $documento->getTamanhoBytes(),
                'mime'          => $documento->getMimeType(),
                'categoria'     => $documento->getCategoria(),
                'excluidoEm'    => self::instante($documento->getExcluidoEm()),
                'excluidoPor'   => self::autor($documento->getExcluidoPor()),
                'caminho'       => self::caminho($documento->getSecao()),
                'secaoId'       => $documento->getSecao()?->getId(),
                'secaoNaLixeira' => $documento->getSecao()?->estaNaLixeira() ?? false,
            ];
        }

        // Do excluído mais recente ao mais antigo, pastas e arquivos juntos; empate pelo nome.
        usort($itens, static fn (array $a, array $b): int => [$b['excluidoEm'], $a['nome']] <=> [$a['excluidoEm'], $b['nome']]);

        return new self($itens, count($documentos), count($secoes));
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'itens'         => $this->itens,
            'totalArquivos' => $this->totalArquivos,
            'totalPastas'   => $this->totalPastas,
        ];
    }

    /** A trilha de onde o item saiu, da raiz até o pai imediato; com a trava anti-ciclo da entidade. */
    private static function caminho(?PastaSecao $pai): string
    {
        $nomes  = [];
        $passos = 0;
        $atual  = $pai;
        while ($atual !== null && $passos < PastaSecao::LIMITE_SEGURANCA) {
            array_unshift($nomes, $atual->getNome());
            $atual = $atual->getPai();
            ++$passos;
        }

        return $nomes === [] ? self::RAIZ : implode(' / ', $nomes);
    }

    private static function instante(?\DateTimeImmutable $em): ?string
    {
        return $em?->format(\DateTimeInterface::ATOM);
    }

    /** @return array{id: int|null, nome: string}|null */
    private static function autor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => $user->getId(), 'nome' => (string) $user->getFullName()];
    }
}
